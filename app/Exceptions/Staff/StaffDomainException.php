<?php

declare(strict_types=1);

namespace App\Exceptions\Staff;

use App\Enums\StaffErrorCode;
use RuntimeException;

final class StaffDomainException extends RuntimeException
{
    public function __construct(
        public readonly StaffErrorCode $errorCode,
        string $message,
        /** @var array<string, mixed> */
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
