<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DriverAccount extends Model
{
    use HasFactory;

    /** @var array<int, string> */
    protected $fillable = [
        'driver_id',
        'cash_to_deposit', 'earnings_balance', 'debt_balance',
        'max_cash_liability',
        'lifetime_earnings', 'lifetime_cash_handled', 'lifetime_platform_fees_paid',
    ];

    protected function casts(): array
    {
        return [
            'cash_to_deposit' => 'decimal:2',
            'earnings_balance' => 'decimal:2',
            'debt_balance' => 'decimal:2',
            'max_cash_liability' => 'decimal:2',
            'lifetime_earnings' => 'decimal:2',
            'lifetime_cash_handled' => 'decimal:2',
            'lifetime_platform_fees_paid' => 'decimal:2',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(DriverAccountTransaction::class, 'driver_id', 'driver_id');
    }

    /**
     * Driver-dashboard net (spec section 4.7): earnings minus debt.
     * Excludes cash_to_deposit because that's a physical asset the driver
     * is holding for the platform — not money the platform owes them.
     *
     * Positive = platform owes driver. Negative = driver owes platform (fines).
     */
    protected function netPosition(): Attribute
    {
        return Attribute::get(fn (): string => bcsub(
            (string) $this->earnings_balance,
            (string) $this->debt_balance,
            2
        ));
    }

    /**
     * Settlement net (spec section 11.3): the single cash movement that
     * resolves all three buckets at office settlement.
     *
     * `(cash_to_deposit + debt_balance) - earnings_balance`
     *
     * Positive = driver hands cash to platform.
     * Negative = platform pays driver.
     * Zero = balances cancel out, no cash changes hands.
     */
    protected function settlementNet(): Attribute
    {
        return Attribute::get(fn (): string => bcsub(
            bcadd((string) $this->cash_to_deposit, (string) $this->debt_balance, 2),
            (string) $this->earnings_balance,
            2
        ));
    }

    /**
     * Headroom remaining before the driver hits their cash liability ceiling.
     * Drivers at or above the ceiling cannot accept new cash orders.
     */
    protected function remainingLiability(): Attribute
    {
        return Attribute::get(fn (): string => bcsub(
            (string) $this->max_cash_liability,
            (string) $this->cash_to_deposit,
            2
        ));
    }

    public function isAtLiabilityCeiling(): bool
    {
        return bccomp((string) $this->cash_to_deposit, (string) $this->max_cash_liability, 2) >= 0;
    }

    public function canHoldAdditionalCash(string|float $amount): bool
    {
        $projected = bcadd((string) $this->cash_to_deposit, (string) $amount, 2);

        return bccomp($projected, (string) $this->max_cash_liability, 2) <= 0;
    }
}
