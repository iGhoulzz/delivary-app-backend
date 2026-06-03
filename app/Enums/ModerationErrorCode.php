<?php

declare(strict_types=1);

namespace App\Enums;

enum ModerationErrorCode: string
{
    case CannotModerateSelf = 'CANNOT_MODERATE_SELF';
    case LastActiveAdmin = 'LAST_ACTIVE_ADMIN';
    case InvalidTransition = 'INVALID_TRANSITION';

    public function httpStatus(): int
    {
        return match ($this) {
            self::CannotModerateSelf,
            self::LastActiveAdmin,
            self::InvalidTransition => 422,
        };
    }
}
