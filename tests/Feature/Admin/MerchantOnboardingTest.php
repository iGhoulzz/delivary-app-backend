<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\MerchantStatus;
use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('merchant', 'web');
});

function actingMerchantAdmin(): User
{
    $admin = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    return $admin;
}

function merchantStorePayload(User $user, array $overrides = []): array
{
    return array_merge([
        'user_public_id' => $user->public_id,
        'business_name' => 'Acme Shop',
        'business_phone' => '+218910000333',
        'commission_rate_override' => '0.0500',
        'driver_fee_cut_override' => '0.0300',
        'default_pickup_address' => 'Tripoli Center',
        'default_pickup_location' => ['lat' => 32.8872, 'lng' => 13.1913],
        'notes' => 'fragile pickup',
    ], $overrides);
}

it('admin creates an active merchant for an existing user', function (): void {
    actingMerchantAdmin();
    $target = User::factory()->create(['account_status' => AccountStatus::Active->value]);

    $response = $this->postJson('/api/admin/merchants', merchantStorePayload($target));

    $response->assertCreated()
        ->assertJsonPath('data.business_name', 'Acme Shop')
        ->assertJsonPath('data.status', MerchantStatus::Active->value)
        ->assertJsonPath('data.owner.id', $target->public_id)
        ->assertJsonPath('data.default_pickup_location.lat', 32.8872)
        ->assertJsonPath('data.default_pickup_location.lng', 13.1913);

    expect($target->fresh()->hasRole('merchant'))->toBeTrue();
});

it('looks up users by phone and anti-enumerates misses', function (): void {
    actingMerchantAdmin();
    $target = User::factory()->create(['phone_number' => '+218910000111']);

    $this->getJson('/api/admin/merchants/lookup?phone=%2B218910000111')
        ->assertOk()
        ->assertJsonPath('data.id', $target->public_id)
        ->assertJsonPath('data.phone', '+218910000111');

    $this->getJson('/api/admin/merchants/lookup?phone=%2B218999999999')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('suspends reactivates and bans a merchant', function (): void {
    actingMerchantAdmin();
    $merchant = MerchantProfile::factory()->create();
    $merchant->user->assignRole('merchant');

    $this->postJson("/api/admin/merchants/{$merchant->public_id}/suspend")
        ->assertOk()
        ->assertJsonPath('data.status', MerchantStatus::Suspended->value);

    expect($merchant->user->fresh()->hasRole('merchant'))->toBeTrue();

    $this->postJson("/api/admin/merchants/{$merchant->public_id}/reactivate")
        ->assertOk()
        ->assertJsonPath('data.status', MerchantStatus::Active->value);

    $this->postJson("/api/admin/merchants/{$merchant->public_id}/ban")
        ->assertOk()
        ->assertJsonPath('data.status', MerchantStatus::Banned->value);

    expect($merchant->user->fresh()->hasRole('merchant'))->toBeFalse();
});

it('returns merchant errors for duplicate banned account and invalid transition guards', function (): void {
    actingMerchantAdmin();
    $merchant = MerchantProfile::factory()->create();

    $this->postJson('/api/admin/merchants', merchantStorePayload($merchant->user))
        ->assertStatus(422)
        ->assertJsonPath('error', 'ALREADY_MERCHANT');

    $bannedUser = User::factory()->create(['account_status' => AccountStatus::Banned->value]);
    $this->postJson('/api/admin/merchants', merchantStorePayload($bannedUser))
        ->assertStatus(422)
        ->assertJsonPath('error', 'ACCOUNT_NOT_ELIGIBLE');

    $this->postJson("/api/admin/merchants/{$merchant->public_id}/reactivate")
        ->assertStatus(422)
        ->assertJsonPath('error', 'INVALID_STATUS_TRANSITION');
});

it('rejects non-admins and invalid override rates', function (): void {
    $target = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/admin/merchants', merchantStorePayload($target))
        ->assertForbidden();

    actingMerchantAdmin();

    $this->postJson('/api/admin/merchants', merchantStorePayload($target, [
        'commission_rate_override' => '1.0001',
    ]))->assertStatus(422);

    $this->postJson('/api/admin/merchants', merchantStorePayload($target, [
        'commission_rate_override' => '-0.0001',
    ]))->assertStatus(422);
});

it('lists shows and updates merchants without exposing internal ids', function (): void {
    actingMerchantAdmin();
    $merchant = MerchantProfile::factory()->create(['business_name' => 'Old Shop']);

    $this->getJson('/api/admin/merchants?status=active')
        ->assertOk()
        ->assertJsonPath('data.0.id', $merchant->public_id);

    $this->patchJson("/api/admin/merchants/{$merchant->public_id}", [
        'business_name' => 'New Shop',
        'commission_rate_override' => null,
    ])->assertOk()
        ->assertJsonPath('data.business_name', 'New Shop')
        ->assertJsonMissingPath('data.user_id');

    $this->getJson("/api/admin/merchants/{$merchant->public_id}")
        ->assertOk()
        ->assertJsonPath('data.id', $merchant->public_id)
        ->assertJsonMissingPath('data.owner.internal_id');
});
