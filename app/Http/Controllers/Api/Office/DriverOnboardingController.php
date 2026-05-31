<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office;

use App\Enums\DriverErrorCode;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\LookupDriverRequest;
use App\Http\Requests\Driver\OnboardDriverRequest;
use App\Http\Requests\Driver\VerifyDriverPhoneRequest;
use App\Http\Resources\DriverProfileResource;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Driver\DriverOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class DriverOnboardingController extends Controller
{
    public function __construct(private readonly DriverOnboardingService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $staff */
        $staff = $request->user();
        $officeId = $this->staffOfficeId($staff);

        $statusFilter = $request->input('status');
        $defaultStatuses = ['pre_registered', 'pending_approval'];

        $query = DriverProfile::query()
            ->where('office_id', $officeId)
            ->when(
                $statusFilter !== null,
                fn ($q) => $q->where('status', (string) $statusFilter),
                fn ($q) => $q->whereIn('status', $defaultStatuses),
            )
            ->latest();

        if ($search = $request->input('search')) {
            $query->whereHas('user', fn ($u) => $u
                ->where('phone_number', 'like', '%'.$search.'%')
                ->orWhere('first_name', 'ilike', '%'.$search.'%')
                ->orWhere('last_name', 'ilike', '%'.$search.'%'));
        }

        return DriverProfileResource::collection($query->paginate(25));
    }

    public function lookup(LookupDriverRequest $request): JsonResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        $officeId = $this->staffOfficeId($staff);

        $result = $this->service->findByPhoneForOffice(
            (string) $request->input('phone_number'),
            $officeId,
        );

        return response()->json([
            'user_exists' => $result['user_exists'],
            'user_phone_verified' => $result['user_phone_verified'],
            'driver_profile' => $result['driver_profile']
                ? (new DriverProfileResource($result['driver_profile']))->resolve($request)
                : null,
            'can_onboard' => $result['can_onboard'],
            'reason_if_not' => $result['reason_if_not'],
        ]);
    }

    public function onboard(OnboardDriverRequest $request): JsonResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        $officeId = $this->staffOfficeId($staff);

        $result = $this->service->onboard($officeId, $request->validated());

        if ($result instanceof DriverErrorCode) {
            return response()->json([
                'error' => $result->value,
                'message' => 'Cannot onboard this driver under your office.',
            ], $result->httpStatus());
        }

        return response()->json([
            'driver_profile' => (new DriverProfileResource($result['driver_profile']))->resolve($request),
            'otp_required' => $result['otp_required'],
            'otp_expires_at' => $result['otp_expires_at']?->toIso8601String(),
        ], 201);
    }

    public function verifyPhone(
        VerifyDriverPhoneRequest $request,
        User $driverUser,
        OtpService $otp,
    ): Response|JsonResponse {
        $driverProfile = $driverUser->driverProfile;
        abort_unless($driverProfile !== null, 404);

        /** @var User $staff */
        $staff = $request->user();

        if (! $staff->can('manageInOffice', $driverProfile)) {
            return response()->json([
                'error' => DriverErrorCode::WrongOffice->value,
                'message' => 'Driver belongs to a different office.',
            ], DriverErrorCode::WrongOffice->httpStatus());
        }

        $user = $driverProfile->user;
        if ($user->phone_verified_at !== null) {
            return response()->noContent(); // idempotent
        }

        $verified = $otp->verify(
            $user->phone_number,
            (string) $request->input('code'),
            OtpPurpose::Registration,
        );

        if (! $verified) {
            return response()->json([
                'error' => DriverErrorCode::OtpInvalid->value,
                'message' => 'OTP is invalid or expired.',
            ], DriverErrorCode::OtpInvalid->httpStatus());
        }

        $user->phone_verified_at = now();
        $user->save();

        return response()->noContent();
    }

    public function submit(Request $request, User $driverUser): JsonResponse
    {
        $driverProfile = $driverUser->driverProfile;
        abort_unless($driverProfile !== null, 404);

        /** @var User $staff */
        $staff = $request->user();
        if (! $staff->can('manageInOffice', $driverProfile)) {
            return response()->json([
                'error' => DriverErrorCode::WrongOffice->value,
                'message' => 'Driver belongs to a different office.',
            ], DriverErrorCode::WrongOffice->httpStatus());
        }

        $result = $this->service->submitForApproval($driverProfile);
        if ($result instanceof DriverErrorCode) {
            $body = ['error' => $result->value];
            if ($result === DriverErrorCode::MissingDocuments) {
                $body['missing'] = $this->service->missingDocumentsFor($driverProfile);
                $body['message'] = 'Cannot submit — required documents are missing.';
            } else {
                $body['message'] = match ($result) {
                    DriverErrorCode::PhoneNotVerified => 'Driver\'s phone is not verified yet.',
                    DriverErrorCode::InvalidState => 'Driver is not in a state where they can be submitted.',
                    default => 'Submission rejected.',
                };
            }

            return response()->json($body, $result->httpStatus());
        }

        return response()->json([
            'driver_profile' => (new DriverProfileResource($result['driver_profile']))->resolve($request),
        ]);
    }

    /**
     * Returns the office_id that the authenticated staff member is assigned
     * to. Aborts with 403 if they have no active assignment.
     */
    protected function staffOfficeId(User $staff): int
    {
        $assignment = $staff->officeStaffAssignments()
            ->whereNull('removed_at')
            ->first();

        abort_if($assignment === null, 403, 'Staff member has no active office assignment.');

        return $assignment->office_id;
    }
}
