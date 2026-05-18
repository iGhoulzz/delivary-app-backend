<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Models\OfficeInventory;
use App\Services\Order\StorageFeeCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OfficeInventoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OfficeInventory $inventory */
        $inventory = $this->resource;

        return [
            'id' => $inventory->public_id,
            'office_id' => $inventory->office_id,
            'received_by_staff_id' => $inventory->received_by_staff_id,
            'received_at' => $inventory->received_at?->toIso8601String(),
            'shelf_location' => $inventory->shelf_location,
            'accrued_storage_fee_snapshot' => (string) $inventory->accrued_storage_fee,
            'accrued_storage_fee_live' => $inventory->retrieved_at === null && $inventory->abandoned_at === null
                ? app(StorageFeeCalculator::class)->compute($inventory)
                : (string) $inventory->accrued_storage_fee,
            'retrieval_fees_waived_amount' => (string) $inventory->retrieval_fees_waived_amount,
            'cash_collected_at_retrieval' => (string) $inventory->cash_collected_at_retrieval,
            'retrieved_at' => $inventory->retrieved_at?->toIso8601String(),
            'retrieved_by_staff_id' => $inventory->retrieved_by_staff_id,
            'abandoned_at' => $inventory->abandoned_at?->toIso8601String(),
            'abandoned_by_admin_id' => $inventory->abandoned_by_admin_id,
            'disposal_notes' => $inventory->disposal_notes,
            'notes' => $inventory->notes,
        ];
    }
}
