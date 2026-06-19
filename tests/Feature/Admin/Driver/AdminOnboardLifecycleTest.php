<?php

declare(strict_types=1);

use App\Enums\DriverDocumentType;
use App\Enums\DriverErrorCode;
use App\Enums\DriverStatus;
use App\Enums\OtpPurpose;
use App\Enums\VehicleType;
use App\Models\DriverDocument;
use App\Models\OfficeLocation;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['admin', 'driver', 'user'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    Sanctum::actingAs($this->admin);
});

function lifecycleOffice(): OfficeLocation
{
    return OfficeLocation::create([
        'region_id' => null,
        'name' => 'Lifecycle Office '.uniqid(),
        'address' => 'Tripoli office',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);
}

function lifecycleOnboardPayload(OfficeLocation $office, string $phone): array
{
    return [
        'mode' => 'new',
        'office_public_id' => $office->public_id,
        'phone_number' => $phone,
        'first_name' => 'Lifecycle',
        'last_name' => 'Driver',
        'vehicle_type' => VehicleType::Car->value,
        'vehicle_plate' => 'LC-123',
    ];
}

it('reuses the full onboarding lifecycle through docs phone verification and submit', function (): void {
    $office = lifecycleOffice();
    $phone = '+218910300001';

    $this->postJson('/api/admin/drivers', lifecycleOnboardPayload($office, $phone))
        ->assertCreated()
        ->assertJsonPath('driver_profile.status', DriverStatus::PreRegistered->value);

    $driver = User::query()->where('phone_number', $phone)->firstOrFail();

    foreach (DriverDocumentType::requiredForApproval() as $type) {
        $this->post("/api/admin/drivers/{$driver->public_id}/documents", [
            'type' => $type->value,
            'file' => UploadedFile::fake()->create($type->value.'.pdf', 12, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('driver_document.document_type', $type->value);
    }

    Cache::put(
        OtpPurpose::Registration->cacheKeyFor($phone),
        ['code' => Hash::make('123456'), 'attempts' => 0],
        300,
    );

    $this->postJson("/api/admin/drivers/{$driver->public_id}/verify-phone", ['code' => '123456'])
        ->assertNoContent();

    $this->postJson("/api/admin/drivers/{$driver->public_id}/submit")
        ->assertOk()
        ->assertJsonPath('driver_profile.status', DriverStatus::PendingApproval->value);

    expect($driver->fresh()->driverProfile?->status)->toBe(DriverStatus::PendingApproval);
});

it('rejects submit without phone verification or required documents using office lifecycle guards', function (): void {
    $office = lifecycleOffice();
    $phone = '+218910300002';

    $this->postJson('/api/admin/drivers', lifecycleOnboardPayload($office, $phone))->assertCreated();
    $driver = User::query()->where('phone_number', $phone)->firstOrFail();

    $this->postJson("/api/admin/drivers/{$driver->public_id}/submit")
        ->assertStatus(DriverErrorCode::PhoneNotVerified->httpStatus())
        ->assertJsonPath('error', DriverErrorCode::PhoneNotVerified->value);

    $driver->forceFill(['phone_verified_at' => now()])->save();

    $this->postJson("/api/admin/drivers/{$driver->public_id}/submit")
        ->assertStatus(DriverErrorCode::MissingDocuments->httpStatus())
        ->assertJsonPath('error', DriverErrorCode::MissingDocuments->value)
        ->assertJsonCount(count(DriverDocumentType::requiredForApproval()), 'missing');
});

it('deletes an uploaded document while the driver is pre registered', function (): void {
    $office = lifecycleOffice();
    $phone = '+218910300003';

    $this->postJson('/api/admin/drivers', lifecycleOnboardPayload($office, $phone))->assertCreated();
    $driver = User::query()->where('phone_number', $phone)->firstOrFail();

    $document = DriverDocument::create([
        'driver_id' => $driver->id,
        'document_type' => DriverDocumentType::NationalIdFront->value,
        'verified' => false,
    ]);

    $this->deleteJson("/api/admin/drivers/{$driver->public_id}/documents/national_id_front")
        ->assertNoContent();

    expect(DriverDocument::query()->whereKey($document->id)->exists())->toBeFalse();
});
