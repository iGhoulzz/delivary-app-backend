<?php

declare(strict_types=1);

use App\Enums\MerchantErrorCode;

it('maps every merchant error code to an http status', function () {
    expect(MerchantErrorCode::UserNotFound->httpStatus())->toBe(404)
        ->and(MerchantErrorCode::AlreadyMerchant->httpStatus())->toBe(422)
        ->and(MerchantErrorCode::AccountNotEligible->httpStatus())->toBe(422)
        ->and(MerchantErrorCode::InvalidStatusTransition->httpStatus())->toBe(422)
        ->and(MerchantErrorCode::MerchantNotActive->httpStatus())->toBe(403)
        ->and(MerchantErrorCode::MissingPickup->httpStatus())->toBe(422);
});
