<?php

declare(strict_types=1);

namespace App\Enums;

enum PickupMethod: string
{
    case Code = 'code';
    case GeofenceConfirmation = 'geofence_confirmation';
    case AdminOverride = 'admin_override';
    case Bypassed = 'bypassed'; // platform-wide codes.enforce_pickup = false

    public function label(): string
    {
        return match ($this) {
            self::Code => 'Code',
            self::GeofenceConfirmation => 'Geofence Confirmation',
            self::AdminOverride => 'Admin Override',
            self::Bypassed => 'Bypassed (codes disabled)',
        };
    }
}
