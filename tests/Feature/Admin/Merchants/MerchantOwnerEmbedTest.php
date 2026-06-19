<?php

declare(strict_types=1);

use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['admin', 'merchant'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('shows merchant owner phone account status and roles', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $owner = User::factory()->create(['phone_number' => '+218910000222']);
    $owner->assignRole('merchant');
    $merchant = MerchantProfile::factory()->create(['user_id' => $owner->id]);

    $this->getJson("/api/admin/merchants/{$merchant->public_id}")
        ->assertOk()
        ->assertJsonPath('data.owner.id', $owner->public_id)
        ->assertJsonPath('data.owner.phone', '+218910000222')
        ->assertJsonPath('data.owner.account_status', $owner->account_status->value)
        ->assertJsonPath('data.owner.roles.0', 'merchant');
});
