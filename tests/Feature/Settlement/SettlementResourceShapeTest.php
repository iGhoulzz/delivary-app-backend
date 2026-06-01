<?php

declare(strict_types=1);

use App\Enums\SettlementStatus;
use App\Models\OfficeLocation;
use App\Models\Settlement;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('admin settlement resource exposes office public_id, not internal id', function (): void {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $office = OfficeLocation::create([
        'region_id' => null,
        'name' => 'Settlement Office '.uniqid(),
        'address' => 'addr',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);

    $driver = User::factory()->create();

    $settlement = Settlement::create([
        'driver_id' => $driver->id,
        'office_id' => $office->id,
        'processed_by_staff_id' => $admin->id,
        'cash_received_from_driver' => '0',
        'cash_paid_to_driver' => '0',
        'cash_to_deposit_cleared' => '0',
        'earnings_balance_cleared' => '0',
        'debt_balance_cleared' => '0',
        'shortage_amount' => '0',
        'excess_amount' => '0',
        'status' => SettlementStatus::Completed->value,
    ]);

    $response = $this->getJson("/api/admin/settlements/{$settlement->public_id}");
    $response->assertStatus(200);

    $office_block = $response->json('data.office');
    expect($office_block['id'])->toBe($office->public_id);
    expect($office_block['name'])->toBe($office->name);
});
