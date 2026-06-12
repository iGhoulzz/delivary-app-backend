<?php

declare(strict_types=1);

use App\Models\MerchantProfile;
use App\ValueObjects\MerchantOrderContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('derives context fields from a merchant profile', function () {
    $m = MerchantProfile::factory()->create([
        'business_name' => 'Acme',
        'business_phone' => '+218910000000',
        'commission_rate_override' => '0.0500',
        'driver_fee_cut_override' => '0.0300',
    ]);

    $ctx = MerchantOrderContext::fromProfile($m);

    expect($ctx->merchantProfileId)->toBe($m->id)
        ->and($ctx->businessName)->toBe('Acme')
        ->and($ctx->contactPhone)->toBe('+218910000000')
        ->and($ctx->commissionRateOverride)->toBe('0.0500')
        ->and($ctx->driverFeeCutOverride)->toBe('0.0300');
});

it('falls back to the owner phone and null overrides when unset', function () {
    $m = MerchantProfile::factory()->create([
        'business_phone' => null,
        'commission_rate_override' => null,
        'driver_fee_cut_override' => null,
    ]);

    $ctx = MerchantOrderContext::fromProfile($m);

    expect($ctx->contactPhone)->toBe($m->user->phone_number)
        ->and($ctx->commissionRateOverride)->toBeNull()
        ->and($ctx->driverFeeCutOverride)->toBeNull();
});
