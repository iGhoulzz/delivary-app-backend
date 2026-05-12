<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Type of saved payment instrument the user has on file (architecture-ready,
 * inactive at MVP). Currently only `card` — the enum is kept for future
 * expansion (bank account, mobile money, etc.) without a schema change.
 */
enum PaymentMethodType: string
{
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Card',
        };
    }
}
