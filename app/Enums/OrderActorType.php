<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderActorType: string
{
    case User = 'user';
    case Driver = 'driver';
    case Admin = 'admin';
    case System = 'system';
    case OfficeStaff = 'office_staff';

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Driver => 'Driver',
            self::Admin => 'Admin',
            self::System => 'System',
            self::OfficeStaff => 'Office Staff',
        };
    }
}
