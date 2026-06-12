<?php

declare(strict_types=1);

namespace App\Enums;

enum MerchantErrorCode: string
{
    case UserNotFound = 'USER_NOT_FOUND';
    case AlreadyMerchant = 'ALREADY_MERCHANT';
    case AccountNotEligible = 'ACCOUNT_NOT_ELIGIBLE';
    case InvalidStatusTransition = 'INVALID_STATUS_TRANSITION';
    case MerchantNotActive = 'MERCHANT_NOT_ACTIVE';
    case MissingPickup = 'MISSING_PICKUP';

    public function httpStatus(): int
    {
        return match ($this) {
            self::UserNotFound => 404,
            self::MerchantNotActive => 403,
            self::AlreadyMerchant,
            self::AccountNotEligible,
            self::InvalidStatusTransition,
            self::MissingPickup => 422,
        };
    }
}
