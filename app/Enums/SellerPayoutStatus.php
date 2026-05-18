<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of a seller payout receipt.
 */
enum SellerPayoutStatus: string
{
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isFinal(): bool
    {
        return true;
    }
}
