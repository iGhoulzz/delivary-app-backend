<?php

declare(strict_types=1);

use App\Enums\ItemSize;
use App\Enums\OrderType;
use App\Models\MerchantProfile;
use App\Services\Order\CreationService;
use App\Services\Order\QuoteService;
use App\ValueObjects\MerchantOrderContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->world = TestWorld::create();
    $this->merchant = MerchantProfile::factory()->create([
        'business_name' => 'Acme Shop',
        'business_phone' => '+218915550000',
        'commission_rate_override' => '0.0500',
    ]);

    $this->createMerchantOrder = function (string $itemPrice) {
        $ctx = MerchantOrderContext::fromProfile($this->merchant);

        $quote = app(QuoteService::class)->quote(
            OrderType::MerchantDelivery,
            $this->world['pickup']['lat'], $this->world['pickup']['lng'],
            $this->world['dropoff']['lat'], $this->world['dropoff']['lng'],
            ItemSize::Small, $itemPrice, 'receiver', $ctx,
        );

        return app(CreationService::class)->create($this->merchant->user, [
            'quote_token' => $quote['quote_token'],
            'order_type' => OrderType::MerchantDelivery->value,
            'item_price' => $itemPrice,
            'pickup_location' => $this->world['pickup'],
            'pickup_address' => 'Merchant pickup',
            'receiver_location' => $this->world['dropoff'],
            'receiver_address' => 'Customer address',
            'receiver_phone' => '+218910000111',
            'receiver_name' => 'Customer',
            'item_size' => ItemSize::Small->value,
            'item_description' => 'Goods',
        ], null, $ctx);
    };
});

it('writes business identity + merchant_profile_id + snapshots merchant commission', function (): void {
    $order = ($this->createMerchantOrder)('100.00');

    expect($order->order_type)->toBe(OrderType::MerchantDelivery)
        ->and($order->merchant_profile_id)->toBe($this->merchant->id)
        ->and($order->sender_user_id)->toBe($this->merchant->user_id)
        ->and($order->sender_name)->toBe('Acme Shop')
        ->and($order->sender_phone)->toBe('+218915550000')
        ->and($order->delivery_fee_payer->value)->toBe('receiver')
        ->and((string) $order->commission_amount)->toBe('5.00'); // 0.05 × 100
});

it('allows item_price = 0 (pure fulfillment) on a merchant order', function (): void {
    $order = ($this->createMerchantOrder)('0.00');

    expect((string) $order->item_price)->toBe('0.00')
        ->and($order->merchant_profile_id)->toBe($this->merchant->id);
});
