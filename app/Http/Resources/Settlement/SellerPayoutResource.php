<?php

declare(strict_types=1);

namespace App\Http\Resources\Settlement;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SellerPayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'amount' => (string) $this->amount,
            'office' => [
                'id' => $this->office?->public_id,
                'name' => $this->office?->name,
            ],
            'paid_at' => $this->paid_at?->toIso8601String(),
            'paid_by_staff' => [
                'id' => $this->paidByStaff?->public_id,
                'name' => $this->paidByStaff?->fullName(),
            ],
            'status' => $this->status->value,
            'notes' => $this->notes,
            'orders' => $this->whenLoaded(
                'orders',
                fn () => $this->orders->map(static fn ($order): array => [
                    'order_id' => $order->public_id,
                    'order_number' => $order->order_number,
                    'item_description' => $order->item_description,
                    'amount_contributed' => (string) $order->pivot->amount_contributed,
                ])->all(),
            ),
        ];
    }
}
