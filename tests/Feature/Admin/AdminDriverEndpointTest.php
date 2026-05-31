<?php

declare(strict_types=1);

use App\Enums\DriverStatus;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');
    Role::findOrCreate('driver', 'web');
});

function actingAsAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    return $admin;
}

function makeDriverProfile(DriverStatus $status = DriverStatus::Active): DriverProfile
{
    $driver = User::factory()->create();
    $driver->assignRole('driver');

    return DriverProfile::factory()->create([
        'user_id' => $driver->id,
        'status' => $status->value,
    ]);
}

it('shows a driver by user public_id', function (): void {
    actingAsAdmin();
    $profile = makeDriverProfile();

    $response = $this->getJson("/api/admin/drivers/{$profile->user->public_id}");

    expect($response->status())->toBe(200);
    expect($response->json('driver_profile.id'))->toBe($profile->id);
});

it('returns 404 for an unknown driver public_id', function (): void {
    actingAsAdmin();

    $response = $this->getJson('/api/admin/drivers/01HZZZZZZZZZZZZZZZZZZZZZZZ');

    expect($response->status())->toBe(404);
});

it('suspends a driver by user public_id', function (): void {
    actingAsAdmin();
    $profile = makeDriverProfile(DriverStatus::Active);

    $response = $this->postJson("/api/admin/drivers/{$profile->user->public_id}/suspend");

    expect($response->status())->toBe(200);
    expect($response->json('driver_profile.status'))->toBe(DriverStatus::Suspended->value);
    expect($profile->fresh()->status)->toBe(DriverStatus::Suspended);
});
