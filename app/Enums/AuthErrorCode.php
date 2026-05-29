<?php

declare(strict_types=1);

namespace App\Enums;

enum AuthErrorCode: string
{
    case InvalidCredentials = 'invalid_credentials';
    case PhoneNotVerified = 'phone_not_verified';
    case AccountNotLoginable = 'account_not_loginable';
    case TooManyAttempts = 'too_many_attempts';
    case OtpInvalid = 'otp_invalid';
    case OtpExpired = 'otp_expired';
    case ResetTokenInvalid = 'reset_token_invalid';
    case VerificationLinkInvalid = 'verification_link_invalid';
    case EmailNotVerified = 'email_not_verified';
    case AlreadyVerified = 'already_verified';
    case ValidationFailed = 'validation_failed';

    public function httpStatus(): int
    {
        return match ($this) {
            self::InvalidCredentials => 401,
            self::PhoneNotVerified, self::AccountNotLoginable,
            self::EmailNotVerified,
            self::AlreadyVerified, self::VerificationLinkInvalid => 403,
            self::TooManyAttempts => 429,
            self::OtpInvalid, self::OtpExpired,
            self::ResetTokenInvalid, self::ValidationFailed => 422,
        };
    }
}
