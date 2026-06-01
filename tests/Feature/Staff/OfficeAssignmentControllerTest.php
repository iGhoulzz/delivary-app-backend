<?php

declare(strict_types=1);

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
});

function makeStaffCrudFeatureOffice(): OfficeLocation
{
    return OfficeLocation::create([
        'region_id' => null,
        'name' => 'Office '.uniqid(),
        'address' => 'addr',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);
}

it('attaches an office to an office staff user via post', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $staff = User::factory()->create();
    $staff->assignRole('office_staff');
    $office = makeStaffCrudFeatureOffice();

    $response = $this->postJson(
        "/api/admin/staff/{$staff->public_id}/office-assignments",
        ['office_public_id' => $office->public_id, 'is_manager' => true],
    );

    expect($response->status())->toBe(201);
    expect($response->json('office.name'))->toBe($office->name);
    expect($response->json('is_manager'))->toBeTrue();
});

it('rejects attaching an office by bare internal id', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $staff = User::factory()->create();
    $staff->assignRole('office_staff');
    $office = makeStaffCrudFeatureOffice();

    $response = $this->postJson(
        "/api/admin/staff/{$staff->public_id}/office-assignments",
        ['office_public_id' => (string) $office->id, 'is_manager' => false],
    );

    expect($response->status())->toBe(422);
});

it('rejects attaching an office to an admin user', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $target = User::factory()->create();
    $target->assignRole('admin');

    $response = $this->postJson(
        "/api/admin/staff/{$target->public_id}/office-assignments",
        ['office_public_id' => makeStaffCrudFeatureOffice()->public_id, 'is_manager' => false],
    );

    expect($response->status())->toBe(422);
    expect($response->json('error'))->toBe('ROLE_MISMATCH_FOR_OFFICE_ASSIGN');
});

it('rejects duplicate active assignment with conflict', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $staff = User::factory()->create();
    $staff->assignRole('office_staff');
    $office = makeStaffCrudFeatureOffice();

    OfficeStaffAssignment::create([
        'user_id' => $staff->id,
        'office_id' => $office->id,
        'is_manager' => false,
        'assigned_at' => now(),
    ]);

    $response = $this->postJson(
        "/api/admin/staff/{$staff->public_id}/office-assignments",
        ['office_public_id' => $office->public_id, 'is_manager' => false],
    );

    expect($response->status())->toBe(409);
    expect($response->json('error'))->toBe('OFFICE_ASSIGNMENT_DUPLICATE');
});

it('detaches an assignment via delete', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $staff = User::factory()->create();
    $staff->assignRole('office_staff');

    $assignment = OfficeStaffAssignment::create([
        'user_id' => $staff->id,
        'office_id' => makeStaffCrudFeatureOffice()->id,
        'is_manager' => false,
        'assigned_at' => now(),
    ]);
    OfficeStaffAssignment::create([
        'user_id' => $staff->id,
        'office_id' => makeStaffCrudFeatureOffice()->id,
        'is_manager' => false,
        'assigned_at' => now(),
    ]);

    $response = $this->deleteJson(
        "/api/admin/staff/{$staff->public_id}/office-assignments/{$assignment->public_id}",
    );

    expect($response->status())->toBe(204);
    expect($assignment->fresh()->removed_at)->not->toBeNull();
});

it('rejects last assignment detach', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $staff = User::factory()->create();
    $staff->assignRole('office_staff');

    $assignment = OfficeStaffAssignment::create([
        'user_id' => $staff->id,
        'office_id' => makeStaffCrudFeatureOffice()->id,
        'is_manager' => false,
        'assigned_at' => now(),
    ]);

    $response = $this->deleteJson(
        "/api/admin/staff/{$staff->public_id}/office-assignments/{$assignment->public_id}",
    );

    expect($response->status())->toBe(422);
    expect($response->json('error'))->toBe('OFFICE_ASSIGNMENT_LAST_REQUIRED');
});

it('does not detach another staff users assignment', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $firstStaff = User::factory()->create();
    $firstStaff->assignRole('office_staff');
    $secondStaff = User::factory()->create();
    $secondStaff->assignRole('office_staff');

    $assignment = OfficeStaffAssignment::create([
        'user_id' => $firstStaff->id,
        'office_id' => makeStaffCrudFeatureOffice()->id,
        'is_manager' => false,
        'assigned_at' => now(),
    ]);

    $response = $this->deleteJson(
        "/api/admin/staff/{$secondStaff->public_id}/office-assignments/{$assignment->public_id}",
    );

    expect($response->status())->toBe(404);
    expect($assignment->fresh()->removed_at)->toBeNull();
});
