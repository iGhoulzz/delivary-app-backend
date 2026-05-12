<?php

declare(strict_types=1);

namespace App\Enums;

enum DriverAccountTransactionReason: string
{
    case OrderCompleted = 'order_completed';
    case Settlement = 'settlement';
    case Payout = 'payout';
    case CancellationFee = 'cancellation_fee';
    case SettlementShortage = 'settlement_shortage';
    case SettlementExcess = 'settlement_excess';
    case DebtOffset = 'debt_offset';
    case DebtPayment = 'debt_payment';
    case StrikeFee = 'strike_fee';
    case ManualAdjustment = 'manual_adjustment';

    public function label(): string
    {
        return match ($this) {
            self::OrderCompleted => 'Order Completed',
            self::Settlement => 'Settlement',
            self::Payout => 'Payout',
            self::CancellationFee => 'Cancellation Fee',
            self::SettlementShortage => 'Settlement Shortage',
            self::SettlementExcess => 'Settlement Excess',
            self::DebtOffset => 'Debt Offset',
            self::DebtPayment => 'Debt Payment',
            self::StrikeFee => 'Strike Fee',
            self::ManualAdjustment => 'Manual Adjustment',
        };
    }
}
