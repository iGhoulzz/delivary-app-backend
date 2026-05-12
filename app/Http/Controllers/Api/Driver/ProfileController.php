<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverProfileResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProfileController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->driverProfile;
        if ($profile === null) {
            return response()->json(['error' => 'no_driver_profile'], 404);
        }

        return response()->json([
            'driver_profile' => (new DriverProfileResource($profile))->resolve($request),
        ]);
    }
}
