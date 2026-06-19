<?php

declare(strict_types=1);

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('driver', 'web');
});

it('exposes account_status on list rows and filters by activity_status', function (): void {
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $online = User::factory()->create();
    $online->assignRole('driver');
    DriverProfile::factory()->create(['user_id' => $online->id, 'status' => 'active', 'activity_status' => 'online']);

    $offline = User::factory()->create();
    $offline->assignRole('driver');
    DriverProfile::factory()->create(['user_id' => $offline->id, 'status' => 'active', 'activity_status' => 'offline']);

    $list = $this->getJson('/api/admin/drivers');
    expect($list->status())->toBe(200);
    $list->assertJsonStructure(['data' => [['account_status', 'activity_status']]]);

    $filtered = $this->getJson('/api/admin/drivers?activity_status=online');
    expect($filtered->status())->toBe(200);
    expect(collect($filtered->json('data'))->pluck('activity_status')->unique()->values()->all())->toBe(['online']);
});

it('adds regions, roles, counts and notification prefs to the driver detail', function (): void {
    $world = TestWorld::create();

    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $driver = User::factory()->create();
    $driver->assignRole('driver');
    DriverProfile::factory()->create(['user_id' => $driver->id, 'status' => 'active', 'office_id' => $world['office']->id]);
    $driver->driverRegions()->attach($world['region']->id);

    $detail = $this->getJson("/api/admin/drivers/{$driver->public_id}");
    expect($detail->status())->toBe(200);
    $detail->assertJsonStructure([
        'driver_profile' => [
            'regions' => [['id', 'name']],
            'last_active_at',
            'deliveries_today',
            'roles',
            'orders_as_customer_count',
            'notification_prefs' => ['push', 'sms', 'email'],
        ],
    ]);
    expect($detail->json('driver_profile.roles'))->toContain('driver');
    expect($detail->json('driver_profile.deliveries_today'))->toBe(0);
});
