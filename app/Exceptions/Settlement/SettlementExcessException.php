<?php

declare(strict_types=1);

namespace App\Exceptions\Settlement;

use App\Enums\SettlementErrorCode;
use RuntimeException;

final class SettlementExcessException extends RuntimeException
{
    public function __construct(
        public readonly string $expectedNet,
        public readonly string $actualNet,
    ) {
        parent::__construct(sprintf(
            'Settlement excess rejected: actual net %s exceeds expected %s. Hand excess back before submitting.',
            $actualNet,
            $expectedNet,
        ));
    }

    public function errorCode(): SettlementErrorCode
    {
        return SettlementErrorCode::SettlementExcessRejected;
    }
}
