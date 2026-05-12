<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DriverProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DriverProfile */
final class DriverProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'office_id' => $this->office_id,
            'status' => $this->status->value,
            'activity_status' => $this->activity_status->value,
            'vehicle_type' => $this->vehicle_type->value,
            'vehicle_plate' => $this->vehicle_plate,
            'vehicle_color' => $this->vehicle_color,
            'vehicle_model' => $this->vehicle_model,
            'lifetime_deliveries' => $this->lifetime_deliveries,
            'rating_average' => $this->rating_average,
            'created_at' => $this->created_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
        ];
    }
}
