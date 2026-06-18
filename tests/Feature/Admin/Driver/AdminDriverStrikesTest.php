<?php

declare(strict_types=1);

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

it('lists strikes with an active_count for an admin', function (): void {
    $admin = User::factory()->create(['must_change_password' => false]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $driver = User::factory()->create();
    $driver->assignRole('driver');

    DriverStrike::create([
        'driver_id' => $driver->id,
        'reason' => 'no_show_at_pickup',
        'issued_by' => 'system',
        'fee_amount' => 10,
        'is_voided' => false,
    ]);
    DriverStrike::create([
        'driver_id' => $driver->id,
        'reason' => 'customer_complaint',
        'issued_by' => 'admin',
        'fee_amount' => 0,
        'is_voided' => true,
        'voided_at' => now(),
    ]);

    $response = $this->getJson("/api/admin/drivers/{$driver->public_id}/strikes");

    expect($response->status())->toBe(200);
    $response->assertJsonStructure([
        'active_count',
        'total',
        'strikes' => [['id', 'reason', 'issued_by', 'fee_amount', 'is_voided', 'created_at']],
    ]);
    expect($response->json('active_count'))->toBe(1);
    expect($response->json('total'))->toBe(2);
});

it('forbids non-admins', function (): void {
    $driver = User::factory()->create();
    Sanctum::actingAs(User::factory()->create(['must_change_password' => false]));

    $this->getJson("/api/admin/drivers/{$driver->public_id}/strikes")->assertForbidden();
});
