<?php

declare(strict_types=1);

namespace App\Enums;

enum DeliveryMethod: string
{
    case Code = 'code';
    case AdminOverride = 'admin_override';
    case Bypassed = 'bypassed'; // platform-wide codes.enforce_delivery = false

    public function label(): string
    {
        return match ($this) {
            self::Code => 'Code',
            self::AdminOverride => 'Admin Override',
            self::Bypassed => 'Bypassed (codes disabled)',
        };
    }
}
