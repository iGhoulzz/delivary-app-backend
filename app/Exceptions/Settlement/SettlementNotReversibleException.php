<?php

declare(strict_types=1);

namespace App\Exceptions\Settlement;

use App\Enums\SettlementErrorCode;
use RuntimeException;

final class SettlementNotReversibleException extends RuntimeException
{
    public function __construct(
        public readonly SettlementErrorCode $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): SettlementErrorCode
    {
        return $this->errorCode;
    }
}
