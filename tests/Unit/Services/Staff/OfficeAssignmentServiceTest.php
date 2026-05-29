<?php

declare(strict_types=1);

use App\Exceptions\Staff\StaffDomainException;
use App\Models\OfficeLocation;
use App\Models\OfficeStaffAssignment;
use App\Models\User;
use App\Services\Staff\OfficeAssignmentService;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('office_staff', 'web');
    Role::findOrCreate('admin', 'web');
});

function makeStaffCrudOffice(): OfficeLocation
{
    return OfficeLocation::create([
        'region_id' => null,
        'name' => 'Test Office '.uniqid(),
        'address' => 'Test address',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);
}

function makeOfficeStaffUserForAssignments(): User
{
    $user = User::factory()->create();
    $user->assignRole('office_staff');

    return $user;
}

it('attaches an office assignment to an office staff user', function (): void {
    $user = makeOfficeStaffUserForAssignments();
    $office = makeStaffCrudOffice();

    $assignment = app(OfficeAssignmentService::class)->attach($user, $office->id, true);

    expect($assignment)->toBeInstanceOf(OfficeStaffAssignment::class);
    expect($assignment->user_id)->toBe($user->id);
    expect($assignment->office_id)->toBe($office->id);
    expect($assignment->public_id)->toBeString();
    expect((bool) $assignment->is_manager)->toBeTrue();
    expect($assignment->removed_at)->toBeNull();
    expect($assignment->assigned_at)->not->toBeNull();
});

it('refuses to attach when user is not office staff', function (): void {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $office = makeStaffCrudOffice();

    expect(fn () => app(OfficeAssignmentService::class)->attach($user, $office->id, false))
        ->toThrow(StaffDomainException::class, 'User is not office staff.');
});

it('refuses duplicate active assignments', function (): void {
    $user = makeOfficeStaffUserForAssignments();
    $office = makeStaffCrudOffice();

    app(OfficeAssignmentService::class)->attach($user, $office->id, false);

    expect(fn () => app(OfficeAssignmentService::class)->attach($user, $office->id, false))
        ->toThrow(StaffDomainException::class, 'Office assignment is already active.');
});

it('allows reattaching an office after the previous assignment was removed', function (): void {
    $user = makeOfficeStaffUserForAssignments();
    $office = makeStaffCrudOffice();
    $otherOffice = makeStaffCrudOffice();

    $assignment = app(OfficeAssignmentService::class)->attach($user, $office->id, false);
    app(OfficeAssignmentService::class)->attach($user, $otherOffice->id, false);
    app(OfficeAssignmentService::class)->detach($user, $assignment);

    $reattached = app(OfficeAssignmentService::class)->attach($user, $office->id, true);

    expect($reattached->id)->not->toBe($assignment->id);
    expect($reattached->office_id)->toBe($office->id);
    expect((bool) $reattached->is_manager)->toBeTrue();
});

it('detaches an assignment by soft removal', function (): void {
    $user = makeOfficeStaffUserForAssignments();
    $firstOffice = makeStaffCrudOffice();
    $secondOffice = makeStaffCrudOffice();

    $assignment = app(OfficeAssignmentService::class)->attach($user, $firstOffice->id, false);
    app(OfficeAssignmentService::class)->attach($user, $secondOffice->id, false);

    app(OfficeAssignmentService::class)->detach($user, $assignment);

    expect($assignment->fresh()->removed_at)->not->toBeNull();
});

it('refuses to detach when it would leave zero active assignments', function (): void {
    $user = makeOfficeStaffUserForAssignments();
    $office = makeStaffCrudOffice();
    $assignment = app(OfficeAssignmentService::class)->attach($user, $office->id, false);

    expect(fn () => app(OfficeAssignmentService::class)->detach($user, $assignment))
        ->toThrow(StaffDomainException::class, 'Cannot remove the last active office assignment.');
});

it('does not detach an assignment belonging to another staff user', function (): void {
    $firstUser = makeOfficeStaffUserForAssignments();
    $secondUser = makeOfficeStaffUserForAssignments();
    $firstOffice = makeStaffCrudOffice();
    $secondOffice = makeStaffCrudOffice();

    $assignment = app(OfficeAssignmentService::class)->attach($firstUser, $firstOffice->id, false);
    app(OfficeAssignmentService::class)->attach($secondUser, $secondOffice->id, false);

    app(OfficeAssignmentService::class)->detach($secondUser, $assignment);

    expect($assignment->fresh()->removed_at)->toBeNull();
});

it('attachMany attaches multiple assignments in one call', function (): void {
    $user = makeOfficeStaffUserForAssignments();
    $firstOffice = makeStaffCrudOffice();
    $secondOffice = makeStaffCrudOffice();

    $result = app(OfficeAssignmentService::class)->attachMany($user, [
        ['office_id' => $firstOffice->id, 'is_manager' => false],
        ['office_id' => $secondOffice->id, 'is_manager' => true],
    ]);

    $activeCount = OfficeStaffAssignment::query()
        ->where('user_id', $user->id)
        ->whereNull('removed_at')
        ->count();

    expect($result)->toHaveCount(2);
    expect($activeCount)->toBe(2);
});

it('attachMany refuses duplicate office ids in the same payload', function (): void {
    $user = makeOfficeStaffUserForAssignments();
    $office = makeStaffCrudOffice();

    expect(fn () => app(OfficeAssignmentService::class)->attachMany($user, [
        ['office_id' => $office->id, 'is_manager' => false],
        ['office_id' => $office->id, 'is_manager' => true],
    ]))->toThrow(StaffDomainException::class, 'Office assignment is already active.');
});
