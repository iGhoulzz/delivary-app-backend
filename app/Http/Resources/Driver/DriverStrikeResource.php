<?php

declare(strict_types=1);

namespace App\Http\Resources\Driver;

use App\Models\DriverStrike;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DriverStrike */
final class DriverStrikeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'reason' => $this->reason->value,
            'issued_by' => $this->issued_by->value,
            'order' => $this->relationLoaded('order') && $this->order !== null
                ? ['id' => $this->order->public_id, 'order_number' => $this->order->order_number]
                : null,
            'fee_amount' => $this->fee_amount,
            'is_voided' => (bool) $this->is_voided,
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
