<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('admin assign mutation response carries nested sender and driver public ids', function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('driver', 'web');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $driver = makeOnlineDriverAt(32.8872, 13.1913);
    $order = makeOrderAt(32.8872, 13.1913);
    $sender = $order->sender;

    $row = $this->postJson("/api/admin/orders/{$order->public_id}/assign", [
        'driver_public_id' => $driver->public_id,
    ])->assertOk()->json('data');

    expect(data_get($row, 'sender.id'))->not->toBeNull()
        ->and(data_get($row, 'sender.id'))->toBe($sender->public_id)
        ->and(data_get($row, 'driver.id'))->not->toBeNull()
        ->and(data_get($row, 'driver.id'))->toBe($driver->public_id);
});
