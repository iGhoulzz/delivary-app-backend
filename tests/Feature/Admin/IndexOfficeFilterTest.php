<?php

declare(strict_types=1);

use App\Enums\SettlementStatus;
use App\Models\DriverProfile;
use App\Models\OfficeLocation;
use App\Models\SellerPayout;
use App\Models\Settlement;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function makeIndexFilterAdmin(): User
{
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    return $admin;
}

function makeIndexFilterOffice(): OfficeLocation
{
    return OfficeLocation::create([
        'region_id' => null,
        'name' => 'Office '.uniqid(),
        'address' => 'addr',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);
}

it('filters admin driver index by office_public_id', function (): void {
    Role::findOrCreate('driver', 'web');
    makeIndexFilterAdmin();

    $officeA = makeIndexFilterOffice();
    $officeB = makeIndexFilterOffice();

    $driverA = User::factory()->create();
    $driverA->assignRole('driver');
    DriverProfile::factory()->create(['user_id' => $driverA->id, 'office_id' => $officeA->id]);

    $driverB = User::factory()->create();
    $driverB->assignRole('driver');
    DriverProfile::factory()->create(['user_id' => $driverB->id, 'office_id' => $officeB->id]);

    $response = $this->getJson("/api/admin/drivers?office_public_id={$officeA->public_id}");

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(1);
});

it('rejects admin driver index filter with a bare internal office id', function (): void {
    makeIndexFilterAdmin();
    $office = makeIndexFilterOffice();

    $response = $this->getJson("/api/admin/drivers?office_public_id={$office->id}");

    expect($response->status())->toBe(422);
});

it('filters admin settlements index by office_public_id', function (): void {
    $admin = makeIndexFilterAdmin();
    $officeA = makeIndexFilterOffice();
    $officeB = makeIndexFilterOffice();
    $driver = User::factory()->create();

    foreach ([$officeA, $officeB] as $office) {
        Settlement::create([
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
    }

    $response = $this->getJson("/api/admin/settlements?office_public_id={$officeA->public_id}");

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(1);
});

it('rejects admin settlements index filter with a bare internal office id', function (): void {
    makeIndexFilterAdmin();
    $office = makeIndexFilterOffice();

    $response = $this->getJson("/api/admin/settlements?office_public_id={$office->id}");

    expect($response->status())->toBe(422);
});

it('filters admin seller-payouts index by office_public_id', function (): void {
    $admin = makeIndexFilterAdmin();
    $officeA = makeIndexFilterOffice();
    $officeB = makeIndexFilterOffice();
    $seller = User::factory()->create();

    foreach ([$officeA, $officeB] as $office) {
        SellerPayout::create([
            'user_id' => $seller->id,
            'office_id' => $office->id,
            'paid_by_staff_id' => $admin->id,
            'amount' => '0',
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    $response = $this->getJson("/api/admin/seller-payouts?office_public_id={$officeA->public_id}");

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(1);
});

it('rejects admin seller-payouts index filter with a bare internal office id', function (): void {
    makeIndexFilterAdmin();
    $office = makeIndexFilterOffice();

    $response = $this->getJson("/api/admin/seller-payouts?office_public_id={$office->id}");

    expect($response->status())->toBe(422);
});
