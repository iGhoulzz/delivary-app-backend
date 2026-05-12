<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DriverAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DriverAccount */
final class DriverAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'cash_to_deposit' => $this->cash_to_deposit,
            'earnings_balance' => $this->earnings_balance,
            'debt_balance' => $this->debt_balance,
            'max_cash_liability' => $this->max_cash_liability,
            'lifetime_earnings' => $this->lifetime_earnings,
            'lifetime_cash_handled' => $this->lifetime_cash_handled,
            'lifetime_platform_fees_paid' => $this->lifetime_platform_fees_paid,
            'net_position' => $this->net_position,
        ];
    }
}
