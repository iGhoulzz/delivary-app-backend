<?php

declare(strict_types=1);

config(['broadcasting.default' => 'null']);

use App\Enums\AccountStatus;
use App\Models\OfficeLocation;
use App\Models\Region;
use App\Models\User;
use App\Services\Staff\StaffService;
use App\Services\Staff\TempPasswordChangeService;
use App\Support\DTO\CreateStaffInput;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }

    echo "PASS {$message}\n";
};

DB::beginTransaction();

try {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');
    Role::findOrCreate('user', 'web');

    $region = Region::query()->firstOrFail();
    $office1 = OfficeLocation::query()->where('region_id', $region->id)->first()
        ?? OfficeLocation::create([
            'region_id' => $region->id,
            'name' => 'E2E Office 1',
            'address' => 'addr',
            'location' => Point::makeGeodetic(32.8872, 13.1913),
            'is_active' => true,
        ]);

    $office2 = OfficeLocation::create([
        'region_id' => $region->id,
        'name' => 'E2E Office 2',
        'address' => 'addr',
        'location' => Point::makeGeodetic(32.9, 13.2),
        'is_active' => true,
    ]);

    $rootAdmin = User::create([
        'first_name' => 'Root',
        'last_name' => 'Admin',
        'phone_number' => '+218910'.random_int(100000, 999999),
        'password' => Hash::make('rootpass1'),
        'account_status' => AccountStatus::Active->value,
        'phone_verified_at' => now(),
    ]);
    $rootAdmin->assignRole('admin');

    $coAdmin = User::create([
        'first_name' => 'Co',
        'last_name' => 'Admin',
        'phone_number' => '+218910'.random_int(100000, 999999),
        'password' => Hash::make('copass1'),
        'account_status' => AccountStatus::Active->value,
        'phone_verified_at' => now(),
    ]);
    $coAdmin->assignRole('admin');

    $staffService = app(StaffService::class);
    $passwordChange = app(TempPasswordChangeService::class);

    echo "Scenario 1: admin creates admin, forced change\n";
    $created = $staffService->create(new CreateStaffInput(
        phoneNumber: '+218910'.random_int(100000, 999999),
        firstName: 'New',
        lastName: 'Admin',
        email: null,
        role: 'admin',
    ), $rootAdmin);
    $assert(is_string($created['temporary_password']), 'temporary password returned');
    $assert($created['user']->must_change_password === true, 'must_change_password flag set');
    $changed = $passwordChange->change($created['user'], $created['temporary_password'], 'newPass99X');
    $assert($changed['user']->must_change_password === false, 'flag cleared after change');
    $assert(is_string($changed['token']), 'new token issued');

    echo "Scenario 2: admin creates office_staff with 2 offices\n";
    $officeStaff = $staffService->create(new CreateStaffInput(
        phoneNumber: '+218910'.random_int(100000, 999999),
        firstName: 'Office',
        lastName: 'Worker',
        email: null,
        role: 'office_staff',
        officeAssignments: [
            ['office_id' => $office1->id, 'is_manager' => false],
            ['office_id' => $office2->id, 'is_manager' => true],
        ],
    ), $rootAdmin);
    $activeCount = $officeStaff['user']->activeOfficeAssignments()->count();
    $assert($activeCount === 2, "office_staff has 2 active assignments (got {$activeCount})");

    echo "Scenario 3: admin resets another admin's password\n";
    $coAdmin->createToken('old');
    $reset = $staffService->resetTempPassword($coAdmin, $rootAdmin);
    $assert($reset['user']->must_change_password === true, 'flag set after reset');
    $assert($coAdmin->fresh()->tokens()->count() === 0, 'tokens revoked');

    echo "Scenario 4: admin suspends office_staff\n";
    $suspended = $staffService->suspend($officeStaff['user'], $rootAdmin);
    $assert($suspended->account_status === AccountStatus::Suspended, 'office_staff suspended');

    echo "Scenario 5: admin deactivates office_staff\n";
    $reinstated = $staffService->reinstate($officeStaff['user'], $rootAdmin);
    $deactivated = $staffService->deactivate($reinstated, $rootAdmin);
    $assert($deactivated->account_status === AccountStatus::Suspended, 'deactivate returns suspended staff');
    $assert($deactivated->activeOfficeAssignments()->count() === 0, 'all assignments removed');

    echo "Scenario 6: guards against self-modify and last-admin\n";
    try {
        $staffService->suspend($rootAdmin, $rootAdmin);
        throw new RuntimeException('self-suspend should have thrown');
    } catch (Throwable $exception) {
        $assert(str_contains($exception->getMessage(), 'own account'), 'self-suspend rejected');
    }

    $staffService->suspend($coAdmin->fresh(), $rootAdmin);
    $staffService->suspend($created['user']->fresh(), $rootAdmin);
    $adminRole = Role::findByName('admin', 'web');
    $adminIds = DB::table('model_has_roles')
        ->where('role_id', $adminRole->id)
        ->where('model_type', User::class)
        ->pluck('model_id');
    User::query()
        ->whereIn('id', $adminIds)
        ->where('id', '!=', $rootAdmin->id)
        ->update(['account_status' => AccountStatus::Suspended->value]);

    try {
        $staffService->suspend($rootAdmin, $coAdmin->fresh());
        throw new RuntimeException('last-admin suspend should have thrown');
    } catch (Throwable $exception) {
        $assert(str_contains($exception->getMessage(), 'last active admin'), 'last-admin protected');
    }

    echo "\nALL STAFF E2E SMOKE SCENARIOS PASSED\n";
} finally {
    DB::rollBack();
}
