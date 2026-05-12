<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\OrderDisplayStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class GuestTrackingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $o */
        $o = $this->resource;

        $senderFirstName = explode(' ', trim((string) $o->sender_name))[0] ?? null;

        return [
            'id' => $o->public_id,
            'display_status' => OrderDisplayStatus::fromInternal($o->status),
            'order_type' => $o->order_type->value,
            'item' => [
                'description' => $o->item_description,
                'size' => $o->item_size->value,
            ],
            'sender' => [
                'first_name' => $senderFirstName,    // no phone
            ],
            'pickup_address' => $o->pickup_address,
            'receiver_address' => $o->receiver_address,
            'receiver_location' => $o->receiver_location
                ? ['lat' => (float) $o->receiver_location->getLatitude(), 'lng' => (float) $o->receiver_location->getLongitude()]
                : null,
            'delivery_fee' => (string) $o->delivery_fee,
            'delivery_fee_payer' => $o->delivery_fee_payer->value,
            'cash_collected_at_delivery' => $o->cashCollectedAtDelivery(),
            'delivery_code' => $o->delivery_code,    // the receiver needs this
            'driver' => $this->driverBlock($o),
            'timestamps' => [
                'created_at' => $o->created_at?->toIso8601String(),
                'picked_up_at' => $o->picked_up_at?->toIso8601String(),
                'delivered_at' => $o->delivered_at?->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function driverBlock(Order $o): ?array
    {
        if ($o->driver_id === null) {
            return null;
        }
        $afterPickup = in_array($o->status, [
            OrderStatus::PickedUp,
            OrderStatus::DriverEnRouteDropoff,
            OrderStatus::DeliveryInProgress,
            OrderStatus::Delivered,
        ], true);
        if (! $afterPickup) {
            return null;
        }

        $driver = $o->driver;
        $profile = $driver?->driverProfile;
        $firstName = explode(' ', trim((string) $driver?->first_name))[0] ?? null;

        return [
            'first_name' => $firstName,
            'phone' => $driver?->phone_number,
            'vehicle_type' => $profile?->vehicle_type?->value,
            'current_location' => $profile?->current_location
                ? ['lat' => (float) $profile->current_location->getLatitude(), 'lng' => (float) $profile->current_location->getLongitude()]
                : null,
        ];
    }
}
