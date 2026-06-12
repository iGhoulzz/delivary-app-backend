<?php

declare(strict_types=1);

use App\Enums\ItemSize;
use App\Enums\OrderType;
use App\Models\PlatformSetting;
use App\Services\Order\PricingService;
use App\ValueObjects\MerchantOrderContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->world = TestWorld::create();
    PlatformSetting::set('pricing.item_commission_rate', '0.1000');
});

it('snapshots merchant commission on a merchant_delivery order (sale-order predicate)', function () {
    $p = app(PricingService::class)->compute(
        OrderType::MerchantDelivery,
        $this->world['pickup']['lat'], $this->world['pickup']['lng'],
        $this->world['dropoff']['lat'], $this->world['dropoff']['lng'],
        ItemSize::Small, '100.00', 'receiver', 'cash',
    );

    // Platform rate 0.10 now applies to merchant_delivery (was forced to 0 before).
    expect($p['commission_amount'])->toBe('10.00');
});

it('applies the merchant override rates when context is given', function () {
    $ctx = new MerchantOrderContext(1, '0.0500', '0.0300', 'Acme', null);

    $p = app(PricingService::class)->compute(
        OrderType::MerchantDelivery,
        $this->world['pickup']['lat'], $this->world['pickup']['lng'],
        $this->world['dropoff']['lat'], $this->world['dropoff']['lng'],
        ItemSize::Small, '100.00', 'receiver', 'cash', $ctx,
    );

    expect($p['commission_rate'])->toBe('0.0500')
        ->and($p['commission_amount'])->toBe('5.00')
        ->and($p['driver_fee_cut_rate'])->toBe('0.0300');
});

it('still computes zero commission for a standard delivery (regression)', function () {
    $p = app(PricingService::class)->compute(
        OrderType::StandardDelivery,
        $this->world['pickup']['lat'], $this->world['pickup']['lng'],
        $this->world['dropoff']['lat'], $this->world['dropoff']['lng'],
        ItemSize::Small, '0.00', 'sender', 'cash',
    );

    expect($p['commission_amount'])->toBe('0.00');
});
