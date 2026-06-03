<?php

declare(strict_types=1);

use App\Enums\ModerationErrorCode;

it('maps every error code to 422', function (): void {
    foreach (ModerationErrorCode::cases() as $code) {
        expect($code->httpStatus())->toBe(422);
    }
});
