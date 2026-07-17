<?php

declare(strict_types=1);

namespace App\Http\Resources\Settlement;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SellerEarningResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'order_id' => $this->order?->public_id,
            'order_number' => $this->order?->order_number,
            'order_description' => $this->order?->item_description,
            'amount' => (string) $this->amount,
            'status' => $this->status->value,
            'cleared_at' => $this->cleared_at?->toIso8601String(),
            'available_at' => $this->available_at?->toIso8601String(),
            'paid_out_at' => $this->paid_out_at?->toIso8601String(),
        ];
    }
}
