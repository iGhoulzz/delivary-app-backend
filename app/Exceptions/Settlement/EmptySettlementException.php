<?php

declare(strict_types=1);

namespace App\Exceptions\Settlement;

use App\Enums\SettlementErrorCode;
use RuntimeException;

final class EmptySettlementException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Driver has no balances to settle (all three buckets at zero).');
    }

    public function errorCode(): SettlementErrorCode
    {
        return SettlementErrorCode::SettlementEmpty;
    }
}
