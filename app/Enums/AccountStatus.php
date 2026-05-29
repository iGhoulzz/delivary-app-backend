<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case PendingVerification = 'pending_verification';
    case Suspended = 'suspended';
    case SuspendedUnpaidFees = 'suspended_unpaid_fees';
    case Banned = 'banned';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::PendingVerification => 'Pending Verification',
            self::Suspended => 'Suspended',
            self::SuspendedUnpaidFees => 'Suspended (Unpaid Fees)',
            self::Banned => 'Banned',
        };
    }

    public function canLogin(): bool
    {
        return $this === self::Active || $this === self::PendingVerification;
    }

    public function canCreateOrders(): bool
    {
        return $this === self::Active;
    }

    public function canWithdraw(): bool
    {
        return $this === self::Active;
    }

    public function isSuspended(): bool
    {
        return in_array($this, [self::Suspended, self::SuspendedUnpaidFees], true);
    }
}
