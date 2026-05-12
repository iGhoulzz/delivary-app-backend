<?php

declare(strict_types=1);

namespace App\Exceptions\Order;

use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;

final class InvalidOrderTransitionException extends OrderDomainException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        parent::__construct(
            errorCode: OrderErrorCode::InvalidStateTransition,
            message: sprintf('Cannot transition from %s to %s.', $from->value, $to->value),
            details: ['from' => $from->value, 'to' => $to->value],
        );
    }
}
