<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Driver;

use App\Enums\AccountStatus;
use App\Enums\DriverDocumentType;
use App\Enums\DriverErrorCode;
use App\Enums\DriverStatus;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\AdminDriverLookupRequest;
use App\Http\Requests\Driver\AdminOnboardDriverRequest;
use App\Http\Requests\Driver\AdminUploadDriverDocumentRequest;
use App\Http\Requests\Driver\AdminVerifyDriverPhoneRequest;
use App\Http\Resources\DriverDocumentResource;
use App\Http\Resources\DriverProfileResource;
use App\Models\DriverDocument;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Driver\DriverDocumentService;
use App\Services\Driver\DriverOnboardingService;
use App\Support\Resolvers\PublicIdResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class OnboardingController extends Controller
{
    public function __construct(
        private readonly DriverOnboardingService $onboarding,
        private readonly DriverDocumentService $documents,
    ) {}

    public function lookup(AdminDriverLookupRequest $request): JsonResponse
    {
        $phone = (string) $request->input('phone_number');
        $officeId = PublicIdResolver::officeId($request->input('office_public_id'));

        if ($officeId !== null) {
            $result = $this->onboarding->findByPhoneForOffice($phone, $officeId);

            return response()->json([
                'user_exists' => $result['user_exists'],
                'user_phone_verified' => $result['user_phone_verified'],
                'driver_profile' => $result['driver_profile']
                    ? (new DriverProfileResource($result['driver_profile']->loadMissing(DriverProfileResource::RELATIONS)))->resolve($request)
                    : null,
                'can_onboard' => $result['can_onboard'],
                'reason_if_not' => $result['reason_if_not'],
            ]);
        }

        $user = User::query()
            ->with('driverProfile')
            ->where('phone_number', $phone)
            ->first();

        if ($user === null) {
            return response()->json([
                'user_exists' => false,
                'user_phone_verified' => false,
                'driver_profile' => null,
                'can_onboard' => true,
                'reason_if_not' => null,
            ]);
        }

        $profile = $user->driverProfile;

        return response()->json([
            'user_exists' => true,
            'user_phone_verified' => $user->phone_verified_at !== null,
            'driver_profile' => $profile
                ? (new DriverProfileResource($profile->loadMissing(DriverProfileResource::RELATIONS)))->resolve($request)
                : null,
            'can_onboard' => $profile === null && ! $user->hasRole('driver'),
            'reason_if_not' => $profile !== null || $user->hasRole('driver') ? DriverErrorCode::DriverProfileExists->value : null,
        ]);
    }

    public function onboard(AdminOnboardDriverRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $officeId = PublicIdResolver::officeId((string) $validated['office_public_id']);

        $payload = [
            'phone_number' => (string) ($validated['phone_number'] ?? ''),
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'vehicle_type' => (string) $validated['vehicle_type'],
            'vehicle_plate' => (string) $validated['vehicle_plate'],
            'vehicle_color' => $validated['vehicle_color'] ?? null,
            'vehicle_model' => $validated['vehicle_model'] ?? null,
        ];

        if ($validated['mode'] === 'existing') {
            $user = User::query()
                ->where('public_id', $validated['user_public_id'])
                ->firstOrFail();

            if ($user->account_status === AccountStatus::Banned) {
                return response()->json([
                    'error' => 'account_not_eligible',
                    'message' => 'Banned users cannot be onboarded as drivers.',
                ], 422);
            }

            if ($user->driverProfile !== null || $user->hasRole('driver')) {
                return response()->json([
                    'error' => DriverErrorCode::DriverProfileExists->value,
                    'message' => 'This user is already a driver.',
                ], 422);
            }

            $payload['phone_number'] = $user->phone_number;
            $payload['first_name'] = $user->first_name;
            $payload['last_name'] = $user->last_name;
        }

        $result = $this->onboarding->onboard($officeId, $payload);
        if ($result instanceof DriverErrorCode) {
            return $this->driverError($result);
        }

        return response()->json([
            'driver_profile' => (new DriverProfileResource($result['driver_profile']->loadMissing(DriverProfileResource::RELATIONS)))->resolve($request),
            'otp_required' => $result['otp_required'],
            'otp_expires_at' => $result['otp_expires_at']?->toIso8601String(),
        ], 201);
    }

    public function verifyPhone(
        AdminVerifyDriverPhoneRequest $request,
        User $driverUser,
        OtpService $otp,
    ): Response|JsonResponse {
        $profile = $driverUser->driverProfile;
        abort_unless($profile !== null, 404);

        if ($driverUser->phone_verified_at !== null) {
            return response()->noContent();
        }

        $verified = $otp->verify(
            $driverUser->phone_number,
            (string) $request->input('code'),
            OtpPurpose::Registration,
        );

        if (! $verified) {
            return $this->driverError(DriverErrorCode::OtpInvalid);
        }

        $driverUser->phone_verified_at = now();
        $driverUser->save();

        return response()->noContent();
    }

    public function storeDocument(AdminUploadDriverDocumentRequest $request, User $driverUser): JsonResponse
    {
        $profile = $driverUser->driverProfile;
        abort_unless($profile !== null, 404);

        if ($profile->status !== DriverStatus::PreRegistered) {
            return $this->driverError(DriverErrorCode::LockedPostSubmission);
        }

        $document = $this->documents->upload(
            $profile,
            DriverDocumentType::from((string) $request->input('type')),
            $request->file('file'),
            $request->input('expires_at'),
            $request->input('notes'),
        );

        return response()->json([
            'driver_document' => (new DriverDocumentResource($document))->resolve($request),
        ], 201);
    }

    public function destroyDocument(Request $request, User $driverUser, DriverDocumentType $documentType): Response|JsonResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);

        $profile = $driverUser->driverProfile;
        abort_unless($profile !== null, 404);

        if ($profile->status !== DriverStatus::PreRegistered) {
            return $this->driverError(DriverErrorCode::LockedPostSubmission);
        }

        $document = DriverDocument::query()
            ->where('driver_id', $driverUser->id)
            ->where('document_type', $documentType->value)
            ->firstOrFail();

        $this->documents->delete($document);

        return response()->noContent();
    }

    public function submit(Request $request, User $driverUser): JsonResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);

        $profile = $driverUser->driverProfile;
        abort_unless($profile !== null, 404);

        $result = $this->onboarding->submitForApproval($profile);
        if ($result instanceof DriverErrorCode) {
            $body = ['error' => $result->value];
            if ($result === DriverErrorCode::MissingDocuments) {
                $body['missing'] = $this->onboarding->missingDocumentsFor($profile);
                $body['message'] = 'Cannot submit - required documents are missing.';
            } else {
                $body['message'] = match ($result) {
                    DriverErrorCode::PhoneNotVerified => 'Driver phone is not verified yet.',
                    DriverErrorCode::InvalidState => 'Driver is not in a state where they can be submitted.',
                    default => 'Submission rejected.',
                };
            }

            return response()->json($body, $result->httpStatus());
        }

        return response()->json([
            'driver_profile' => (new DriverProfileResource($result['driver_profile']->loadMissing(DriverProfileResource::RELATIONS)))->resolve($request),
        ]);
    }

    private function driverError(DriverErrorCode $error): JsonResponse
    {
        return response()->json([
            'error' => $error->value,
            'message' => 'Driver onboarding action was rejected.',
        ], $error->httpStatus());
    }
}
