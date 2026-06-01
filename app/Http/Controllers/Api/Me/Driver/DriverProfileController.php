<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverProfileResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DriverProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->driverProfile;

        return response()->json([
            'driver_profile' => $profile === null
                ? null
                : (new DriverProfileResource($profile->loadMissing(DriverProfileResource::RELATIONS)))->resolve($request),
        ]);
    }
}
