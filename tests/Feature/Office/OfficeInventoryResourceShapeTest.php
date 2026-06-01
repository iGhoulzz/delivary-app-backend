<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\OfficeInventory;
use App\Models\OfficeLocation;
use App\Models\Order;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('embedded office inventory uses public ids + nested audit attribution', function (): void {
    Role::findOrCreate('office_staff', 'web');

    $office = OfficeLocation::create([
        'region_id' => null,
        'name' => 'Inventory Office '.uniqid(),
        'address' => 'addr',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);

    $staff = User::factory()->create();
    $staff->assignRole('office_staff');
    $staff->officeStaffAssignments()->create([
        'office_id' => $office->id,
        'is_manager' => false,
        'assigned_at' => now(),
    ]);

    $order = Order::factory()->create([
        'status' => OrderStatus::AtOffice->value,
        'return_office_id' => $office->id,
    ]);

    $inv = OfficeInventory::create([
        'order_id' => $order->id,
        'office_id' => $office->id,
        'received_by_staff_id' => $staff->id,
        'received_at' => now(),
    ]);

    Sanctum::actingAs($staff);

    $body = $this->getJson("/api/office/orders/{$order->public_id}");
    $body->assertStatus(200);

    $row = $body->json('data.inventory');

    expect($row)->not->toBeNull();
    expect($row)->not->toHaveKey('office_id');
    expect($row)->not->toHaveKey('received_by_staff_id');
    expect(data_get($row, 'office.id'))->toBe($office->public_id);
    expect(data_get($row, 'received_by.id'))->toBe($staff->public_id);
});
