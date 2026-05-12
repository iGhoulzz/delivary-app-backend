<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Region */
final class RegionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'service_area_id' => $this->service_area_id,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
