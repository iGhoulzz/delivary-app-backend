<?php

declare(strict_types=1);

namespace App\Enums;

enum SellerPayoutStatus: string
{
    case Pending = 'pending';     // submitted by seller, awaiting admin review
    case Approved = 'approved';   // admin approved, awaiting payout execution
    case Paid = 'paid';           // funds disbursed (cash given / bank transfer sent)
    case Rejected = 'rejected';   // admin rejected (insufficient funds, fraud, etc.)
    case Cancelled = 'cancelled'; // seller withdrew the request before approval

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Rejected, self::Cancelled], true);
    }

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }
}
