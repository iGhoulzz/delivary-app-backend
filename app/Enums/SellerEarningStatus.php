<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a sale order's seller-side earning.
 */
enum SellerEarningStatus: string
{
    case PendingSettlement = 'pending_settlement';
    case PendingClearance = 'pending_clearance';
    case Available = 'available';
    case PaidOut = 'paid_out';

    public function label(): string
    {
        return match ($this) {
            self::PendingSettlement => 'Pending Settlement',
            self::PendingClearance => 'Pending Clearance',
            self::Available => 'Available',
            self::PaidOut => 'Paid Out',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::PaidOut;
    }
}
