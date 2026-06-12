<?php

declare(strict_types=1);

use App\Enums\MerchantStatus;
use App\Models\MerchantProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds an active merchant profile bound to a user', function () {
    $m = MerchantProfile::factory()->create();

    expect($m->user)->not->toBeNull()
        ->and($m->status)->toBe(MerchantStatus::Active)
        ->and($m->public_id)->not->toBeEmpty();
});

it('can build suspended and banned merchants via states', function () {
    expect(MerchantProfile::factory()->suspended()->create()->status)
        ->toBe(MerchantStatus::Suspended)
        ->and(MerchantProfile::factory()->banned()->create()->status)
        ->toBe(MerchantStatus::Banned);
});
