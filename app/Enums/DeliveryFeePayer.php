<?php

declare(strict_types=1);

namespace App\Enums;

enum DeliveryFeePayer: string
{
    case Sender = 'sender';
    case Receiver = 'receiver';

    public function label(): string
    {
        return match ($this) {
            self::Sender => 'Sender',
            self::Receiver => 'Receiver',
        };
    }
}
