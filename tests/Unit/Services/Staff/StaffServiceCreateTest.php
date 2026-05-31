<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Models\OfficeLocation;
use App\Models\OfficeStaffAssignment;
use App\Models\User;
use App\Services\Staff\StaffService;
use App\Support\DTO\CreateStaffInput;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');
});

it('creates an admin user with a generated temp password', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('admin');

    $service = app(StaffService::class);

    $result = $service->create(new CreateStaffInput(
        phoneNumber: '+218910000010',
        firstName: 'Aya',
        lastName: 'Smith',
        email: null,
        role: 'admin',
    ), $actor);

    expect($result)->toHaveKeys(['user', 'temporary_password']);
    expect($result['user'])->toBeInstanceOf(User::class);
    expect($result['user']->first_name)->toBe('Aya');
    expect($result['user']->phone_number)->toBe('+218910000010');
    expect($result['user']->account_status)->toBe(AccountStatus::Active);
    expect($result['user']->must_change_password)->toBeTrue();
    expect($result['user']->hasRole('admin'))->toBeTrue();
    expect($result['temporary_password'])->toBeString();
    expect(strlen($result['temporary_password']))->toBe(10);
    expect(Hash::check($result['temporary_password'], $result['user']->password))->toBeTrue();
});

it('creates an office staff user with office assignments', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('admin');

    $office = OfficeLocation::create([
        'region_id' => null,
        'name' => 'Test Office',
        'address' => 'Test address',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);

    $result = app(StaffService::class)->create(new CreateStaffInput(
        phoneNumber: '+218910000022',
        firstName: 'Yusuf',
        lastName: 'Smith',
        email: null,
        role: 'office_staff',
        officeAssignments: [['office_id' => $office->id, 'is_manager' => true]],
    ), $actor);

    $activeAssignment = OfficeStaffAssignment::query()
        ->where('user_id', $result['user']->id)
        ->where('office_id', $office->id)
        ->whereNull('removed_at')
        ->first();

    expect($result['user']->hasRole('office_staff'))->toBeTrue();
    expect($activeAssignment)->not->toBeNull();
    expect((bool) $activeAssignment->is_manager)->toBeTrue();
});
