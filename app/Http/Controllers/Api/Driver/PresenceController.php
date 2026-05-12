<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\DriverGoOfflineRequest;
use App\Http\Requests\Order\DriverGoOnlineRequest;
use App\Http\Requests\Order\DriverLocationUpdateRequest;
use App\Http\Resources\DriverProfileResource;
use App\Services\Driver\PresenceService;

final class PresenceController extends Controller
{
    public function __construct(private readonly PresenceService $presence) {}

    public function goOnline(DriverGoOnlineRequest $request): DriverProfileResource
    {
        return new DriverProfileResource($this->presence->goOnline($request->user(), $request->validated()));
    }

    public function goOffline(DriverGoOfflineRequest $request): DriverProfileResource
    {
        $validated = $request->validated();

        return new DriverProfileResource($this->presence->goOffline(
            $request->user(),
            isset($validated['reason']) ? (string) $validated['reason'] : null,
        ));
    }

    public function location(DriverLocationUpdateRequest $request): DriverProfileResource
    {
        return new DriverProfileResource($this->presence->updateLocation($request->user(), $request->validated()));
    }
}
