<?php

declare(strict_types=1);

namespace App\Http\Resources\Settlement;

use App\ValueObjects\SettlementPreview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property SettlementPreview $resource */
final class SettlementPreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'buckets' => [
                'cash_to_deposit' => $this->resource->cashToDeposit,
                'earnings_balance' => $this->resource->earningsBalance,
                'debt_balance' => $this->resource->debtBalance,
            ],
            'expected_net' => $this->resource->expectedNet,
            'instructions' => $this->buildInstructions(),
            'pending_earnings' => $this->resource->pendingEarnings->map(static fn ($earning): array => [
                'order_id' => $earning->order?->public_id,
                'item_description' => $earning->order?->item_description,
                'item_price' => (string) ($earning->order?->item_price ?? '0.00'),
                'amount' => (string) $earning->amount,
            ])->all(),
        ];
    }

    private function buildInstructions(): string
    {
        $net = $this->resource->expectedNet;

        if (bccomp($net, '0.00', 2) === 1) {
            return "Driver should hand over {$net} LYD.";
        }

        if (bccomp($net, '0.00', 2) === -1) {
            $absNet = bcmul($net, '-1', 2);

            return "Platform should pay driver {$absNet} LYD.";
        }

        return 'No cash movement required. Buckets cancel out exactly.';
    }
}
