<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three buckets that compose a driver's financial state.
 * Each is always stored as a non-negative number; the sign of any
 * `driver_account_transactions.amount` row tells us whether that bucket
 * was credited or debited.
 */
enum DriverAccountBucket: string
{
    /** Cash physically held by the driver, owed to the platform. */
    case CashToDeposit = 'cash_to_deposit';

    /** Delivery fees the driver has earned, owed by the platform. */
    case EarningsBalance = 'earnings_balance';

    /** Debt the driver owes the platform (cancellation fees, shortages). */
    case DebtBalance = 'debt_balance';

    public function label(): string
    {
        return match ($this) {
            self::CashToDeposit => 'Cash to Deposit',
            self::EarningsBalance => 'Earnings Balance',
            self::DebtBalance => 'Debt Balance',
        };
    }
}
