<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Enums\DeliveryFeeStatus;
use App\Enums\ReturnFault;
use App\Models\Order;
use App\Services\Order\StorageFeeCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OfficeOrderResource extends JsonResource
{
    /**
     * Relations read by toArray(). Callers must loadMissing() these before
     * resolving so nested public ids are never emitted as null.
     *
     * @var array<int, string>
     */
    public const RELATIONS = [
        'officeInventory.office',
        'officeInventory.receivedByStaff',
        'officeInventory.retrievedByStaff',
        'officeInventory.abandonedByAdmin',
        'sender',
        'driver.driverProfile',
        'returnOffice',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $inventory = $order->officeInventory;

        $deliveryFeeOwed = $this->computeDeliveryFeeOwed($order);
        $storageFee = $inventory !== null && $inventory->retrieved_at === null && $inventory->abandoned_at === null
            ? app(StorageFeeCalculator::class)->compute($inventory)
            : ($inventory !== null ? (string) $inventory->accrued_storage_fee : '0.00');
        $waived = $inventory !== null ? (string) $inventory->retrieval_fees_waived_amount : '0.00';
        $totalOwed = bcsub(bcadd($deliveryFeeOwed, $storageFee, 2), $waived, 2);

        if (bccomp($totalOwed, '0.00', 2) === -1) {
            $totalOwed = '0.00';
        }

        return [
            'id' => $order->public_id,
            'status' => $order->status->value,
            'order_type' => $order->order_type->value,
            'item' => [
                'description' => $order->item_description,
                'size' => $order->item_size->value,
                'price' => (string) $order->item_price,
            ],
            'sender' => [
                'id' => $order->relationLoaded('sender') ? $order->sender?->public_id : null,
                'name' => $order->relationLoaded('sender') ? $order->sender?->fullName() : null,
                'phone' => $order->sender_phone,
            ],
            'pickup_address' => $order->pickup_address,
            'receiver_address' => $order->receiver_address,
            'return' => [
                'office' => $order->relationLoaded('returnOffice') && $order->returnOffice !== null
                    ? ['id' => $order->returnOffice->public_id, 'name' => $order->returnOffice->name]
                    : null,
                'reason' => $order->return_reason?->value,
                'fault' => $order->return_fault?->value,
                'delivery_failed_at' => $order->delivery_failed_at?->toIso8601String(),
                'returning_to_office_at' => $order->returning_to_office_at?->toIso8601String(),
                'returned_to_office_at' => $order->returned_to_office_at?->toIso8601String(),
                'at_office_at' => $order->at_office_at?->toIso8601String(),
            ],
            'retrieval_owed' => [
                'delivery_fee' => $deliveryFeeOwed,
                'storage_fee_live' => $storageFee,
                'waived' => $waived,
                'total' => $totalOwed,
            ],
            'inventory' => $inventory !== null ? (new OfficeInventoryResource($inventory))->toArray($request) : null,
            'driver' => $order->driver_id !== null ? [
                'id' => $order->relationLoaded('driver') ? $order->driver?->public_id : null,
                'name' => $order->relationLoaded('driver') ? $order->driver?->fullName() : null,
            ] : null,
        ];
    }

    private function computeDeliveryFeeOwed(Order $order): string
    {
        if ($order->delivery_fee_status === DeliveryFeeStatus::Paid) {
            return '0.00';
        }

        if (! in_array($order->return_fault, [ReturnFault::Sender, ReturnFault::Receiver], true)) {
            return '0.00';
        }

        return (string) $order->delivery_fee;
    }
}
