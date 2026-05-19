<?php

declare(strict_types=1);

namespace App\Http\Resources\Broadcast;

use App\Models\DriverProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Broadcast-safe driver shape for `OrderDriverAssigned` and similar events.
 * Sender-visible fields only — NO internal id, user_id, office_id, plate,
 * status, or activity_status. Request-independent.
 *
 * Caller contract: wrap a DriverProfile with `user` eager-loaded.
 *
 * @mixin DriverProfile
 */
final class DriverForOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->user;

        return [
            'first_name' => $user?->first_name,
            'vehicle_type' => $this->vehicle_type->value,
            'vehicle_color' => $this->vehicle_color,
            'rating_average' => $this->rating_average,
            'lifetime_deliveries' => $this->lifetime_deliveries,
            'current_location' => $this->current_location !== null
                ? [
                    'lat' => (float) $this->current_location->getLatitude(),
                    'lng' => (float) $this->current_location->getLongitude(),
                ]
                : null,
        ];
    }
}
