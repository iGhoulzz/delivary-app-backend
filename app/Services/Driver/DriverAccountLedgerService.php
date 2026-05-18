<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\DriverAccountBucket;
use App\Enums\DriverAccountTransactionReason;
use App\Models\DriverAccount;
use App\Models\DriverAccountTransaction;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class DriverAccountLedgerService
{
    public function applyDeliveryCompletionCredit(User $driver, Order $order): void
    {
        $earnings = bcsub((string) $order->delivery_fee, (string) $order->driver_fee_cut_amount, 2);
        if (bccomp($earnings, '0.00', 2) !== 1) {
            return;
        }

        $account = DriverAccount::query()
            ->where('driver_id', $driver->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (bccomp((string) $account->debt_balance, '0.00', 2) === 1) {
            $offset = bccomp((string) $account->debt_balance, $earnings, 2) === 1
                ? $earnings
                : (string) $account->debt_balance;

            $this->mutateBucket(
                $account,
                DriverAccountBucket::DebtBalance,
                bcmul($offset, '-1', 2),
                DriverAccountTransactionReason::DebtOffset,
                $order,
                null,
                null,
            );

            $earnings = bcsub($earnings, $offset, 2);
        }

        if (bccomp($earnings, '0.00', 2) === 1) {
            $this->mutateBucket(
                $account,
                DriverAccountBucket::EarningsBalance,
                $earnings,
                DriverAccountTransactionReason::OrderCompleted,
                $order,
                null,
                null,
            );
        }
    }

    public function applyFee(
        User $driver,
        string $amount,
        DriverAccountTransactionReason $reason,
        Model $reference,
        ?int $createdByAdminId = null,
        ?string $notes = null,
    ): void {
        if (bccomp($amount, '0.00', 2) !== 1) {
            return;
        }

        $account = DriverAccount::query()
            ->where('driver_id', $driver->id)
            ->lockForUpdate()
            ->firstOrFail();

        $remaining = $amount;

        if (bccomp((string) $account->earnings_balance, '0.00', 2) === 1) {
            $earningsDebit = bccomp((string) $account->earnings_balance, $remaining, 2) === 1
                ? $remaining
                : (string) $account->earnings_balance;

            $this->mutateBucket(
                $account,
                DriverAccountBucket::EarningsBalance,
                bcmul($earningsDebit, '-1', 2),
                $reason,
                $reference,
                $createdByAdminId,
                $notes,
            );

            $remaining = bcsub($remaining, $earningsDebit, 2);
        }

        if (bccomp($remaining, '0.00', 2) === 1) {
            $this->mutateBucket(
                $account,
                DriverAccountBucket::DebtBalance,
                $remaining,
                $reason,
                $reference,
                $createdByAdminId,
                $notes,
            );
        }
    }

    private function mutateBucket(
        DriverAccount $account,
        DriverAccountBucket $bucket,
        string $amount,
        DriverAccountTransactionReason $reason,
        Model $reference,
        ?int $createdByAdminId,
        ?string $notes,
    ): void {
        $column = $bucket->value;
        $account->{$column} = bcadd((string) $account->{$column}, $amount, 2);
        $account->save();

        DriverAccountTransaction::create([
            'driver_id' => $account->driver_id,
            'bucket' => $bucket->value,
            'amount' => $amount,
            'reason' => $reason->value,
            'reference_type' => $reference::class,
            'reference_id' => $reference->getKey(),
            'balance_after' => $account->{$column},
            'notes' => $notes,
            'created_by_admin_id' => $createdByAdminId,
        ]);
    }
}
