<?php

declare(strict_types=1);

namespace App\Enums;

enum DriverActivityStatus: string
{
    case Offline = 'offline';
    case Online = 'online';
    case OnOrder = 'on_order';

    public function label(): string
    {
        return match ($this) {
            self::Offline => 'Offline',
            self::Online => 'Online',
            self::OnOrder => 'On Order',
        };
    }

    public function isAvailableForBroadcast(): bool
    {
        return $this === self::Online;
    }
}
