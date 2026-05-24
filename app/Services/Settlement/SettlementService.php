<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Enums\DriverAccountBucket;
use App\Enums\DriverAccountTransactionReason;
use App\Enums\SellerEarningStatus;
use App\Enums\SettlementStatus;
use App\Events\DriverAccountUpdated;
use App\Exceptions\Settlement\EmptySettlementException;
use App\Exceptions\Settlement\SettlementExcessException;
use App\Models\DriverAccount;
use App\Models\DriverAccountTransaction;
use App\Models\OfficeLocation;
use App\Models\SellerEarning;
use App\Models\Settlement;
use App\Models\SettlementOrder;
use App\Models\User;
use App\ValueObjects\SettlementPreview;
use Illuminate\Support\Facades\DB;

/**
 * Sole writer of `settlements`, `settlement_orders`, and the related
 * `driver_account_transactions` rows. Atomic per spec §3 + §11 — one
 * lockForUpdate on driver_accounts gates every state mutation.
 *
 * Flow:
 *   1. Snapshot the three buckets.
 *   2. Reject empty buckets (EmptySettlementException → 422).
 *   3. Compute expected net = cash + debt − earnings; compare with actual.
 *   4. Reject excess (SettlementExcessException → 422). Excess must be
 *      physically handed back at the counter before re-submitting.
 *   5. Write the Settlement row + driver_account_transactions per bucket.
 *   6. Apply shortage (if any) → debt_balance with SettlementShortage reason.
 *   7. Flip this driver's pending_settlement seller_earnings to
 *      pending_clearance + stamp cleared_at + write settlement_orders pivot.
 */
final class SettlementService
{
    public function preview(User $driver): SettlementPreview
    {
        $account = DriverAccount::query()
            ->where('driver_id', $driver->id)
            ->firstOrFail();

        $expectedNet = bcsub(
            bcadd((string) $account->cash_to_deposit, (string) $account->debt_balance, 2),
            (string) $account->earnings_balance,
            2,
        );

        $pendingEarnings = SellerEarning::query()
            ->pendingSettlementForDriver($driver->id)
            ->with('order:id,public_id,item_description,item_price,commission_amount')
            ->get();

        return new SettlementPreview(
            cashToDeposit: (string) $account->cash_to_deposit,
            earningsBalance: (string) $account->earnings_balance,
            debtBalance: (string) $account->debt_balance,
            expectedNet: $expectedNet,
            pendingEarnings: $pendingEarnings,
        );
    }

