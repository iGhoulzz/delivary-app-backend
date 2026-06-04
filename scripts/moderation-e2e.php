<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\ModerationReason;
use App\Enums\VehicleType;
use App\Models\AccountModerationAction;
use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Moderation\AccountModerationService;
use App\Services\Staff\StaffService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException('FAIL '.$message);
    }

    echo "PASS {$message}\n";
};

$phone = static fn (int $number): string => '+21894'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);

$makeUser = static function (string $firstName, string $phoneNumber, ?string $role): User {
    $user = User::create([
        'first_name' => $firstName,
        'last_name' => 'ModSmoke',
        'phone_number' => $phoneNumber,
        'password' => Hash::make('password'),
        'account_status' => AccountStatus::Active->value,
        'phone_verified_at' => now(),
    ]);

    if ($role !== null) {
        $user->assignRole($role);
    }

    return $user;
};

DB::beginTransaction();

try {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('driver', 'web');
    Role::findOrCreate('office_staff', 'web');
    Role::findOrCreate('user', 'web');

    $moderation = app(AccountModerationService::class);
    $admin = $makeUser('Admin', $phone(random_int(1, 99999)), 'admin');
    $makeUser('Admin2', $phone(random_int(1, 99999)), 'admin');

    echo "Scenario 1: suspend a customer blocks login\n";
    $customer = $makeUser('Cust', $phone(random_int(1, 99999)), 'user');
    $moderation->suspend($customer, $admin, ModerationReason::Abuse, 'spamming');
    $assert($customer->fresh()->account_status === AccountStatus::Suspended, 'customer suspended');
    $assert(! $customer->fresh()->account_status->canLogin(), 'suspended customer cannot login');

    echo "Scenario 2: ban an online driver forces offline, DriverStatus untouched\n";
    $driver = $makeUser('Drv', $phone(random_int(1, 99999)), 'driver');
    DriverProfile::create([
        'user_id' => $driver->id,
        'office_id' => null,
        'status' => DriverStatus::Active->value,
        'vehicle_type' => VehicleType::Car->value,
        'vehicle_plate' => 'MS-'.Str::upper(Str::random(4)),
        'vehicle_color' => 'white',
        'activity_status' => DriverActivityStatus::Online->value,
    ]);
    $moderation->ban($driver, $admin, ModerationReason::Fraud, 'fake orders');
    $profile = DriverProfile::where('user_id', $driver->id)->first();
    $assert($driver->fresh()->account_status === AccountStatus::Banned, 'driver banned');
    $assert($profile->activity_status === DriverActivityStatus::Offline, 'driver forced offline');
    $assert($profile->status === DriverStatus::Active, 'DriverStatus untouched');

    echo "Scenario 3: reinstate debtor lands on suspended_unpaid_fees\n";
    $debtor = $makeUser('Debt', $phone(random_int(1, 99999)), 'driver');
    DriverAccount::create([
        'driver_id' => $debtor->id,
        'cash_to_deposit' => '0.00',
        'earnings_balance' => '0.00',
        'debt_balance' => '25.00',
        'max_cash_liability' => '200.00',
    ]);
    $moderation->suspend($debtor, $admin, ModerationReason::NonPayment, 'owes money');
    $moderation->reinstate($debtor, $admin, ModerationReason::Other, 'partial appeal');
    $assert(
        $debtor->fresh()->account_status === AccountStatus::SuspendedUnpaidFees,
        'debtor reinstated to suspended_unpaid_fees',
    );

    echo "Scenario 4: reinstate clean user lands on active\n";
    $clean = $makeUser('Clean', $phone(random_int(1, 99999)), 'user');
    $moderation->suspend($clean, $admin, ModerationReason::Other, 'manual hold');
    $moderation->reinstate($clean, $admin, ModerationReason::Other, 'cleared');
    $assert($clean->fresh()->account_status === AccountStatus::Active, 'clean user reinstated to active');

    echo "Scenario 5: audit rows recorded with correct snapshots\n";
    $row = AccountModerationAction::where('user_id', $customer->id)->latest('id')->first();
    $assert($row !== null && $row->to_status === AccountStatus::Suspended, 'audit row written for suspend');
    $assert($row->from_status === AccountStatus::Active, 'audit from_status snapshot correct');

    echo "Scenario 6: staff suspend via StaffService is audited\n";
    $staff = $makeUser('Stf', $phone(random_int(1, 99999)), 'office_staff');
    app(StaffService::class)->suspend($staff, $admin);
    $staffRow = AccountModerationAction::where('user_id', $staff->id)->latest('id')->first();
    $assert($staffRow !== null && $staffRow->action->value === 'suspend', 'staff suspension audited');

    echo "\nALL MODERATION SMOKE SCENARIOS PASSED\n";
} finally {
    DB::rollBack();
}
