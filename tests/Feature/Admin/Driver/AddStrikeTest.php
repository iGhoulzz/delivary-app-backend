<?php

declare(strict_types=1);

use App\Models\DriverAccount;
use App\Models\DriverAccountTransaction;
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

it('adds a manual strike with a fee that posts to the ledger', function (): void {
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $driver = User::factory()->create();
    $driver->assignRole('driver');
    DriverProfile::factory()->create(['user_id' => $driver->id]);
    DriverAccount::factory()->create(['driver_id' => $driver->id, 'earnings_balance' => '0.00', 'debt_balance' => '0.00']);

    $response = $this->postJson("/api/admin/drivers/{$driver->public_id}/strikes", [
        'reason' => 'manual_admin',
        'fee' => 5,
    ]);

    expect($response->status())->toBe(201);
    expect(DriverStrike::where('driver_id', $driver->id)
        ->where('issued_by', 'admin')->where('issued_by_admin_id', $admin->id)->exists())->toBeTrue();
    expect(DriverAccountTransaction::where('driver_id', $driver->id)->where('reason', 'strike_fee')->exists())->toBeTrue();
});

it('adds a manual strike with no fee and writes no ledger row', function (): void {
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $driver = User::factory()->create();
    $driver->assignRole('driver');

    $this->postJson("/api/admin/drivers/{$driver->public_id}/strikes", [
        'reason' => 'manual_admin',
        'fee' => 0,
    ])->assertStatus(201);

    expect(DriverAccountTransaction::where('driver_id', $driver->id)->exists())->toBeFalse();
});

it('rejects an invalid strike reason', function (): void {
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $driver = User::factory()->create();

    $this->postJson("/api/admin/drivers/{$driver->public_id}/strikes", [
        'reason' => 'not_a_reason',
    ])->assertStatus(422);
});

it('forbids non-admins', function (): void {
    $driver = User::factory()->create();
    Sanctum::actingAs(User::factory()->create(['must_change_password' => false]));

    $this->postJson("/api/admin/drivers/{$driver->public_id}/strikes", ['reason' => 'manual_admin'])->assertForbidden();
});
