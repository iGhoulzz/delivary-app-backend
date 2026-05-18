<?php

declare(strict_types=1);

namespace App\Http\Resources\Settlement;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'driver' => [
                'id' => $this->driver?->public_id,
                'name' => $this->driver?->fullName(),
            ],
            'office' => [
                'id' => $this->office?->id,
                'name' => $this->office?->name,
            ],
            'processed_by_staff' => [
                'id' => $this->processedByStaff?->public_id,
                'name' => $this->processedByStaff?->fullName(),
            ],
            'cash_received_from_driver' => (string) $this->cash_received_from_driver,
            'cash_paid_to_driver' => (string) $this->cash_paid_to_driver,
            'cash_movement' => $this->cashMovement(),
            'cash_to_deposit_cleared' => (string) $this->cash_to_deposit_cleared,
            'earnings_balance_cleared' => (string) $this->earnings_balance_cleared,
            'debt_balance_cleared' => (string) $this->debt_balance_cleared,
            'shortage_amount' => (string) $this->shortage_amount,
            'excess_amount' => (string) $this->excess_amount,
            'notes' => $this->notes,
            'contributing_orders' => $this->whenLoaded(
                'orders',
                fn () => $this->orders->map(static fn ($order): array => [
                    'order_id' => $order->public_id,
                    'amount_contributed' => (string) $order->pivot->amount_contributed,
                ])->all(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
