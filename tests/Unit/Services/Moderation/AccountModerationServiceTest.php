<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\ModerationAction;
use App\Enums\ModerationReason;
use App\Exceptions\Moderation\ModerationException;
use App\Models\AccountModerationAction;
use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Moderation\AccountModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('driver', 'web');
    $this->service = app(AccountModerationService::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('suspends an active user, revokes tokens, and writes an audit row', function (): void {
    $target = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $target->createToken('t');

    $result = $this->service->suspend($target, $this->admin, ModerationReason::Abuse, 'spam');

    expect($result->account_status)->toBe(AccountStatus::Suspended);
    expect($target->tokens()->count())->toBe(0);

    $row = AccountModerationAction::query()->latest('id')->first();
    expect($row->action)->toBe(ModerationAction::Suspend);
    expect($row->reason_code)->toBe(ModerationReason::Abuse);
    expect($row->from_status)->toBe(AccountStatus::Active);
    expect($row->to_status)->toBe(AccountStatus::Suspended);
    expect($row->user_id)->toBe($target->id);
    expect($row->actor_id)->toBe($this->admin->id);
});

it('bans a user from any non-banned status', function (): void {
    $target = User::factory()->create(['account_status' => AccountStatus::Suspended->value]);

    $result = $this->service->ban($target, $this->admin, ModerationReason::Fraud, 'chargebacks');

    expect($result->account_status)->toBe(AccountStatus::Banned);
});

it('forces an online driver offline on ban (cascade) without touching DriverStatus', function (): void {
    $driver = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $driver->assignRole('driver');
    DriverProfile::factory()->create([
        'user_id' => $driver->id,
        'status' => DriverStatus::Active->value,
        'activity_status' => DriverActivityStatus::Online->value,
    ]);

    $this->service->ban($driver, $this->admin, ModerationReason::Fraud, 'x');

    $profile = DriverProfile::query()->where('user_id', $driver->id)->first();
    expect($profile->activity_status)->toBe(DriverActivityStatus::Offline);
    expect($profile->status)->toBe(DriverStatus::Active);
});

it('reinstates a suspended user to active when no fees are owed', function (): void {
    $target = User::factory()->create(['account_status' => AccountStatus::Suspended->value]);

    $result = $this->service->reinstate($target, $this->admin, ModerationReason::Other, 'appeal upheld');

    expect($result->account_status)->toBe(AccountStatus::Active);
});

it('reinstates a debtor into suspended_unpaid_fees', function (): void {
    $target = User::factory()->create(['account_status' => AccountStatus::Banned->value]);
    DriverAccount::factory()->create(['driver_id' => $target->id, 'debt_balance' => '20.00']);

    $result = $this->service->reinstate($target, $this->admin, ModerationReason::Other, 'x');

    expect($result->account_status)->toBe(AccountStatus::SuspendedUnpaidFees);
});

it('rejects moderating yourself', function (): void {
    $this->service->suspend($this->admin, $this->admin, ModerationReason::Other, 'x');
})->throws(ModerationException::class);

it('rejects suspending the last active admin', function (): void {
    $actor = User::factory()->create(); // non-admin actor; $this->admin is the sole active admin
    $this->service->suspend($this->admin, $actor, ModerationReason::Other, 'x');
})->throws(ModerationException::class);

it('rejects no-op transitions (suspend an already-suspended user)', function (): void {
    $target = User::factory()->create(['account_status' => AccountStatus::Suspended->value]);
    $this->service->suspend($target, $this->admin, ModerationReason::Other, 'x');
})->throws(ModerationException::class);

it('guards against stale model state by locking and reloading the target row', function (): void {
    $target = User::factory()->create(['account_status' => AccountStatus::Active->value]);

    // Mutate the row directly so the in-memory instance stays stale (still Active).
    DB::table('users')->where('id', $target->id)->update(['account_status' => AccountStatus::Suspended->value]);
    expect($target->account_status)->toBe(AccountStatus::Active); // stale snapshot

    // Guard must run against the locked, freshly-loaded row (Suspended), not the
    // stale instance — so this is an invalid suspend->suspend transition.
    $this->service->suspend($target, $this->admin, ModerationReason::Other, 'x');
})->throws(ModerationException::class);

it('writes from_status from the locked row, not the stale instance', function (): void {
    $target = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    DB::table('users')->where('id', $target->id)->update(['account_status' => AccountStatus::Banned->value]);

    // Stale instance says Active; reinstate is valid from the real (Banned) row.
    $this->service->reinstate($target, $this->admin, ModerationReason::Other, 'appeal');

    $row = AccountModerationAction::query()->latest('id')->first();
    expect($row->from_status)->toBe(AccountStatus::Banned); // locked truth, not stale Active
});
