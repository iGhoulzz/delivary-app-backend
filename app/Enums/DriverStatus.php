<?php

declare(strict_types=1);

namespace App\Enums;

enum DriverStatus: string
{
    case PreRegistered = 'pre_registered';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Active = 'active';
    case Suspended = 'suspended';
    case Rejected = 'rejected';
    case Banned = 'banned';

    public function label(): string
    {
        return match ($this) {
            self::PreRegistered => 'Pre-Registered',
            self::PendingApproval => 'Pending Approval',
            self::Approved => 'Approved',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Rejected => 'Rejected',
            self::Banned => 'Banned',
        };
    }

    public function canAcceptOrders(): bool
    {
        return $this === self::Active;
    }

    public function canGoOnline(): bool
    {
        return $this === self::Active;
    }
}
