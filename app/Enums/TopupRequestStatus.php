<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a wallet top-up request (architecture-ready, inactive at MVP).
 *
 *   Pending     → user requested, not yet sent to gateway
 *   Processing  → handed off to the gateway, awaiting response/webhook
 *   Completed   → gateway confirmed payment, wallet credited
 *   Failed      → gateway declined or errored
 *   Cancelled   → user cancelled before processing started
 *   Refunded    → previously completed, money returned to source (post-fact reversal)
 */
enum TopupRequestStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Failed,
            self::Cancelled,
            self::Refunded,
        ], true);
    }

    public function isSuccessful(): bool
    {
        return $this === self::Completed;
    }
}
