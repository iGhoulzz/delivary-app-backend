<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DriverProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DriverProfile */
final class DriverProfileFullResource extends JsonResource
{
    /**
     * Relations read by toArray(). Callers must loadMissing() these before
     * resolving so nested public ids are never emitted as null.
     *
     * @var array<int, string>
     */
    public const RELATIONS = ['user', 'office', 'approvedBy', 'documents.driver.media'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user?->public_id,
            'status' => $this->status->value,
            'activity_status' => $this->activity_status->value,
            'office' => $this->relationLoaded('office') && $this->office !== null
                ? ['id' => $this->office->public_id, 'name' => $this->office->name]
                : null,
            'user' => $this->relationLoaded('user') && $this->user !== null ? [
                'id' => $this->user->public_id,
                'first_name' => $this->user->first_name,
                'last_name' => $this->user->last_name,
                'phone_number' => $this->user->phone_number,
                'phone_verified' => $this->user->phone_verified_at !== null,
                'email' => $this->user->email,
                'email_verified' => $this->user->email_verified_at !== null,
                'account_status' => $this->user->account_status->value,
            ] : null,
            'vehicle' => [
                'type' => $this->vehicle_type->value,
                'plate' => $this->vehicle_plate,
                'color' => $this->vehicle_color,
                'model' => $this->vehicle_model,
            ],
            'documents' => DriverDocumentResource::collection($this->whenLoaded('documents')),
            'audit' => [
                'created_at' => $this->created_at?->toIso8601String(),
                'approved_at' => $this->approved_at?->toIso8601String(),
                'approved_by' => $this->relationLoaded('approvedBy') && $this->approvedBy !== null
                    ? ['id' => $this->approvedBy->public_id, 'name' => $this->approvedBy->fullName()]
                    : null,
                'rejected_at' => $this->rejected_at?->toIso8601String(),
            ],
            'lifetime_deliveries' => $this->lifetime_deliveries,
            'rating_average' => $this->rating_average,
            'notes' => $this->notes,
        ];
    }
}
