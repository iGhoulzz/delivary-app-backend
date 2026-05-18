<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Models\SellerEarning;
use Illuminate\Support\Collection;

final readonly class SettlementPreview
{
    /**
     * @param  Collection<int, SellerEarning>  $pendingEarnings
     */
    public function __construct(
        public string $cashToDeposit,
        public string $earningsBalance,
        public string $debtBalance,
        public string $expectedNet,
        public Collection $pendingEarnings,
    ) {}
}
