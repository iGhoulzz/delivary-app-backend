<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stable, client-readable error codes for settlement and seller-payout endpoints.
 */
enum SettlementErrorCode: string
{
    case SettlementExcessRejected = 'SETTLEMENT_EXCESS_REJECTED';
    case SettlementEmpty = 'SETTLEMENT_EMPTY';
    case SettlementCashMismatch = 'SETTLEMENT_CASH_MISMATCH';
    case SettlementNotReversible = 'SETTLEMENT_NOT_REVERSIBLE';
    case SettlementAlreadyReversed = 'SETTLEMENT_ALREADY_REVERSED';
    case PayoutEarningNotAvailable = 'PAYOUT_EARNING_NOT_AVAILABLE';
    case PayoutEarningWrongSeller = 'PAYOUT_EARNING_WRONG_SELLER';
    case PayoutTotalMismatch = 'PAYOUT_TOTAL_MISMATCH';
    case PayoutBelowMinimum = 'PAYOUT_BELOW_MINIMUM';
    case PayoutEmptySelection = 'PAYOUT_EMPTY_SELECTION';
    case OfficeNotAssigned = 'OFFICE_NOT_ASSIGNED';
    case SellerNotFound = 'SELLER_NOT_FOUND';
    case DriverNotFound = 'DRIVER_NOT_FOUND';

    public function httpStatus(): int
    {
        return match ($this) {
            self::SellerNotFound,
            self::DriverNotFound => 404,
            self::OfficeNotAssigned => 403,
            self::SettlementAlreadyReversed => 409,
            default => 422,
        };
    }
}
