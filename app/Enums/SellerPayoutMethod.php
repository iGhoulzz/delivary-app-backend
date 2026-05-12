<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Method by which a seller receives the cash from a payout request.
 *
 * Currently a single-case enum: sellers always claim cash physically at an
 * office. The enum is retained (rather than collapsed into a literal) so that
 * future methods (mobile money, etc.) can be added without a schema or call-
 * site refactor.
 */
enum SellerPayoutMethod: string
{
    case CashAtOffice = 'cash_at_office';

    public function label(): string
    {
        return match ($this) {
            self::CashAtOffice => 'Cash at Office',
        };
    }

    public function requiresOffice(): bool
    {
        return match ($this) {
            self::CashAtOffice => true,
        };
    }
}
