<?php

declare(strict_types=1);

use App\Models\MerchantProfile;
use App\Models\User;
use App\Policies\MerchantProfilePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
});

it('allows admins and denies everyone else', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $plain = User::factory()->create();
    $merchant = MerchantProfile::factory()->make();
    $policy = new MerchantProfilePolicy;

    expect($policy->viewAny($admin))->toBeTrue()
        ->and($policy->viewAny($plain))->toBeFalse()
        ->and($policy->create($admin))->toBeTrue()
        ->and($policy->update($plain, $merchant))->toBeFalse()
        ->and($policy->ban($admin, $merchant))->toBeTrue();
});
