<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MerchantProfile;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MerchantProfile */
final class MerchantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MerchantProfile $merchant */
        $merchant = $this->resource;

        return [
            'id' => $merchant->public_id,
            'business_name' => $merchant->business_name,
            'business_phone' => $merchant->business_phone,
            'status' => $merchant->status->value,
            'commission_rate_override' => $merchant->commission_rate_override,
            'driver_fee_cut_override' => $merchant->driver_fee_cut_override,
            'default_pickup_address' => $merchant->default_pickup_address,
            'default_pickup_location' => $this->pointToArray($merchant->default_pickup_location),
            'notes' => $merchant->notes,
            'owner' => $this->whenLoaded('user', fn (): array => [
                'id' => $merchant->user->public_id,
                'name' => $merchant->user->fullName(),
                'phone' => $merchant->user->phone_number,
                'account_status' => $merchant->user->account_status->value,
                'roles' => $merchant->user->getRoleNames()->values()->all(),
            ]),
            'approved_at' => $merchant->approved_at?->toIso8601String(),
            'created_at' => $merchant->created_at?->toIso8601String(),
            'updated_at' => $merchant->updated_at?->toIso8601String(),
        ];
    }

    /** @return array{lat: float, lng: float}|null */
    private function pointToArray(?Point $point): ?array
    {
        if ($point === null) {
            return null;
        }

        return [
            'lat' => (float) $point->getLatitude(),
            'lng' => (float) $point->getLongitude(),
        ];
    }
}
