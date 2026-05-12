<?php

declare(strict_types=1);

namespace App\Exceptions\Order;

use App\Enums\OrderErrorCode;

final class QuoteMismatchException extends OrderDomainException
{
    /** @param  array<string, mixed>  $freshQuote */
    public function __construct(array $freshQuote)
    {
        parent::__construct(
            errorCode: OrderErrorCode::QuotePriceChanged,
            message: 'The price changed since you previewed it. Review the updated quote and confirm.',
            details: ['fresh_quote' => $freshQuote],
        );
    }
}
