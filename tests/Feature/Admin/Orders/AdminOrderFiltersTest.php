<?php

declare(strict_types=1);

use App\Models\MerchantProfile;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('driver', 'web');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);
});

it('filters admin orders by driver public id merchant public id and search', function (): void {
    $driver = User::factory()->create();
    $driver->assignRole('driver');
    $otherDriver = User::factory()->create();

    $merchant = MerchantProfile::factory()->create();
    $otherMerchant = MerchantProfile::factory()->create();

    $driverOrder = Order::factory()->create(['driver_id' => $driver->id]);
    Order::factory()->create(['driver_id' => $otherDriver->id]);

    $merchantOrder = Order::factory()->create(['merchant_profile_id' => $merchant->id]);
    Order::factory()->create(['merchant_profile_id' => $otherMerchant->id]);

    $needle = Order::factory()->create(['sender_name' => 'Needle Sender']);

    $this->getJson("/api/admin/orders?driver_public_id={$driver->public_id}")
        ->assertOk()
        ->assertJsonPath('data.0.id', $driverOrder->public_id);

    $this->getJson("/api/admin/orders?merchant_public_id={$merchant->public_id}")
        ->assertOk()
        ->assertJsonPath('data.0.id', $merchantOrder->public_id);

    $this->getJson('/api/admin/orders?search=Needle')
        ->assertOk()
        ->assertJsonPath('data.0.id', $needle->public_id);
});
