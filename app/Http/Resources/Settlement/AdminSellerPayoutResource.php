<?php

declare(strict_types=1);

namespace App\Http\Resources\Settlement;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminSellerPayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'amount' => (string) $this->amount,
            'seller' => [
                'id' => $this->user?->public_id,
                'name' => $this->user?->fullName(),
                'phone' => $this->user?->phone_number,
            ],
            'office' => [
                'id' => $this->office?->id,
                'name' => $this->office?->name,
            ],
            'paid_at' => $this->paid_at?->toIso8601String(),
            'paid_by_staff' => [
                'id' => $this->paidByStaff?->public_id,
                'name' => $this->paidByStaff?->fullName(),
            ],
            'status' => $this->status->value,
            'notes' => $this->notes,
            'order_count' => $this->whenLoaded('orders', fn () => $this->orders->count()),
        ];
    }
}
