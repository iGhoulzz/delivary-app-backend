<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\Driver\DriverStrikeResource;
use App\Models\DriverStrike;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class StrikeController extends Controller
{
    public function index(User $driverUser): JsonResponse
    {
        $strikes = DriverStrike::query()
            ->forDriver($driverUser->id)
            ->with('order')
            ->latest()
            ->get();

        // Active = not voided AND inside the rolling 30-day window (spec §9.6).
        $activeCount = $strikes
            ->filter(fn (DriverStrike $strike): bool => ! $strike->is_voided && $strike->created_at >= now()->subDays(30))
            ->count();

        return response()->json([
            'active_count' => $activeCount,
            'total' => $strikes->count(),
            'strikes' => DriverStrikeResource::collection($strikes)->resolve(),
        ]);
    }
}
