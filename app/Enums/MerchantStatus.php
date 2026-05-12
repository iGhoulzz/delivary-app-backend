<?php

declare(strict_types=1);

namespace App\Enums;

enum MerchantStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Banned = 'banned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Banned => 'Banned',
        };
    }

    public function canCreateOrders(): bool
    {
        return $this === self::Active;
    }
}
