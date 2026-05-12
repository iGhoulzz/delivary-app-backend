<?php

declare(strict_types=1);

namespace App\Enums;

enum DriverErrorCode: string
{
    case DriverProfileExists = 'driver_profile_exists';
    case WrongOffice = 'wrong_office';
    case InvalidState = 'invalid_state';
    case MissingDocuments = 'missing_documents';
    case PhoneNotVerified = 'phone_not_verified';
    case LockedPostSubmission = 'locked_post_submission';
    case OutsideServiceArea = 'outside_service_area';
    case OtpInvalid = 'otp_invalid';
    case ValidationFailed = 'validation_failed';

    public function httpStatus(): int
    {
        return match ($this) {
            self::DriverProfileExists, self::InvalidState => 409,
            self::WrongOffice, self::LockedPostSubmission => 403,
            self::MissingDocuments, self::PhoneNotVerified,
            self::OutsideServiceArea, self::OtpInvalid,
            self::ValidationFailed => 422,
        };
    }
}
