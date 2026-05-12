<?php

declare(strict_types=1);

namespace App\Enums;

enum DriverStrikeIssuer: string
{
    case System = 'system';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::System => 'System',
            self::Admin => 'Admin',
        };
    }
}
