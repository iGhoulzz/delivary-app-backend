<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Models\OrderStatusLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrderStatusLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OrderStatusLog $l */
        $l = $this->resource;

        return [
            'from_status' => $l->from_status?->value,
            'to_status' => $l->to_status->value,
            'actor_type' => $l->actor_type->value,
            'actor_id' => $l->actor_id,
            'reason' => $l->reason,
            'metadata' => $l->metadata,
            'actor_location' => $l->actor_location
                ? ['lat' => (float) $l->actor_location->getLatitude(), 'lng' => (float) $l->actor_location->getLongitude()]
                : null,
            'created_at' => $l->created_at?->toIso8601String(),
        ];
    }
}
