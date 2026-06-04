<?php

declare(strict_types=1);

namespace App\Services\Moderation;

use App\Enums\AccountStatus;
use App\Enums\DriverActivityStatus;
use App\Enums\ModerationAction;
use App\Enums\ModerationErrorCode;
use App\Enums\ModerationReason;
use App\Exceptions\Moderation\ModerationException;
use App\Models\AccountModerationAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sole authority for manual AccountStatus moderation (suspend/ban/reinstate)
 * for any user. Public methods open one transaction, lock + reload the target
 * row, run guards against that locked state, then write + audit — so guards
 * and the from_status snapshot can never act on a stale in-memory instance
 * (Critical Rule 3). StaffService reuses apply() directly (it owns its own
 * StaffErrorCode guards) and still benefits from the lock + correct snapshot.
 * See docs/superpowers/specs/2026-06-03-account-moderation-design.md.
 */
final class AccountModerationService
{
    public function suspend(User $target, User $actor, ModerationReason $reason, string $detail): User
    {
        return $this->moderate($target, $actor, ModerationAction::Suspend, $reason, $detail);
    }

    public function ban(User $target, User $actor, ModerationReason $reason, string $detail): User
    {
        return $this->moderate($target, $actor, ModerationAction::Ban, $reason, $detail);
    }

    public function reinstate(User $target, User $actor, ModerationReason $reason, string $detail): User
    {
        return $this->moderate($target, $actor, ModerationAction::Reinstate, $reason, $detail);
    }

    /**
     * Guarded moderation: lock the target row, validate against its real
     * (committed) state, then write + audit — all in one transaction.
     */
    private function moderate(User $target, User $actor, ModerationAction $action, ModerationReason $reason, string $detail): User
    {
        return DB::transaction(function () use ($target, $actor, $action, $reason, $detail): User {
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $this->assertNotSelf($locked, $actor);

            if ($action === ModerationAction::Suspend || $action === ModerationAction::Ban) {
                $this->assertNotLastActiveAdmin($locked);
            }

            $this->assertTransitionAllowed($locked->account_status, $action);

            $toStatus = $this->targetStatusFor($action, $locked);

            return $this->write($locked, $actor, $action, $toStatus, $reason, $detail);
        });
    }

    /**
     * Status write + token revoke + cascade + audit on a locked target row.
     * NO guards/transition enforcement — callers that own their own guards
     * (e.g. StaffService) use this directly; it still locks + reloads so the
     * from_status snapshot is the committed truth.
     */
    public function apply(
        User $target,
        User $actor,
        ModerationAction $action,
        AccountStatus $toStatus,
        ModerationReason $reason,
        string $detail,
    ): User {
        return DB::transaction(function () use ($target, $actor, $action, $toStatus, $reason, $detail): User {
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            return $this->write($locked, $actor, $action, $toStatus, $reason, $detail);
        });
    }

    /**
     * Inner writer. Assumes $target is already locked inside a transaction.
     */
    private function write(
        User $target,
        User $actor,
        ModerationAction $action,
        AccountStatus $toStatus,
        ModerationReason $reason,
        string $detail,
    ): User {
        $fromStatus = $target->account_status;

        $target->forceFill(['account_status' => $toStatus->value])->save();

        if ($action !== ModerationAction::Reinstate) {
            $target->tokens()->delete();
            $this->cascade($target);
        }

        AccountModerationAction::create([
            'user_id' => $target->id,
            'actor_id' => $actor->id,
            'action' => $action->value,
            'reason_code' => $reason->value,
            'detail' => $detail,
            'from_status' => $fromStatus->value,
            'to_status' => $toStatus->value,
        ]);

        return $target->fresh();
    }

    private function targetStatusFor(ModerationAction $action, User $locked): AccountStatus
    {
        return match ($action) {
            ModerationAction::Suspend => AccountStatus::Suspended,
            ModerationAction::Ban => AccountStatus::Banned,
            ModerationAction::Reinstate => $locked->hasOutstandingFees()
                ? AccountStatus::SuspendedUnpaidFees
                : AccountStatus::Active,
        };
    }

    /**
     * Targeted cascade: drivers are forced offline so BroadcastService's
     * eligibleDriversFor (which filters activity_status = online) drops them.
     * The operational DriverStatus axis is intentionally NOT touched, and a
     * live delivery is left for support — never auto-cancelled.
     */
    private function cascade(User $target): void
    {
        $profile = $target->driverProfile;

        if ($profile !== null && $profile->activity_status !== DriverActivityStatus::Offline) {
            $profile->forceFill(['activity_status' => DriverActivityStatus::Offline->value])->save();
        }
    }

    private function assertNotSelf(User $target, User $actor): void
    {
        if ($target->id === $actor->id) {
            throw new ModerationException(
                ModerationErrorCode::CannotModerateSelf,
                'You cannot moderate your own account.',
            );
        }
    }

    private function assertNotLastActiveAdmin(User $target): void
    {
        if (! $target->hasRole('admin')) {
            return;
        }

        // Lock the other active-admin rows (FOR UPDATE) before counting so a
        // concurrent suspension of a different admin serialises against this
        // one and the last-admin invariant holds. (FOR UPDATE can't combine
        // with COUNT in Postgres, so lock-then-count in PHP.)
        $otherActiveAdmins = User::query()
            ->role('admin')
            ->where('account_status', AccountStatus::Active->value)
            ->where('id', '!=', $target->id)
            ->lockForUpdate()
            ->pluck('id')
            ->count();

        if ($otherActiveAdmins < 1) {
            throw new ModerationException(
                ModerationErrorCode::LastActiveAdmin,
                'Cannot suspend or ban the last active admin.',
            );
        }
    }

    private function assertTransitionAllowed(AccountStatus $from, ModerationAction $action): void
    {
        $allowed = match ($action) {
            ModerationAction::Suspend => in_array($from, [AccountStatus::Active, AccountStatus::PendingVerification], true),
            ModerationAction::Ban => $from !== AccountStatus::Banned,
            ModerationAction::Reinstate => in_array($from, [AccountStatus::Suspended, AccountStatus::Banned], true),
        };

        if (! $allowed) {
            throw new ModerationException(
                ModerationErrorCode::InvalidTransition,
                "Cannot {$action->value} a user whose status is {$from->value}.",
            );
        }
    }
}
