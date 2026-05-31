<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Exceptions\Staff\StaffDomainException;
use App\Models\OfficeLocation;
use App\Models\OfficeStaffAssignment;
use App\Models\User;
use App\Services\Staff\StaffService;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');
});

function makeAdmin(): User
{
    $user = User::factory()->create([
        'account_status' => AccountStatus::Active->value,
        'must_change_password' => false,
    ]);
    $user->assignRole('admin');

    return $user;
}

it('suspends another admin, revokes their tokens, sets status', function (): void {
    $actor = makeAdmin();
    $target = makeAdmin();
    $protector = makeAdmin();
    $target->createToken('test');

    expect($target->tokens()->count())->toBe(1);

    $result = app(StaffService::class)->suspend($target, $actor);

    expect($result->account_status)->toBe(AccountStatus::Suspended);
    expect($target->tokens()->count())->toBe(0);
});

it('refuses self-suspension', function (): void {
    $actor = makeAdmin();
    makeAdmin();

    expect(fn () => app(StaffService::class)->suspend($actor, $actor))
        ->toThrow(StaffDomainException::class, 'cannot perform this action on their own');
});

it('refuses to suspend the last admin', function (): void {
    $actor = makeAdmin();
    $target = makeAdmin();

    $other = makeAdmin();
    app(StaffService::class)->suspend($other, $actor);

    app(StaffService::class)->suspend($target, $actor);

    $newAdmin = makeAdmin();
    app(StaffService::class)->suspend($newAdmin, $actor);

    $inactive = User::factory()->create(['account_status' => AccountStatus::Suspended->value]);
    $inactive->assignRole('admin');

    expect(fn () => app(StaffService::class)->suspend($actor, $inactive))
        ->toThrow(StaffDomainException::class, 'last active admin');
});

it('reinstates a suspended admin', function (): void {
    $actor = makeAdmin();
    $target = makeAdmin();
    makeAdmin();

    app(StaffService::class)->suspend($target, $actor);
    expect($target->fresh()->account_status)->toBe(AccountStatus::Suspended);

    $result = app(StaffService::class)->reinstate($target, $actor);

    expect($result->account_status)->toBe(AccountStatus::Active);
});

it('preserves office assignments on suspend and removes them on deactivate', function (): void {
    $actor = makeAdmin();
    $staff = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $staff->assignRole('office_staff');
    $office = OfficeLocation::create([
        'region_id' => null,
        'name' => 'Test Office',
        'address' => 'Test address',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);
    OfficeStaffAssignment::create([
        'user_id' => $staff->id,
        'office_id' => $office->id,
        'is_manager' => false,
        'assigned_at' => now(),
    ]);

    $suspended = app(StaffService::class)->suspend($staff, $actor);

    expect($suspended->activeOfficeAssignments()->count())->toBe(1);

    $reinstated = app(StaffService::class)->reinstate($suspended, $actor);
    $deactivated = app(StaffService::class)->deactivate($reinstated, $actor);

    expect($deactivated->activeOfficeAssignments()->count())->toBe(0);
});

it('resets temp password — regenerates, sets flag, revokes tokens', function (): void {
    $actor = makeAdmin();
    $target = makeAdmin();
    $target->createToken('test');
    $oldHash = $target->password;

    $result = app(StaffService::class)->resetTempPassword($target, $actor);

    expect($result)->toHaveKeys(['user', 'temporary_password']);
    expect($result['user']->must_change_password)->toBeTrue();
    expect(Hash::check($result['temporary_password'], $result['user']->password))->toBeTrue();
    expect($result['user']->password)->not->toBe($oldHash);
    expect($target->tokens()->count())->toBe(0);
});

it('refuses self-reset of temp password', function (): void {
    $actor = makeAdmin();
    makeAdmin();

    expect(fn () => app(StaffService::class)->resetTempPassword($actor, $actor))
        ->toThrow(StaffDomainException::class, 'cannot perform this action on their own');
});
