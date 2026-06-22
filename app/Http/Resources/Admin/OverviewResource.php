<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OverviewResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{stats: array<int, array<string, mixed>>, activity: array<int, array<string, mixed>>} $payload */
        $payload = $this->resource;

        return [
            'stats' => $payload['stats'],
            'activity' => $payload['activity'],
        ];
    }
}
