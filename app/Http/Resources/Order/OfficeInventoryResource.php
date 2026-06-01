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
            'office' => [
                'id' => $inventory->office?->public_id,
                'name' => $inventory->office?->name,
            ],
            'received_by' => $inventory->receivedByStaff
                ? ['id' => $inventory->receivedByStaff->public_id, 'name' => $inventory->receivedByStaff->fullName()]
                : null,
            'received_at' => $inventory->received_at?->toIso8601String(),
            'shelf_location' => $inventory->shelf_location,
            'accrued_storage_fee_snapshot' => (string) $inventory->accrued_storage_fee,
            'accrued_storage_fee_live' => $inventory->retrieved_at === null && $inventory->abandoned_at === null
                ? app(StorageFeeCalculator::class)->compute($inventory)
                : (string) $inventory->accrued_storage_fee,
            'retrieval_fees_waived_amount' => (string) $inventory->retrieval_fees_waived_amount,
            'cash_collected_at_retrieval' => (string) $inventory->cash_collected_at_retrieval,
            'retrieved_at' => $inventory->retrieved_at?->toIso8601String(),
            'retrieved_by' => $inventory->retrievedByStaff
                ? ['id' => $inventory->retrievedByStaff->public_id, 'name' => $inventory->retrievedByStaff->fullName()]
                : null,
            'abandoned_at' => $inventory->abandoned_at?->toIso8601String(),
            'abandoned_by' => $inventory->abandonedByAdmin
                ? ['id' => $inventory->abandonedByAdmin->public_id, 'name' => $inventory->abandonedByAdmin->fullName()]
                : null,
            'disposal_notes' => $inventory->disposal_notes,
            'notes' => $inventory->notes,
        ];
    }
}
