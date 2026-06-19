<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\DriverStatus;
use App\Enums\VehicleType;
use App\Models\DriverProfile;
use App\Models\OfficeLocation;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['admin', 'driver', 'user'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    Sanctum::actingAs($this->admin);
});

function adminDriverOffice(): OfficeLocation
{
    return OfficeLocation::create([
        'region_id' => null,
        'name' => 'Admin Driver Office '.uniqid(),
        'address' => 'Tripoli office',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);
}

function adminDriverPayload(OfficeLocation $office, array $overrides = []): array
{
    return array_merge([
        'mode' => 'new',
        'office_public_id' => $office->public_id,
        'phone_number' => '+218910100001',
        'first_name' => 'New',
        'last_name' => 'Driver',
        'vehicle_type' => VehicleType::Car->value,
        'vehicle_plate' => 'TRP-123',
        'vehicle_color' => 'white',
        'vehicle_model' => 'Corolla',
    ], $overrides);
}

it('looks up a prospective driver by phone', function (): void {
    $user = User::factory()->create(['phone_number' => '+218910200001']);

    $this->postJson('/api/admin/drivers/lookup', ['phone_number' => '+218910200001'])
        ->assertOk()
        ->assertJsonPath('user_exists', true)
        ->assertJsonPath('user_phone_verified', true)
        ->assertJsonPath('driver_profile', null)
        ->assertJsonPath('can_onboard', true);

    expect($user->fresh())->not->toBeNull();
});

it('onboards an existing user into pre registered state without shortcutting approval', function (): void {
    $office = adminDriverOffice();
    $user = User::factory()->create(['phone_number' => '+218910200002']);

    $response = $this->postJson('/api/admin/drivers', adminDriverPayload($office, [
        'mode' => 'existing',
        'user_public_id' => $user->public_id,
        'phone_number' => null,
        'first_name' => null,
    ]));

    $response->assertCreated()
        ->assertJsonPath('driver_profile.id', $user->public_id)
        ->assertJsonPath('driver_profile.status', DriverStatus::PreRegistered->value);

    expect($user->fresh()->driverProfile?->status)->toBe(DriverStatus::PreRegistered);
});

it('creates a new user and pre registered driver profile', function (): void {
    $office = adminDriverOffice();

    $response = $this->postJson('/api/admin/drivers', adminDriverPayload($office, [
        'phone_number' => '+218910200003',
    ]));

    $response->assertCreated()
        ->assertJsonPath('driver_profile.status', DriverStatus::PreRegistered->value)
        ->assertJsonPath('otp_required', true);

    $driver = User::query()->where('phone_number', '+218910200003')->firstOrFail();
    expect($driver->driverProfile?->status)->toBe(DriverStatus::PreRegistered);
});

it('rejects banned users and users already attached as drivers', function (): void {
    $office = adminDriverOffice();
    $banned = User::factory()->create(['account_status' => AccountStatus::Banned->value]);

    $this->postJson('/api/admin/drivers', adminDriverPayload($office, [
        'mode' => 'existing',
        'user_public_id' => $banned->public_id,
        'phone_number' => null,
        'first_name' => null,
    ]))->assertStatus(422)
        ->assertJsonPath('error', 'account_not_eligible');

    $driver = User::factory()->create();
    $driver->assignRole('driver');
    DriverProfile::factory()->create(['user_id' => $driver->id]);

    $this->postJson('/api/admin/drivers', adminDriverPayload($office, [
        'mode' => 'existing',
        'user_public_id' => $driver->public_id,
        'phone_number' => null,
        'first_name' => null,
    ]))->assertStatus(422)
        ->assertJsonPath('error', 'driver_profile_exists');
});
