<?php

declare(strict_types=1);

namespace App\Enums;

enum StaffErrorCode: string
{
    case CannotSelfModify = 'CANNOT_SELF_MODIFY';
    case LastAdminProtected = 'LAST_ADMIN_PROTECTED';
    case TempPasswordMismatch = 'TEMP_PASSWORD_MISMATCH';
    case NewPasswordSameAsTemp = 'NEW_PASSWORD_SAME_AS_TEMP';

    public function httpStatus(): int
    {
        return match ($this) {
            self::CannotSelfModify, self::LastAdminProtected, self::NewPasswordSameAsTemp => 422,
            self::TempPasswordMismatch => 401,
        };
    }
}
