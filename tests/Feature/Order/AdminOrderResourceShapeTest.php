<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('admin order resource exposes public ids, no internal user/driver ids', function (): void {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $sender = User::factory()->create();
    $driver = User::factory()->create();
    $order = Order::factory()->create([
        'sender_user_id' => $sender->id,
        'driver_id' => $driver->id,
    ]);

    $row = $this->getJson("/api/admin/orders/{$order->public_id}")->json('data');

    expect(data_get($row, 'sender.id'))->toBe($sender->public_id);
    expect(data_get($row, 'sender.name'))->toBe($sender->fullName());
    expect($row['sender'])->not->toHaveKey('user_id');

    expect(data_get($row, 'driver.id'))->toBe($driver->public_id);
    expect($row['driver'])->not->toHaveKey('user_id');
});
