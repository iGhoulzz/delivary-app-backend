<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\OrderDisplayStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $o */
        $o = $this->resource;
        $user = $request->user();
        $isSender = $user !== null && $user->id === $o->sender_user_id;
        $isReceiver = $user !== null && $o->receiver_user_id !== null && $user->id === $o->receiver_user_id;

        return [
            'id' => $o->public_id,
            'order_type' => $o->order_type->value,
            'display_status' => OrderDisplayStatus::fromInternal($o->status),
            'status_changed_at' => $o->status_changed_at?->toIso8601String(),
            'created_at' => $o->created_at?->toIso8601String(),

            'pickup' => [
                'address' => $o->pickup_address,
                'location' => $this->pointToArray($o->pickup_location),
                'notes' => $isSender ? $o->pickup_notes : null,
                'pickup_code' => $isSender ? $o->pickup_code : null,
                'geofence_confirmed_at' => $isSender ? $o->pickup_geofence_confirmed_at?->toIso8601String() : null,
            ],

            'receiver' => [
                'address' => $o->receiver_address,
                'location' => $this->pointToArray($o->receiver_location),
                'notes' => $o->receiver_notes,
                'phone' => $isSender ? $o->receiver_phone : null,
                'name' => $isSender ? $o->receiver_name : null,
                'delivery_code' => $isReceiver ? $o->delivery_code : null,
            ],

            'item' => [
                'description' => $o->item_description,
                'size' => $o->item_size->value,
                'weight_kg' => $o->item_weight_kg ? (string) $o->item_weight_kg : null,
                'value' => $o->item_value ? (string) $o->item_value : null,
                'price' => (string) $o->item_price,
            ],

            'pricing' => [
                'delivery_fee_base' => (string) $o->delivery_fee_base,
                'delivery_fee_surcharge_percent' => $o->delivery_fee_surcharge_percent,
                'delivery_fee' => (string) $o->delivery_fee,
                'delivery_fee_payer' => $o->delivery_fee_payer->value,
                'delivery_fee_payment_method' => $o->delivery_fee_payment_method->value,
                'delivery_fee_status' => $o->delivery_fee_status->value,
                'commission_amount' => $isSender ? (string) $o->commission_amount : null,
                'cash_collected_at_delivery' => $o->cashCollectedAtDelivery(),
            ],

            'driver' => $this->driverBlock($o, $isSender, $isReceiver),

            'timestamps' => [
                'assigned_at' => $o->assigned_at?->toIso8601String(),
                'picked_up_at' => $o->picked_up_at?->toIso8601String(),
                'delivery_in_progress_at' => $o->delivery_in_progress_at?->toIso8601String(),
                'delivered_at' => $o->delivered_at?->toIso8601String(),
                'no_driver_available_at' => $o->no_driver_available_at?->toIso8601String(),
                'cancelled_at' => $o->cancelled_at?->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function driverBlock(Order $o, bool $isSender, bool $isReceiver): ?array
    {
        if ($o->driver_id === null) {
            return null;
        }
        // Receiver only sees driver block from picked_up onward.
        if ($isReceiver && ! $isSender) {
            $allowed = in_array($o->status, [
                OrderStatus::PickedUp,
                OrderStatus::DriverEnRouteDropoff,
                OrderStatus::DeliveryInProgress,
                OrderStatus::Delivered,
            ], true);
            if (! $allowed) {
                return null;
            }
        }

        $driver = $o->driver;
        $profile = $driver?->driverProfile;

        return [
            'first_name' => $driver?->first_name,
            'phone' => $driver?->phone_number,
            'vehicle_type' => $profile?->vehicle_type?->value,
            'current_location' => $profile ? $this->pointToArray($profile->current_location) : null,
            'last_seen_at' => $profile?->last_active_at?->toIso8601String(),
        ];
    }

    /** @return array{lat: float, lng: float}|null */
    private function pointToArray(mixed $p): ?array
    {
        if ($p === null) {
            return null;
        }

        return ['lat' => (float) $p->getLatitude(), 'lng' => (float) $p->getLongitude()];
    }
}
