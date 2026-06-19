<?php

declare(strict_types=1);

use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\DriverStrike;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('driver', 'web');
});

it('voids a strike as a status flip without touching balances', function (): void {
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $driver = User::factory()->create();
    $driver->assignRole('driver');
    DriverProfile::factory()->create(['user_id' => $driver->id]);
    DriverAccount::factory()->create(['driver_id' => $driver->id, 'debt_balance' => '10.00']);

    $strike = DriverStrike::create([
        'driver_id' => $driver->id,
        'reason' => 'no_show_at_pickup',
        'issued_by' => 'system',
        'fee_amount' => 10,
        'is_voided' => false,
    ]);

    $response = $this->postJson("/api/admin/drivers/{$driver->public_id}/strikes/{$strike->public_id}/void", [
        'void_reason' => 'real emergency',
    ]);

    expect($response->status())->toBe(200);
    expect($strike->fresh()->is_voided)->toBeTrue();
    expect($strike->fresh()->voided_by_admin_id)->toBe($admin->id);
    // Critical: voiding never reverses the fee — debt is untouched.
    expect((string) $driver->driverAccount()->first()->debt_balance)->toBe('10.00');
});

it('returns 422 when voiding an already-voided strike', function (): void {
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $driver = User::factory()->create();
    $driver->assignRole('driver');
    DriverProfile::factory()->create(['user_id' => $driver->id]);

    $strike = DriverStrike::create([
        'driver_id' => $driver->id,
        'reason' => 'no_show_at_pickup',
        'issued_by' => 'system',
        'fee_amount' => 0,
        'is_voided' => true,
        'voided_at' => now(),
    ]);

    $this->postJson("/api/admin/drivers/{$driver->public_id}/strikes/{$strike->public_id}/void", [
        'void_reason' => 'again',
    ])->assertStatus(422);
});

it('forbids non-admins', function (): void {
    $driver = User::factory()->create();
    $strike = DriverStrike::create([
        'driver_id' => $driver->id,
        'reason' => 'no_show_at_pickup',
        'issued_by' => 'system',
        'fee_amount' => 0,
        'is_voided' => false,
    ]);

    Sanctum::actingAs(User::factory()->create(['must_change_password' => false]));

    $this->postJson("/api/admin/drivers/{$driver->public_id}/strikes/{$strike->public_id}/void", [
        'void_reason' => 'x',
    ])->assertForbidden();
});
