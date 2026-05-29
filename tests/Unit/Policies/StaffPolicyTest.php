<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\StaffPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');
});

it('grants every staff-management ability to admins only', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $officeStaff = User::factory()->create();
    $officeStaff->assignRole('office_staff');

    $regular = User::factory()->create();
    $target = User::factory()->create();

    $policy = new StaffPolicy;

    foreach (['viewAny', 'create'] as $ability) {
        expect($policy->$ability($admin))->toBeTrue();
        expect($policy->$ability($officeStaff))->toBeFalse();
        expect($policy->$ability($regular))->toBeFalse();
    }

    foreach (['view', 'update', 'suspend', 'reinstate', 'deactivate', 'resetTempPassword', 'manageOfficeAssignments'] as $ability) {
        expect($policy->$ability($admin, $target))->toBeTrue();
        expect($policy->$ability($officeStaff, $target))->toBeFalse();
        expect($policy->$ability($regular, $target))->toBeFalse();
    }
});
