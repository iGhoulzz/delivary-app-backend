<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\DriverStatus;
use App\Models\DriverProfile;
use App\Models\MerchantProfile;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['admin', 'user', 'driver', 'merchant'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('lists users with roles profile links and customer order count', function (): void {
    Sanctum::actingAs($this->admin);

    $customer = User::factory()->create(['first_name' => 'Layla', 'last_name' => 'Customer']);
    $customer->assignRole('user');
    Order::factory()->create(['sender_user_id' => $customer->id]);
    Order::factory()->withReceiverUser($customer)->create();

    $driver = User::factory()->create(['first_name' => 'Dana']);
    $driver->assignRole('driver');
    DriverProfile::factory()->create([
        'user_id' => $driver->id,
        'status' => DriverStatus::Active->value,
    ]);

    $merchantUser = User::factory()->create();
    $merchantUser->assignRole('merchant');
    $merchant = MerchantProfile::factory()->create(['user_id' => $merchantUser->id]);

    $response = $this->getJson('/api/admin/users?search=Layla')->assertOk();
    $row = $response->json('data.0');

    expect($row['id'])->toBe($customer->public_id)
        ->and($row['orders_count'])->toBe(2)
        ->and($row['roles'])->toContain('user')
        ->and($row)->toHaveKeys(['phone_verified', 'email_verified', 'joined']);

    $driverRow = $this->getJson('/api/admin/users?role=driver')->assertOk()->json('data.0');
    expect($driverRow['id'])->toBe($driver->public_id)
        ->and($driverRow['driver_public_id'])->toBe($driver->public_id);

    $merchantRow = $this->getJson('/api/admin/users?role=merchant')->assertOk()->json('data.0');
    expect($merchantRow['id'])->toBe($merchantUser->public_id)
        ->and($merchantRow['merchant_public_id'])->toBe($merchant->public_id);
});

it('filters users by account status and forbids non admins', function (): void {
    $banned = User::factory()->create(['account_status' => AccountStatus::Banned->value]);
    User::factory()->create(['account_status' => AccountStatus::Active->value]);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/admin/users?account_status=banned')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($banned->public_id);

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/admin/users')->assertForbidden();
});
