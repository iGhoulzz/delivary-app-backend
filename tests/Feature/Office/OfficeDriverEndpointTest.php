<?php

declare(strict_types=1);

use App\Enums\DriverDocumentType;
use App\Enums\DriverErrorCode;
use App\Enums\DriverStatus;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\OfficeLocation;
use App\Models\OfficeStaffAssignment;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');
    Role::findOrCreate('driver', 'web');
});

function makeOfficeForDriverTest(): OfficeLocation
{
    return OfficeLocation::create([
        'region_id' => null,
        'name' => 'Office '.uniqid(),
        'address' => 'addr',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);
}

function makeOfficeStaff(OfficeLocation $office): User
{
    $staff = User::factory()->create();
    $staff->assignRole('office_staff');

    OfficeStaffAssignment::create([
        'user_id' => $staff->id,
        'office_id' => $office->id,
        'is_manager' => false,
        'assigned_at' => now(),
    ]);

    return $staff;
}

function makeOfficeDriverProfile(OfficeLocation $office, DriverStatus $status = DriverStatus::PreRegistered): DriverProfile
{
    $driver = User::factory()->create();
    $driver->assignRole('driver');

    return DriverProfile::factory()->create([
        'user_id' => $driver->id,
        'office_id' => $office->id,
        'status' => $status->value,
    ]);
}

it('rejects an office staff not assigned to the drivers office with WRONG_OFFICE', function (): void {
    $driverOffice = makeOfficeForDriverTest();
    $otherOffice = makeOfficeForDriverTest();

    $profile = makeOfficeDriverProfile($driverOffice);
    $staff = makeOfficeStaff($otherOffice);
    Sanctum::actingAs($staff);

    $response = $this->deleteJson(
        "/api/office/drivers/{$profile->user->public_id}/documents/national_id_front",
    );

    expect($response->status())->toBe(DriverErrorCode::WrongOffice->httpStatus());
    expect($response->json('error'))->toBe(DriverErrorCode::WrongOffice->value);
});

it('deletes a driver document by scoped type when assigned', function (): void {
    $office = makeOfficeForDriverTest();

    $profile = makeOfficeDriverProfile($office);
    $staff = makeOfficeStaff($office);
    Sanctum::actingAs($staff);

    $document = DriverDocument::create([
        'driver_id' => $profile->user_id,
        'document_type' => DriverDocumentType::NationalIdFront->value,
        'verified' => false,
    ]);

    $response = $this->deleteJson(
        "/api/office/drivers/{$profile->user->public_id}/documents/national_id_front",
    );

    expect($response->status())->toBe(204);
    expect(DriverDocument::query()->whereKey($document->id)->exists())->toBeFalse();
});
