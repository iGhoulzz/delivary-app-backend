<?php

declare(strict_types=1);

namespace App\Exceptions\Merchant;

use App\Enums\MerchantErrorCode;
use RuntimeException;

final class MerchantException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly MerchantErrorCode $errorCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->errorCode->httpStatus();
    }

    /** @return array<string, mixed> */
    public function toResponse(): array
    {
        return [
            'error' => $this->errorCode->value,
            'message' => $this->getMessage(),
            'context' => $this->context,
        ];
    }
}
