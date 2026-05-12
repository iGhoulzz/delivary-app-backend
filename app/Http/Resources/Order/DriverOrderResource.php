<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Models\Order;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DriverOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $o */
        $o = $this->resource;

        return [
            'id' => $o->public_id,
            'order_type' => $o->order_type->value,
            'status' => $o->status->value,                  // raw — driver needs granularity
            'status_changed_at' => $o->status_changed_at?->toIso8601String(),

            'sender' => [
                'name' => $o->sender_name,
                'phone' => $o->sender_phone,                 // visible to assigned driver
            ],

            'pickup' => [
                'address' => $o->pickup_address,
                'location' => $this->pointToArray($o->pickup_location),
                'notes' => $o->pickup_notes,
                // NOTE: pickup_code never exposed to driver — sender hands them the code.
                'geofence_confirmed_at' => $o->pickup_geofence_confirmed_at?->toIso8601String(),
                'code_required' => (bool) PlatformSetting::get('codes.enforce_pickup', true),
            ],

            'receiver' => [
                'name' => $o->receiver_name,
                'phone' => $o->receiver_phone,
                'address' => $o->receiver_address,
                'location' => $this->pointToArray($o->receiver_location),
                'notes' => $o->receiver_notes,
                // NOTE: delivery_code never exposed to driver.
                'code_required' => (bool) PlatformSetting::get('codes.enforce_delivery', true),
            ],

            'item' => [
                'description' => $o->item_description,
                'size' => $o->item_size->value,
                'weight_kg' => $o->item_weight_kg ? (string) $o->item_weight_kg : null,
                'price' => (string) $o->item_price,
            ],

            'earnings' => [
                'delivery_fee' => (string) $o->delivery_fee,
                'driver_fee_cut_amount' => (string) $o->driver_fee_cut_amount,
                'driver_take_home' => bcsub((string) $o->delivery_fee, (string) $o->driver_fee_cut_amount, 2),
                'cash_to_collect' => $o->cashCollectedAtDelivery(),
                'delivery_fee_payer' => $o->delivery_fee_payer->value,
                'delivery_fee_payment_method' => $o->delivery_fee_payment_method->value,
            ],
        ];
    }

    /** @return array{lat: float, lng: float}|null */
    private function pointToArray(mixed $p): ?array
    {
        return $p === null ? null : ['lat' => (float) $p->getLatitude(), 'lng' => (float) $p->getLongitude()];
    }
}
