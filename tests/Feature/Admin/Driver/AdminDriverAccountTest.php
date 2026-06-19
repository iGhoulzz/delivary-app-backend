<?php

declare(strict_types=1);

use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('driver', 'web');
});

it('returns a driver account with buckets and recent transactions for an admin', function (): void {
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $driver = User::factory()->create();
    $driver->assignRole('driver');
    DriverProfile::factory()->create(['user_id' => $driver->id]);
    DriverAccount::factory()->create([
        'driver_id' => $driver->id,
        'cash_to_deposit' => '120.00',
        'max_cash_liability' => '200.00',
    ]);

    $response = $this->getJson("/api/admin/drivers/{$driver->public_id}/account");

    expect($response->status())->toBe(200);
    $response->assertJsonStructure([
        'account' => ['cash_to_deposit', 'earnings_balance', 'debt_balance', 'max_cash_liability', 'net_position'],
        'transactions',
    ]);
    expect($response->json('account.cash_to_deposit'))->toBe('120.00');
});

it('404s when the user has no driver account', function (): void {
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $user = User::factory()->create();

    $this->getJson("/api/admin/drivers/{$user->public_id}/account")->assertStatus(404);
});

it('forbids non-admins', function (): void {
    $driver = User::factory()->create();
    $driver->assignRole('driver');
    DriverProfile::factory()->create(['user_id' => $driver->id]);
    DriverAccount::factory()->create(['driver_id' => $driver->id]);

    Sanctum::actingAs(User::factory()->create(['must_change_password' => false]));

    $this->getJson("/api/admin/drivers/{$driver->public_id}/account")->assertForbidden();
});
