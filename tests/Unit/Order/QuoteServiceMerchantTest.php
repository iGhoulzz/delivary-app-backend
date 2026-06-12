<?php

declare(strict_types=1);

use App\Enums\ItemSize;
use App\Enums\OrderType;
use App\Services\Order\QuoteService;
use App\Support\QuoteToken;
use App\ValueObjects\MerchantOrderContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->world = TestWorld::create());

it('embeds commission_rate, driver_fee_cut_rate and merchant_profile_id in the token', function () {
    $ctx = new MerchantOrderContext(7, '0.0500', '0.0300', 'Acme', null);

    $res = app(QuoteService::class)->quote(
        OrderType::MerchantDelivery,
        $this->world['pickup']['lat'], $this->world['pickup']['lng'],
        $this->world['dropoff']['lat'], $this->world['dropoff']['lng'],
        ItemSize::Small, '100.00', 'receiver', $ctx,
    );

    $payload = QuoteToken::verify($res['quote_token'])['payload'];

    expect($payload['commission_rate'])->toBe('0.0500')
        ->and($payload['driver_fee_cut_rate'])->toBe('0.0300')
        ->and($payload['merchant_profile_id'])->toBe(7)
        ->and($res['pricing']['commission_amount'])->toBe('5.00');
});

it('signs a null merchant_profile_id for the customer path', function () {
    $res = app(QuoteService::class)->quote(
        OrderType::StandardDelivery,
        $this->world['pickup']['lat'], $this->world['pickup']['lng'],
        $this->world['dropoff']['lat'], $this->world['dropoff']['lng'],
        ItemSize::Small, '0.00', 'sender',
    );

    expect(QuoteToken::verify($res['quote_token'])['payload']['merchant_profile_id'])->toBeNull();
});
