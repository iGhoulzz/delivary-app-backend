<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver;

use App\Enums\DriverErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\UpdateRegionsRequest;
use App\Http\Resources\RegionResource;
use App\Models\User;
use App\Services\Driver\DriverRegionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RegionController extends Controller
{
    public function __construct(private readonly DriverRegionService $service) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->driverProfile;
        if ($profile === null) {
            return response()->json(['error' => 'no_driver_profile'], 404);
        }

        $available = $this->service->availableForDriver($profile);
        $selected = $user->driverRegions()->get();
        $effective = $selected->isEmpty() ? $available : $selected;

        return response()->json([
            'office' => $profile->office !== null
                ? ['id' => $profile->office->public_id, 'name' => $profile->office->name]
                : null,
            'available' => RegionResource::collection($available)->resolve($request),
            'selected' => RegionResource::collection($selected)->resolve($request),
            'effective' => RegionResource::collection($effective)->resolve($request),
        ]);
    }

    public function update(UpdateRegionsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->driverProfile;
        if ($profile === null) {
            return response()->json(['error' => 'no_driver_profile'], 404);
        }

        $regionIds = array_map('intval', (array) $request->input('region_ids', []));
        $result = $this->service->update($profile, $regionIds);

        if ($result instanceof DriverErrorCode) {
            return response()->json([
                'error' => $result->value,
                'message' => 'One or more regions are outside your office service area.',
            ], $result->httpStatus());
        }

        return $this->index($request);
    }
}
