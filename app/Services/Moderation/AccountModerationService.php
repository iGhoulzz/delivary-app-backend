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
 * for any user. Public methods enforce guards + transitions then delegate to
 * apply(); StaffService reuses apply() directly (it owns its own guards).
 * See docs/superpowers/specs/2026-06-03-account-moderation-design.md.
 */
final class AccountModerationService
{
    public function suspend(User $target, User $actor, ModerationReason $reason, string $detail): User
    {
        $this->assertNotSelf($target, $actor);
        $this->assertNotLastActiveAdmin($target);
        $this->assertTransitionAllowed($target->account_status, ModerationAction::Suspend);

        return $this->apply($target, $actor, ModerationAction::Suspend, AccountStatus::Suspended, $reason, $detail);
    }

    public function ban(User $target, User $actor, ModerationReason $reason, string $detail): User
    {
        $this->assertNotSelf($target, $actor);
        $this->assertNotLastActiveAdmin($target);
        $this->assertTransitionAllowed($target->account_status, ModerationAction::Ban);

        return $this->apply($target, $actor, ModerationAction::Ban, AccountStatus::Banned, $reason, $detail);
    }

    public function reinstate(User $target, User $actor, ModerationReason $reason, string $detail): User
    {
        $this->assertNotSelf($target, $actor);
        $this->assertTransitionAllowed($target->account_status, ModerationAction::Reinstate);

        $to = $target->hasOutstandingFees()
            ? AccountStatus::SuspendedUnpaidFees
            : AccountStatus::Active;

        return $this->apply($target, $actor, ModerationAction::Reinstate, $to, $reason, $detail);
    }

    /**
     * Status write + token revoke + cascade + audit. NO guards/transition
     * enforcement — callers that own their own guards (e.g. StaffService) use
     * this directly. Public moderation methods call it after guarding.
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
        });
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

        $otherActiveAdmins = User::query()
            ->role('admin')
            ->where('account_status', AccountStatus::Active->value)
            ->where('id', '!=', $target->id)
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
