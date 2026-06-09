<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Exceptions\Staff\StaffDomainException;
use App\Models\OfficeLocation;
use App\Models\User;
use App\Services\Staff\StaffService;
use App\Services\Staff\TempPasswordChangeService;
use App\Support\DTO\CreateStaffInput;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $world = TestWorld::create();
    $this->region = $world['region'];
    $this->office1 = $world['office'];
    $this->office2 = OfficeLocation::create([
        'region_id' => $this->region->id,
        'name' => 'Test Office 2',
        'address' => 'addr 2',
        'location' => Point::makeGeodetic(32.9, 13.2),
        'is_active' => true,
    ]);

    $this->staff = app(StaffService::class);
    $this->rootAdmin = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $this->rootAdmin->assignRole('admin');
    $this->coAdmin = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $this->coAdmin->assignRole('admin');
});

function uniqueStaffPhone(): string
{
    return '+218910'.random_int(100000, 999999);
}

it('creates an admin who must change a temporary password on first use', function (): void {
    $created = $this->staff->create(new CreateStaffInput(
        phoneNumber: uniqueStaffPhone(),
        firstName: 'New',
        lastName: 'Admin',
        email: null,
        role: 'admin',
    ), $this->rootAdmin);

    expect($created['temporary_password'])->toBeString();
    expect($created['user']->must_change_password)->toBeTrue();

    $changed = app(TempPasswordChangeService::class)
        ->change($created['user'], $created['temporary_password'], 'newPass99X');

    expect($changed['user']->must_change_password)->toBeFalse();
    expect($changed['token'])->toBeString();
});

it('creates office_staff with two office assignments', function (): void {
    $officeStaff = $this->staff->create(new CreateStaffInput(
        phoneNumber: uniqueStaffPhone(),
        firstName: 'Office',
        lastName: 'Worker',
        email: null,
        role: 'office_staff',
        officeAssignments: [
            ['office_id' => $this->office1->id, 'is_manager' => false],
            ['office_id' => $this->office2->id, 'is_manager' => true],
        ],
    ), $this->rootAdmin);

    expect($officeStaff['user']->activeOfficeAssignments()->count())->toBe(2);
});

it('resets another admin password and revokes their tokens', function (): void {
    $this->coAdmin->createToken('old');

    $reset = $this->staff->resetTempPassword($this->coAdmin, $this->rootAdmin);

    expect($reset['user']->must_change_password)->toBeTrue();
    expect($this->coAdmin->fresh()->tokens()->count())->toBe(0);
});

it('suspends office_staff while preserving their office assignments', function (): void {
    $officeStaff = $this->staff->create(new CreateStaffInput(
        phoneNumber: uniqueStaffPhone(),
        firstName: 'Office',
        lastName: 'Worker',
        email: null,
        role: 'office_staff',
        officeAssignments: [
            ['office_id' => $this->office1->id, 'is_manager' => false],
            ['office_id' => $this->office2->id, 'is_manager' => true],
        ],
    ), $this->rootAdmin);

    $suspended = $this->staff->suspend($officeStaff['user'], $this->rootAdmin);

    expect($suspended->account_status)->toBe(AccountStatus::Suspended);
    expect($officeStaff['user']->fresh()->activeOfficeAssignments()->count())->toBe(2);
});

it('reinstates then deactivates office_staff (deactivate removes assignments)', function (): void {
    $officeStaff = $this->staff->create(new CreateStaffInput(
        phoneNumber: uniqueStaffPhone(),
        firstName: 'Office',
        lastName: 'Worker',
        email: null,
        role: 'office_staff',
        officeAssignments: [
            ['office_id' => $this->office1->id, 'is_manager' => false],
            ['office_id' => $this->office2->id, 'is_manager' => true],
        ],
    ), $this->rootAdmin);
    $this->staff->suspend($officeStaff['user'], $this->rootAdmin);

    $reinstated = $this->staff->reinstate($officeStaff['user']->fresh(), $this->rootAdmin);
    expect($reinstated->activeOfficeAssignments()->count())->toBe(2);

    $deactivated = $this->staff->deactivate($reinstated, $this->rootAdmin);
    expect($deactivated->account_status)->toBe(AccountStatus::Suspended);
    expect($deactivated->fresh()->activeOfficeAssignments()->count())->toBe(0);
});

it('rejects self-suspension', function (): void {
    expect(fn () => $this->staff->suspend($this->rootAdmin, $this->rootAdmin))
        ->toThrow(StaffDomainException::class);
});

it('protects the last active admin from suspension', function (): void {
    // Suspend the co-admin first, leaving rootAdmin as the only active admin.
    $this->staff->suspend($this->coAdmin, $this->rootAdmin);

    expect(fn () => $this->staff->suspend($this->rootAdmin->fresh(), $this->coAdmin->fresh()))
        ->toThrow(StaffDomainException::class);
});
