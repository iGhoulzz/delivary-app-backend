<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DriverProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DriverProfile */
final class DriverProfileResource extends JsonResource
{
    /**
     * Relations read by toArray(). Callers must loadMissing() these before
     * resolving so nested public ids are never emitted as null.
     *
     * @var array<int, string>
     */
    public const RELATIONS = ['user', 'office'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user?->public_id,
            'user' => $this->relationLoaded('user') && $this->user !== null
                ? ['id' => $this->user->public_id, 'name' => $this->user->fullName()]
                : null,
            'office' => $this->relationLoaded('office') && $this->office !== null
                ? ['id' => $this->office->public_id, 'name' => $this->office->name]
                : null,
            'status' => $this->status->value,
            'account_status' => $this->user?->account_status?->value,
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
