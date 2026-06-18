<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Driver;

use App\Enums\DriverStrikeReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\AddStrikeRequest;
use App\Http\Resources\Driver\DriverStrikeResource;
use App\Models\DriverStrike;
use App\Models\User;
use App\Services\Driver\DriverStrikeService;
use Illuminate\Http\JsonResponse;

final class StrikeController extends Controller
{
    public function __construct(private readonly DriverStrikeService $service) {}

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

    public function store(AddStrikeRequest $request, User $driverUser): JsonResponse
    {
        $strike = $this->service->addManual(
            $driverUser,
            DriverStrikeReason::from((string) $request->input('reason')),
            (string) $request->input('fee', '0'),
            (int) $request->user()->id,
            is_string($request->input('notes')) ? $request->input('notes') : null,
        );

        return response()->json([
            'strike' => (new DriverStrikeResource($strike))->resolve($request),
        ], 201);
    }
}
