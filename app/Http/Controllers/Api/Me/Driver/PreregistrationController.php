<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me\Driver;

use App\Enums\DriverErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\PreregisterDriverRequest;
use App\Http\Resources\DriverProfileResource;
use App\Models\User;
use App\Services\Driver\DriverPreregistrationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class PreregistrationController extends Controller
{
    public function __construct(private readonly DriverPreregistrationService $service) {}

    public function __invoke(PreregisterDriverRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $this->service->preregister($user, [
            ...$request->validated(),
            'office_id' => $request->officeId(),
        ]);

        if ($result instanceof DriverErrorCode) {
            return response()->json([
                'error' => $result->value,
                'message' => 'You already have a driver profile.',
            ], $result->httpStatus());
        }

        return response()->json([
            'driver_profile' => (new DriverProfileResource($result->loadMissing(DriverProfileResource::RELATIONS)))->resolve($request),
        ], Response::HTTP_CREATED);
    }
}