    public function process(
        User $driver,
        User $staff,
        OfficeLocation $office,
        string $cashReceivedFromDriver,
        string $cashPaidToDriver,
        ?string $notes = null,
    ): Settlement {
        return DB::transaction(function () use (
            $driver,
            $staff,
            $office,
            $cashReceivedFromDriver,
            $cashPaidToDriver,
            $notes,
        ): Settlement {
            $account = DriverAccount::query()
                ->where('driver_id', $driver->id)
                ->lockForUpdate()
                ->firstOrFail();

            $cashSnapshot = (string) $account->cash_to_deposit;
            $earningsSnapshot = (string) $account->earnings_balance;
            $debtSnapshot = (string) $account->debt_balance;

            // Reject if every bucket is zero — nothing to settle.
            if (
                bccomp($cashSnapshot, '0.00', 2) === 0
                && bccomp($earningsSnapshot, '0.00', 2) === 0
                && bccomp($debtSnapshot, '0.00', 2) === 0
            ) {
                throw new EmptySettlementException;
            }

            $expectedNet = bcsub(
                bcadd($cashSnapshot, $debtSnapshot, 2),
                $earningsSnapshot,
                2,
            );
            $actualNet = bcsub($cashReceivedFromDriver, $cashPaidToDriver, 2);

            // Excess (driver handed more than owed) → must be handed back at counter, not held in DB.
            if (bccomp($actualNet, $expectedNet, 2) === 1) {
                throw new SettlementExcessException($expectedNet, $actualNet);
            }

            $shortage = bccomp($actualNet, $expectedNet, 2) === -1
                ? bcsub($expectedNet, $actualNet, 2)
                : '0.00';

            $settlement = Settlement::create([
                'driver_id' => $driver->id,
                'office_id' => $office->id,
                'processed_by_staff_id' => $staff->id,
                'cash_received_from_driver' => $cashReceivedFromDriver,
                'cash_paid_to_driver' => $cashPaidToDriver,
                'cash_to_deposit_cleared' => $cashSnapshot,
                'earnings_balance_cleared' => $earningsSnapshot,
                'debt_balance_cleared' => $debtSnapshot,
                'shortage_amount' => $shortage,
                'excess_amount' => '0.00',
                'status' => SettlementStatus::Completed->value,
                'notes' => $notes,
            ]);

            // Mutate driver_account buckets BEFORE writing transactions so balance_after is correct.
            $account->cash_to_deposit = '0.00';
            $account->earnings_balance = '0.00';
            $account->debt_balance = $shortage;
            $account->lifetime_cash_handled = bcadd(
                (string) $account->lifetime_cash_handled,
                $cashReceivedFromDriver,
                2,
            );
            $account->save();

            // One driver_account_transactions row per non-zero bucket clear.
            $this->writeBucketTransaction(
                $account,
                DriverAccountBucket::CashToDeposit,
                bcmul($cashSnapshot, '-1', 2),
                '0.00',
                DriverAccountTransactionReason::Settlement,
                $settlement,
            );
            $this->writeBucketTransaction(
                $account,
                DriverAccountBucket::EarningsBalance,
                bcmul($earningsSnapshot, '-1', 2),
                '0.00',
                DriverAccountTransactionReason::Settlement,
                $settlement,
            );
            $this->writeBucketTransaction(
                $account,
                DriverAccountBucket::DebtBalance,
                bcmul($debtSnapshot, '-1', 2),
                '0.00',
                DriverAccountTransactionReason::Settlement,
                $settlement,
            );

            // Shortage (if any) → push to debt_balance with distinct reason for audit.
            if (bccomp($shortage, '0.00', 2) === 1) {
                $this->writeBucketTransaction(
                    $account,
                    DriverAccountBucket::DebtBalance,
                    $shortage,
                    $shortage,
                    DriverAccountTransactionReason::SettlementShortage,
                    $settlement,
                );
            }

            // Flip this driver's pending_settlement earnings to pending_clearance.
            $earnings = SellerEarning::query()
                ->pendingSettlementForDriver($driver->id)
                ->lockForUpdate()
                ->get();

            $now = now();
            foreach ($earnings as $earning) {
                $earning->status = SellerEarningStatus::PendingClearance->value;
                $earning->cleared_at = $now;
                $earning->save();

                SettlementOrder::create([
                    'settlement_id' => $settlement->id,
                    'order_id' => $earning->order_id,
                    'amount_contributed' => (string) $earning->amount,
                ]);
            }

            event(new DriverAccountUpdated($account->refresh()));

            return $settlement->fresh(['driver', 'office', 'processedByStaff', 'orders']);
        });
    }

    private function writeBucketTransaction(
        DriverAccount $account,
        DriverAccountBucket $bucket,
        string $amount,
        string $balanceAfter,
        DriverAccountTransactionReason $reason,
        Settlement $reference,
    ): void {
        if (bccomp($amount, '0.00', 2) === 0) {
            return;
        }

        DriverAccountTransaction::create([
            'driver_id' => $account->driver_id,
            'bucket' => $bucket->value,
            'amount' => $amount,
            'reason' => $reason->value,
            'reference_type' => $reference::class,
            'reference_id' => $reference->getKey(),
            'balance_after' => $balanceAfter,
        ]);
    }
}
