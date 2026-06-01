<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminOrderResource extends JsonResource
{
    /**
     * Relations read by toArray(). Callers must loadMissing() these before
     * resolving so nested public ids are never emitted as null.
     * (officeInventory is intentionally absent — this resource does not render it.)
     *
     * @var array<int, string>
     */
    public const RELATIONS = [
        'sender',
        'receiverUser',
        'receiverGuest',
        'driver.driverProfile',
        'returnOffice',
        'statusLogs.actor',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $o */
        $o = $this->resource;

        return [
            'id' => $o->public_id,
            'order_type' => $o->order_type->value,
            'status' => $o->status->value,
            'status_changed_at' => $o->status_changed_at?->toIso8601String(),
            'tracking_token' => $o->tracking_token,

            'sender' => [
                'id' => $o->relationLoaded('sender') ? $o->sender?->public_id : null,
                'name' => $o->relationLoaded('sender') ? $o->sender?->fullName() : null,
                'phone' => $o->sender_phone,
            ],
            'pickup' => [
                'address' => $o->pickup_address,
                'location' => $this->pt($o->pickup_location),
                'notes' => $o->pickup_notes,
                'code' => $o->pickup_code,           // visible to admin
                'code_attempts' => $o->pickup_code_attempts,
                'picked_up_method' => $o->picked_up_method?->value,
                'geofence_confirmed_at' => $o->pickup_geofence_confirmed_at?->toIso8601String(),
            ],
            'receiver' => [
                'type' => $o->receiver_type->value,
                'user' => $o->relationLoaded('receiverUser') && $o->receiverUser !== null
                    ? ['id' => $o->receiverUser->public_id, 'name' => $o->receiverUser->fullName()]
                    : null,
                'guest' => $o->relationLoaded('receiverGuest') && $o->receiverGuest !== null
                    ? ['id' => $o->receiverGuest->public_id]
                    : null,
                'name' => $o->receiver_name,
                'phone' => $o->receiver_phone,
                'address' => $o->receiver_address,
                'location' => $this->pt($o->receiver_location),
                'notes' => $o->receiver_notes,
                'code' => $o->delivery_code,        // visible to admin
                'code_attempts' => $o->delivery_code_attempts,
                'delivered_method' => $o->delivered_method?->value,
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
                'commission_rate' => (string) $o->commission_rate,
                'commission_amount' => (string) $o->commission_amount,
                'driver_fee_cut_rate' => (string) $o->driver_fee_cut_rate,
                'driver_fee_cut_amount' => (string) $o->driver_fee_cut_amount,
                'delivery_fee_payer' => $o->delivery_fee_payer->value,
                'delivery_fee_payment_method' => $o->delivery_fee_payment_method->value,
                'delivery_fee_status' => $o->delivery_fee_status->value,
                'delivery_fee_paid_at' => $o->delivery_fee_paid_at?->toIso8601String(),
            ],
            'driver' => $o->driver_id ? [
                'id' => $o->relationLoaded('driver') ? $o->driver?->public_id : null,
                'name' => $o->relationLoaded('driver') ? $o->driver?->fullName() : null,
                'first_name' => $o->driver?->first_name,
                'phone' => $o->driver?->phone_number,
                'assignment_attempts' => $o->driver_assignment_attempts,
                'search_radius_tier' => $o->search_radius_tier,
            ] : null,
            'timestamps' => [
                'awaiting_driver_at' => $o->awaiting_driver_at?->toIso8601String(),
                'no_driver_available_at' => $o->no_driver_available_at?->toIso8601String(),
                'assigned_at' => $o->assigned_at?->toIso8601String(),
                'driver_en_route_pickup_at' => $o->driver_en_route_pickup_at?->toIso8601String(),
                'picked_up_at' => $o->picked_up_at?->toIso8601String(),
                'driver_en_route_dropoff_at' => $o->driver_en_route_dropoff_at?->toIso8601String(),
                'delivery_in_progress_at' => $o->delivery_in_progress_at?->toIso8601String(),
                'delivered_at' => $o->delivered_at?->toIso8601String(),
                'cancelled_at' => $o->cancelled_at?->toIso8601String(),
                'created_at' => $o->created_at?->toIso8601String(),
            ],
            'status_logs' => $this->whenLoaded('statusLogs', fn () => OrderStatusLogResource::collection($o->statusLogs)),
        ];
    }

    /** @return array{lat: float, lng: float}|null */
    private function pt(mixed $p): ?array
    {
        return $p === null ? null : ['lat' => (float) $p->getLatitude(), 'lng' => (float) $p->getLongitude()];
    }
}
