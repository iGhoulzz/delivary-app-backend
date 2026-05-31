<?php

declare(strict_types=1);

namespace App\Enums;

enum StaffErrorCode: string
{
    case CannotSelfModify = 'CANNOT_SELF_MODIFY';
    case LastAdminProtected = 'LAST_ADMIN_PROTECTED';
    case TempPasswordMismatch = 'TEMP_PASSWORD_MISMATCH';
    case NewPasswordSameAsTemp = 'NEW_PASSWORD_SAME_AS_TEMP';
    case RoleMismatchForOfficeAssign = 'ROLE_MISMATCH_FOR_OFFICE_ASSIGN';
    case OfficeAssignmentDuplicate = 'OFFICE_ASSIGNMENT_DUPLICATE';
    case OfficeAssignmentLastRequired = 'OFFICE_ASSIGNMENT_LAST_REQUIRED';

    public function httpStatus(): int
    {
        return match ($this) {
            self::CannotSelfModify,
            self::LastAdminProtected,
            self::NewPasswordSameAsTemp,
            self::RoleMismatchForOfficeAssign,
            self::OfficeAssignmentLastRequired => 422,
            self::OfficeAssignmentDuplicate => 409,
            self::TempPasswordMismatch => 401,
        };
    }
}
