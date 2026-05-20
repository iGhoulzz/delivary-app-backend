<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Enums\DriverAccountBucket;
use App\Enums\DriverAccountTransactionReason;
use App\Enums\SellerEarningStatus;
use App\Enums\SettlementErrorCode;
use App\Enums\SettlementStatus;
use App\Exceptions\Settlement\SettlementNotReversibleException;
use App\Models\DriverAccount;
use App\Models\DriverAccountTransaction;
use App\Models\PlatformSetting;
use App\Models\SellerEarning;
use App\Models\Settlement;
use App\Models\SettlementOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only correcting-settlement service per spec §6.3 + locked decision #9.
 *
 * Reversal is permitted ONLY while every contributing earning is still
 * `pending_clearance`. Once any earning is `available` or `paid_out`, the
 * seller may have withdrawn — reversal becomes a support case, not software.
 *
 * The original Settlement row is immutable in its facts; this service flips
 * its status to Cancelled with a cross-reference note and inserts a NEW
 * Settlement row carrying the opposite cash + bucket movements.
 */
final class SettlementReversalService
{
    public function reverse(Settlement $original, User $admin, string $reason): Settlement
    {
        return DB::transaction(function () use ($original, $admin, $reason): Settlement {
            $original = Settlement::query()->lockForUpdate()->findOrFail($original->id);

            if ($original->status !== SettlementStatus::Completed) {
                throw new SettlementNotReversibleException(
                    SettlementErrorCode::SettlementAlreadyReversed,
                    "Settlement {$original->public_id} is not in Completed status (current: {$original->status->value}).",
                );
            }

            // Optional hard time-cap (platform setting).
            $windowHoursRaw = PlatformSetting::get('settlement.reverse_window_hours');
            if ($windowHoursRaw !== null && $windowHoursRaw !== '' && (int) $windowHoursRaw > 0) {
                $windowHours = (int) $windowHoursRaw;
                if ($original->created_at !== null && $original->created_at->diffInHours(now()) > $windowHours) {
                    throw new SettlementNotReversibleException(
                        SettlementErrorCode::SettlementNotReversible,
                        "Reversal window of {$windowHours}h has elapsed.",
                    );
                }
            }

            $contributingOrderIds = SettlementOrder::query()
                ->where('settlement_id', $original->id)
                ->pluck('order_id')
                ->all();

            $earnings = SellerEarning::query()
                ->whereIn('order_id', $contributingOrderIds)
                ->lockForUpdate()
                ->get();

            foreach ($earnings as $earning) {
                if ($earning->status !== SellerEarningStatus::PendingClearance) {
                    throw new SettlementNotReversibleException(
                        SettlementErrorCode::SettlementNotReversible,
                        "Earning {$earning->public_id} has progressed to {$earning->status->value}; reversal blocked.",
                    );
                }
            }

            $account = DriverAccount::query()
                ->where('driver_id', $original->driver_id)
                ->lockForUpdate()
                ->firstOrFail();

            $correcting = Settlement::create([
                'driver_id' => $original->driver_id,
                'office_id' => $original->office_id,
                'processed_by_staff_id' => $admin->id,
                'cash_received_from_driver' => (string) $original->cash_paid_to_driver,
                'cash_paid_to_driver' => (string) $original->cash_received_from_driver,
                'cash_to_deposit_cleared' => bcmul((string) $original->cash_to_deposit_cleared, '-1', 2),
                'earnings_balance_cleared' => bcmul((string) $original->earnings_balance_cleared, '-1', 2),
                'debt_balance_cleared' => bcmul((string) $original->debt_balance_cleared, '-1', 2),
                'shortage_amount' => '0.00',
                'excess_amount' => '0.00',
                'status' => SettlementStatus::Completed->value,
                'notes' => "Reversal of {$original->public_id}: {$reason}",
            ]);

            $cashRestore = (string) $original->cash_to_deposit_cleared;
            $earningsRestore = (string) $original->earnings_balance_cleared;
            $debtRestore = (string) $original->debt_balance_cleared;
            $shortageRestore = (string) $original->shortage_amount;

            $account->cash_to_deposit = bcadd((string) $account->cash_to_deposit, $cashRestore, 2);
            $account->earnings_balance = bcadd((string) $account->earnings_balance, $earningsRestore, 2);
            // Original settlement cleared $debtRestore from debt and pushed $shortageRestore back into it.
            // To reverse: add back the cleared portion (+debtRestore) and remove the shortage (-shortageRestore).
            $account->debt_balance = bcadd(
                bcsub((string) $account->debt_balance, $shortageRestore, 2),
                $debtRestore,
                2,
            );
            // Defense-in-depth: clamp at zero (Critical Rule 5 — no negative balances).
            if (bccomp((string) $account->debt_balance, '0.00', 2) === -1) {
                $account->debt_balance = '0.00';
            }
            $account->save();

            $this->writeReversalTx(
                $account,
                DriverAccountBucket::CashToDeposit,
                $cashRestore,
                $correcting,
                $admin->id,
            );
            $this->writeReversalTx(
                $account,
                DriverAccountBucket::EarningsBalance,
                $earningsRestore,
                $correcting,
                $admin->id,
            );
            $this->writeReversalTx(
                $account,
                DriverAccountBucket::DebtBalance,
                bcsub($debtRestore, $shortageRestore, 2),
                $correcting,
                $admin->id,
            );

            foreach ($earnings as $earning) {
                $earning->status = SellerEarningStatus::PendingSettlement->value;
                $earning->cleared_at = null;
                $earning->save();
            }

            // Pivot rows on the original settlement are intentionally preserved.
            // The original Settlement is flipped to Cancelled and cross-references
            // the correcting settlement; the rows remain as audit of what was
            // initially settled. Any consumer reading active settlement state
            // must filter by status.
            $original->status = SettlementStatus::Cancelled->value;
            $original->notes = trim(
                ($original->notes ?? '')
                . "\nReversed by {$correcting->public_id}: {$reason}",
            );
            $original->save();

            return $correcting->fresh(['driver', 'office', 'processedByStaff']);
        });
    }

    private function writeReversalTx(
        DriverAccount $account,
        DriverAccountBucket $bucket,
        string $amount,
        Settlement $reference,
        int $adminId,
    ): void {
        if (bccomp($amount, '0.00', 2) === 0) {
            return;
        }

        DriverAccountTransaction::create([
            'driver_id' => $account->driver_id,
            'bucket' => $bucket->value,
            'amount' => $amount,
            'reason' => DriverAccountTransactionReason::ManualAdjustment->value,
            'reference_type' => $reference::class,
            'reference_id' => $reference->getKey(),
            'balance_after' => (string) $account->{$bucket->value},
            'created_by_admin_id' => $adminId,
            'notes' => 'Settlement reversal',
        ]);
    }
}
