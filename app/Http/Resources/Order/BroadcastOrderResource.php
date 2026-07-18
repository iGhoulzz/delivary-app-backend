<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class BroadcastOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return [
            'id' => $order->public_id,
            'order_number' => $order->order_number,
            'order_type' => $order->order_type->value,
            'status' => $order->status->value,
            'pickup' => [
                'address' => $order->pickup_address,
                'location' => $this->point($order->pickup_location),
            ],
            'receiver' => [
                'address' => $order->receiver_address,
                'location' => $this->point($order->receiver_location),
            ],
            'item' => [
                'description' => $order->item_description,
                'size' => $order->item_size->value,
                'price' => (string) $order->item_price,
            ],
            'earnings' => [
                'delivery_fee' => (string) $order->delivery_fee,
                'driver_fee_cut_amount' => (string) $order->driver_fee_cut_amount,
                'driver_take_home' => bcsub((string) $order->delivery_fee, (string) $order->driver_fee_cut_amount, 2),
                'cash_to_collect' => $order->cashCollectedAtDelivery(),
            ],
            'search_radius_tier' => $order->search_radius_tier,
            'delivery_fee_surcharge_percent' => $order->delivery_fee_surcharge_percent,
            'distance_to_pickup_meters' => isset($order->distance_to_pickup_meters)
                ? (int) $order->distance_to_pickup_meters
                : null,
        ];
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function point(mixed $point): ?array
    {
        if ($point === null) {
            return null;
        }

        return ['lat' => (float) $point->getLatitude(), 'lng' => (float) $point->getLongitude()];
    }
}
