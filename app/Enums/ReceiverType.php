<?php

declare(strict_types=1);

namespace App\Enums;

enum ReceiverType: string
{
    case RegisteredUser = 'registered_user';
    case Guest = 'guest';

    public function label(): string
    {
        return match ($this) {
            self::RegisteredUser => 'Registered User',
            self::Guest => 'Guest',
        };
    }
}
