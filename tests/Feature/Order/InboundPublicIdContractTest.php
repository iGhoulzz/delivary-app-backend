<?php

declare(strict_types=1);

use App\Models\DriverProfile;
use App\Models\OfficeLocation;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function makeInboundOffice(): OfficeLocation
{
    return OfficeLocation::create([
        'region_id' => null,
        'name' => 'Office '.uniqid(),
        'address' => 'addr',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);
}

function makeInboundAdmin(): User
{
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    return $admin;
}

it('preregisters a driver by office_public_id', function (): void {
    Role::findOrCreate('driver', 'web');
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $office = makeInboundOffice();

    $response = $this->postJson('/api/me/driver/preregister', [
        'office_public_id' => $office->public_id,
        'vehicle_type' => 'motorcycle',
        'vehicle_plate' => 'AB-123',
    ]);

    expect($response->status())->toBe(201);
    expect(DriverProfile::query()->where('user_id', $user->id)->value('office_id'))->toBe($office->id);
});

it('rejects preregister with a bare internal office id', function (): void {
    Role::findOrCreate('driver', 'web');
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $office = makeInboundOffice();

    $response = $this->postJson('/api/me/driver/preregister', [
        'office_public_id' => (string) $office->id,
        'vehicle_type' => 'motorcycle',
        'vehicle_plate' => 'AB-123',
    ]);

    expect($response->status())->toBe(422);
});

it('admin assigns an order by driver_public_id', function (): void {
    Role::findOrCreate('driver', 'web');
    makeInboundAdmin();
    $driver = makeOnlineDriverAt(32.8872, 13.1913);
    $order = makeOrderAt(32.8872, 13.1913);

    $response = $this->postJson("/api/admin/orders/{$order->public_id}/assign", [
        'driver_public_id' => $driver->public_id,
    ]);

    expect($response->status())->toBe(200);
    expect($order->fresh()->driver_id)->toBe($driver->id);
});

it('rejects admin assign with a bare internal driver id', function (): void {
    Role::findOrCreate('driver', 'web');
    makeInboundAdmin();
    $driver = makeOnlineDriverAt(32.8872, 13.1913);
    $order = makeOrderAt(32.8872, 13.1913);

    $response = $this->postJson("/api/admin/orders/{$order->public_id}/assign", [
        'driver_public_id' => (string) $driver->id,
    ]);

    expect($response->status())->toBe(422);
});

it('rejects admin redirect-return with a bare internal office id', function (): void {
    makeInboundAdmin();
    $office = makeInboundOffice();
    $order = makeOrderAt(32.8872, 13.1913);

    $response = $this->postJson("/api/admin/orders/{$order->public_id}/redirect-return", [
        'office_public_id' => (string) $office->id,
    ]);

    expect($response->status())->toBe(422);
});
