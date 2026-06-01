<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\DriverErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\IndexDriverRequest;
use App\Http\Resources\DriverProfileFullResource;
use App\Http\Resources\DriverProfileResource;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Driver\DriverApprovalService;
use App\Services\Driver\DriverStatusTransitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class DriverController extends Controller
{
    public function __construct(
        private readonly DriverApprovalService $approvalService,
        private readonly DriverStatusTransitionService $transitionService,
    ) {}

    public function index(IndexDriverRequest $request): AnonymousResourceCollection
    {
        $query = DriverProfile::query()
            ->with(DriverProfileResource::RELATIONS)
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', (string) $s))
            ->when($request->officeId(), fn ($q, $id) => $q->where('office_id', $id))
            ->orderByRaw("CASE status WHEN 'pending_approval' THEN 0 ELSE 1 END")
            ->oldest();

        if ($search = $request->input('search')) {
            $query->whereHas('user', fn ($u) => $u
                ->where('phone_number', 'like', '%'.$search.'%')
                ->orWhere('first_name', 'ilike', '%'.$search.'%')
                ->orWhere('last_name', 'ilike', '%'.$search.'%'));
        }

        return DriverProfileResource::collection($query->paginate(25));
    }

    public function show(Request $request, User $driverUser): JsonResponse
    {
        $driverProfile = $driverUser->driverProfile;
        abort_unless($driverProfile !== null, 404);

        $driverProfile->loadMissing(DriverProfileFullResource::RELATIONS);

        return response()->json([
            'driver_profile' => (new DriverProfileFullResource($driverProfile))->resolve($request),
        ]);
    }

    public function approve(Request $request, User $driverUser): JsonResponse
    {
        $driverProfile = $driverUser->driverProfile;
        abort_unless($driverProfile !== null, 404);

        /** @var User $admin */
        $admin = $request->user();
        $result = $this->approvalService->approve($driverProfile, $admin);

        if ($result instanceof DriverErrorCode) {
            return response()->json([
                'error' => $result->value,
                'message' => 'Driver is not in pending_approval state.',
            ], $result->httpStatus());
        }

        return response()->json([
            'driver_profile' => (new DriverProfileFullResource($result->loadMissing(DriverProfileFullResource::RELATIONS)))->resolve($request),
        ]);
    }

    public function reject(Request $request, User $driverUser): JsonResponse
    {
        $driverProfile = $driverUser->driverProfile;
        abort_unless($driverProfile !== null, 404);

        return $this->respondWithTransition($request, $this->transitionService->reject($driverProfile));
    }

    public function suspend(Request $request, User $driverUser): JsonResponse
    {
        $driverProfile = $driverUser->driverProfile;
        abort_unless($driverProfile !== null, 404);

        return $this->respondWithTransition($request, $this->transitionService->suspend($driverProfile));
    }

    public function reinstate(Request $request, User $driverUser): JsonResponse
    {
        $driverProfile = $driverUser->driverProfile;
        abort_unless($driverProfile !== null, 404);

        return $this->respondWithTransition($request, $this->transitionService->reinstate($driverProfile));
    }

    private function respondWithTransition(Request $request, DriverErrorCode|DriverProfile $result): JsonResponse
    {
        if ($result instanceof DriverErrorCode) {
            return response()->json([
                'error' => $result->value,
                'message' => 'Transition not allowed from current state.',
            ], $result->httpStatus());
        }

        return response()->json([
            'driver_profile' => (new DriverProfileResource($result->loadMissing(DriverProfileResource::RELATIONS)))->resolve($request),
        ]);
    }
}
