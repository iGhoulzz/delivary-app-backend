<?php

declare(strict_types=1);

namespace App\Enums;

enum OtpPurpose: string
{
    case Registration = 'registration';
    case PasswordReset = 'password_reset';

    public function cacheKeyFor(string $phone): string
    {
        return "otp:{$this->value}:{$phone}";
    }

    public function smsTemplate(): string
    {
        return match ($this) {
            self::Registration => 'Your verification code is :code. It expires in 5 minutes.',
            self::PasswordReset => 'Your password reset code is :code. It expires in 5 minutes.',
        };
    }
}
