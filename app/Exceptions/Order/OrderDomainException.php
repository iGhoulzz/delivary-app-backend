<?php

declare(strict_types=1);

namespace App\Exceptions\Order;

use App\Enums\OrderErrorCode;
use RuntimeException;

class OrderDomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly OrderErrorCode $errorCode,
        string $message = '',
        public readonly array $details = [],
    ) {
        parent::__construct($message !== '' ? $message : $errorCode->value);
    }

    public function httpStatus(): int
    {
        return $this->errorCode->httpStatus();
    }

    /**
     * @return array{error: array{code: string, message: string, details?: array<string, mixed>}}
     */
    public function toResponse(): array
    {
        $payload = [
            'error' => [
                'code' => strtoupper($this->errorCode->value),
                'message' => $this->getMessage(),
            ],
        ];
        if ($this->details !== []) {
            $payload['error']['details'] = $this->details;
        }

        return $payload;
    }
}
