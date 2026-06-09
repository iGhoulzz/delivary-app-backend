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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('driver', 'web');
    Role::findOrCreate('office_staff', 'web');
    Role::findOrCreate('user', 'web');

    $this->service = app(AccountModerationService::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    // Second admin so the last-active-admin guard never blocks our targets.
    User::factory()->create()->assignRole('admin');
});

it('suspends a customer so they can no longer log in', function (): void {
    $customer = User::factory()->create(['account_status' => AccountStatus::Active->value]);

    $this->service->suspend($customer, $this->admin, ModerationReason::Abuse, 'spamming');

    expect($customer->fresh()->account_status)->toBe(AccountStatus::Suspended);
    expect($customer->fresh()->account_status->canLogin())->toBeFalse();
});

it('bans an online driver — forces offline, leaves DriverStatus untouched', function (): void {
    $driver = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $driver->assignRole('driver');
    DriverProfile::factory()->create([
        'user_id' => $driver->id,
        'status' => DriverStatus::Active->value,
        'activity_status' => DriverActivityStatus::Online->value,
        'vehicle_type' => VehicleType::Car->value,
    ]);

    $this->service->ban($driver, $this->admin, ModerationReason::Fraud, 'fake orders');

    $profile = DriverProfile::query()->where('user_id', $driver->id)->first();
    expect($driver->fresh()->account_status)->toBe(AccountStatus::Banned);
    expect($profile->activity_status)->toBe(DriverActivityStatus::Offline);
    expect($profile->status)->toBe(DriverStatus::Active);
});

it('reinstates a debtor into suspended_unpaid_fees', function (): void {
    $debtor = User::factory()->create(['account_status' => AccountStatus::Suspended->value]);
    $debtor->assignRole('driver');
    DriverAccount::factory()->create(['driver_id' => $debtor->id, 'debt_balance' => '25.00']);

    $this->service->reinstate($debtor, $this->admin, ModerationReason::Other, 'partial appeal');

    expect($debtor->fresh()->account_status)->toBe(AccountStatus::SuspendedUnpaidFees);
});

it('reinstates a clean user to active', function (): void {
    $clean = User::factory()->create(['account_status' => AccountStatus::Suspended->value]);

    $this->service->reinstate($clean, $this->admin, ModerationReason::Other, 'cleared');

    expect($clean->fresh()->account_status)->toBe(AccountStatus::Active);
});

it('records an audit row with correct status snapshots', function (): void {
    $customer = User::factory()->create(['account_status' => AccountStatus::Active->value]);

    $this->service->suspend($customer, $this->admin, ModerationReason::Other, 'x');

    $row = AccountModerationAction::query()->where('user_id', $customer->id)->latest('id')->first();
    expect($row->from_status)->toBe(AccountStatus::Active);
    expect($row->to_status)->toBe(AccountStatus::Suspended);
});

it('audits a staff suspension routed through StaffService', function (): void {
    $staff = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $staff->assignRole('office_staff');

    app(StaffService::class)->suspend($staff, $this->admin);

    $this->assertDatabaseHas('account_moderation_actions', [
        'user_id' => $staff->id,
        'action' => 'suspend',
    ]);
});
