<?php

declare(strict_types=1);

namespace App\Enums;

enum DeliveryFeePaymentMethod: string
{
    case Cash = 'cash';
    case Wallet = 'wallet'; // architecture-ready, inactive in MVP

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Wallet => 'Wallet',
        };
    }
}
