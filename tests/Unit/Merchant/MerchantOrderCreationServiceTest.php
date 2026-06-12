<?php

declare(strict_types=1);

use App\Enums\ItemSize;
use App\Enums\OrderType;
use App\Exceptions\Merchant\MerchantException;
use App\Models\MerchantProfile;
use App\Services\Merchant\MerchantOrderCreationService;
use App\Services\Order\QuoteService;
use App\ValueObjects\MerchantOrderContext;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->world = TestWorld::create();
    $this->merchant = MerchantProfile::factory()->create([
        'default_pickup_address' => 'Default depot',
        'default_pickup_location' => Point::makeGeodetic(
            $this->world['pickup']['lat'],
            $this->world['pickup']['lng'],
        ),
    ]);
    $this->merchant->user->assignRole('merchant');
    $this->svc = app(MerchantOrderCreationService::class);

    $this->makeInput = function (string $itemPrice, array $extra = []): array {
        $ctx = MerchantOrderContext::fromProfile($this->merchant);
        $quote = app(QuoteService::class)->quote(
            OrderType::MerchantDelivery,
            $this->world['pickup']['lat'], $this->world['pickup']['lng'],
            $this->world['dropoff']['lat'], $this->world['dropoff']['lng'],
            ItemSize::Small, $itemPrice, 'receiver', $ctx,
        );

        return array_merge([
            'quote_token' => $quote['quote_token'],
            'item_price' => $itemPrice,
            'receiver_location' => $this->world['dropoff'],
            'receiver_address' => 'Customer address',
            'receiver_phone' => '+218910000222',
            'receiver_name' => 'Customer',
            'item_size' => ItemSize::Small->value,
            'item_description' => 'Goods',
        ], $extra);
    };
});

it('uses a complete per-order pickup when supplied', function () {
    $out = $this->svc->resolvePickup([
        'pickup_address' => 'Shop front',
        'pickup_location' => ['lat' => 32.8872, 'lng' => 13.1913],
    ], $this->merchant);

    expect($out['pickup_address'])->toBe('Shop front');
});

it('falls back to the profile default pickup when omitted', function () {
    $out = $this->svc->resolvePickup([], $this->merchant);

    expect($out['pickup_address'])->toBe('Default depot')
        ->and(round((float) $out['pickup_location']['lat'], 4))->toBe(round($this->world['pickup']['lat'], 4));
});

it('rejects a partial per-order pickup instead of silently defaulting', function () {
    expect(fn () => $this->svc->resolvePickup(['pickup_address' => 'Only address'], $this->merchant))
        ->toThrow(MerchantException::class);
});

it('throws MissingPickup when neither per-order pickup nor default exists', function () {
    $bare = MerchantProfile::factory()->create();

    expect(fn () => $this->svc->resolvePickup([], $bare))
        ->toThrow(MerchantException::class);
});

it('creates a merchant_delivery order using the default pickup', function () {
    $order = $this->svc->create($this->merchant->user, ($this->makeInput)('50.00'));

    expect($order->order_type)->toBe(OrderType::MerchantDelivery)
        ->and($order->merchant_profile_id)->toBe($this->merchant->id)
        ->and($order->pickup_address)->toBe('Default depot')
        ->and((string) $order->item_price)->toBe('50.00');
});

it('rejects a non-active merchant', function () {
    $suspended = MerchantProfile::factory()->suspended()->create();

    expect(fn () => $this->svc->create($suspended->user, ($this->makeInput)('50.00')))
        ->toThrow(MerchantException::class);
});
