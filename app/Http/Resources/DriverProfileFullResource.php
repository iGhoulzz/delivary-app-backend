<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DriverDocument;
use App\Models\DriverProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DriverProfile */
final class DriverProfileFullResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $documents = DriverDocument::where('driver_id', $this->user_id)->get();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'activity_status' => $this->activity_status->value,
            'office_id' => $this->office_id,
            'office' => $this->relationLoaded('office') && $this->office !== null
                ? ['id' => $this->office->id, 'name' => $this->office->name]
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
            'documents' => DriverDocumentResource::collection($documents)->resolve($request),
            'audit' => [
                'created_at' => $this->created_at?->toIso8601String(),
                'approved_at' => $this->approved_at?->toIso8601String(),
                'approved_by_admin_id' => $this->approved_by_admin_id,
                'rejected_at' => $this->rejected_at?->toIso8601String(),
            ],
            'lifetime_deliveries' => $this->lifetime_deliveries,
            'rating_average' => $this->rating_average,
            'notes' => $this->notes,
        ];
    }
}
