<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The external payment gateway responsible for issuing/honouring a saved
 * payment method or processing a top-up. Architecture-ready; inactive at MVP.
 *
 * Plutu is the primary candidate for Libyan card processing. Adding new
 * providers is a deliberate decision (code change), not user-supplied data,
 * so an enum is appropriate over a free-form string.
 */
enum PaymentMethodProvider: string
{
    case Plutu = 'plutu';

    public function label(): string
    {
        return match ($this) {
            self::Plutu => 'Plutu',
        };
    }
}
