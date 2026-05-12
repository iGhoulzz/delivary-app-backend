<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cash settlement outcome (spec section 11.4).
 *
 * - Completed:  matched or excess returned, all balances cleared.
 * - Disputed:   driver and agent disagreed — NO record persisted with this
 *               status in normal flow; admin-only state for special cases
 *               (post-hoc reconciliation, audit annotation).
 * - Cancelled:  agent or admin voided the settlement before commit.
 */
enum SettlementStatus: string
{
    case Completed = 'completed';
    case Disputed = 'disputed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
            self::Disputed => 'Disputed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isFinal(): bool
    {
        return $this !== self::Disputed;
    }
}
