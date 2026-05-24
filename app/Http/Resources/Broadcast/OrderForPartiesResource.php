<?php

declare(strict_types=1);

namespace App\Http\Resources\Broadcast;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\OrderDisplayStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Broadcast-safe order shape for the `private:order.{public_id}` channel.
 * Audience-neutral (sender + receiver receive the same payload). Request-
 * independent — must NOT read $request->user() because broadcasts run off
 * the HTTP request lifecycle.
 *
 * Caller contract: the Order must have `driver.driverProfile` eager-loaded
 * before wrapping in this Resource, otherwise N+1 queries will fire on every
 * broadcast. The dispatching event's broadcastWith() is responsible.
 */
final class OrderForPartiesResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $o */
        $o = $this->resource;

        return [
            'id' => $o->public_id,
            'order_type' => $o->order_type->value,
            'status' => $o->status->value,
            'display_status' => OrderDisplayStatus::fromInternal($o->status),
            'status_changed_at' => $o->status_changed_at?->toIso8601String(),

            'pickup' => [
                'address' => $o->pickup_address,
                'location' => $this->point($o->pickup_location),
            ],

            'receiver' => [
                'address' => $o->receiver_address,
                'location' => $this->point($o->receiver_location),
            ],

            'item' => [
                'description' => $o->item_description,
                'size' => $o->item_size->value,
            ],

            'pricing' => [
                'delivery_fee' => (string) $o->delivery_fee,
                'delivery_fee_payer' => $o->delivery_fee_payer->value,
                'cash_collected_at_delivery' => $o->cashCollectedAtDelivery(),
            ],

            'driver' => $this->driverBlock($o),

            'timestamps' => [
                'created_at' => $o->created_at?->toIso8601String(),
                'assigned_at' => $o->assigned_at?->toIso8601String(),
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
        // Driver identity (name, vehicle, last-seen) is exposed as soon as a
        // driver is assigned. current_location is the only field gated on
        // post-pickup, to avoid leaking the pickup-route geometry to the
        // receiver before pickup. Spec §4.0 / Task 4 plan.
        $afterPickup = in_array($o->status, [
            OrderStatus::PickedUp,
            OrderStatus::DriverEnRouteDropoff,
            OrderStatus::DeliveryInProgress,
            OrderStatus::Delivered,
        ], true);

        $driver = $o->driver;
        $profile = $driver?->driverProfile;

        return [
            'first_name' => $driver?->first_name,
            'vehicle_type' => $profile?->vehicle_type?->value,
            'vehicle_color' => $profile?->vehicle_color,
            'current_location' => $afterPickup && $profile
                ? $this->point($profile->current_location)
                : null,
            'last_seen_at' => $profile?->last_active_at?->toIso8601String(),
        ];
    }

    /** @return array{lat: float, lng: float}|null */
    private function point(mixed $p): ?array
    {
        if ($p === null) {
            return null;
        }

        return ['lat' => (float) $p->getLatitude(), 'lng' => (float) $p->getLongitude()];
    }
}
