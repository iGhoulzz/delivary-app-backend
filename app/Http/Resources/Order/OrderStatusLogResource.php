<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Models\OrderStatusLog;
use App\Support\OrderStatusLogMetadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderStatusLog
 */
final class OrderStatusLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var OrderStatusLog $l */
        $l = $this->resource;

        return [
            'from_status' => $l->from_status?->value,
            'to_status' => $l->to_status->value,
            'actor_type' => $l->actor_type->value,
            'actor' => $l->relationLoaded('actor') && $l->actor !== null
                ? ['id' => $l->actor->public_id, 'name' => $l->actor->fullName()]
                : null,
            'reason' => $l->reason,
            'metadata' => OrderStatusLogMetadata::sanitize($l->metadata),
            'actor_location' => $l->actor_location
                ? ['lat' => (float) $l->actor_location->getLatitude(), 'lng' => (float) $l->actor_location->getLongitude()]
                : null,
            'created_at' => $l->created_at?->toIso8601String(),
        ];
    }
}
