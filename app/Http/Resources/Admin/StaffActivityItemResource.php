<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StaffActivityItemResource extends JsonResource
{
    /**
     * The resource is already a safe, shaped array from StaffActivityService.
     * Pass it through as-is — the service owns the safety contract.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $item */
        $item = $this->resource;

        return $item;
    }
}
