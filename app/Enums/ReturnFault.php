<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who is financially responsible for a failed delivery (spec section 6.2).
 * Determines who is billed for the delivery attempt and storage fees.
 */
enum ReturnFault: string
{
    case Sender = 'sender';
    case Receiver = 'receiver';
    case Driver = 'driver';
    case Platform = 'platform';

    public function label(): string
    {
        return match ($this) {
            self::Sender => 'Sender',
            self::Receiver => 'Receiver',
            self::Driver => 'Driver',
            self::Platform => 'Platform',
        };
    }
}
