<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office;

use App\Enums\DriverDocumentType;
use App\Enums\DriverErrorCode;
use App\Enums\DriverStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\UploadDriverDocumentRequest;
use App\Http\Resources\DriverDocumentResource;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Driver\DriverDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DriverDocumentController extends Controller
{
    public function __construct(private readonly DriverDocumentService $service) {}

    public function store(UploadDriverDocumentRequest $request, DriverProfile $driverProfile): JsonResponse
    {
        /** @var User $staff */
        $staff = $request->user();

        if (! $staff->can('manageInOffice', $driverProfile)) {
            return response()->json([
                'error' => DriverErrorCode::WrongOffice->value,
                'message' => 'Driver belongs to a different office.',
            ], DriverErrorCode::WrongOffice->httpStatus());
        }
        if ($driverProfile->status !== DriverStatus::PreRegistered) {
            return response()->json([
                'error' => DriverErrorCode::LockedPostSubmission->value,
                'message' => 'Documents are locked once submitted for approval.',
            ], DriverErrorCode::LockedPostSubmission->httpStatus());
        }

        $document = $this->service->upload(
            $driverProfile,
            DriverDocumentType::from((string) $request->input('type')),
            $request->file('file'),
            $request->input('expires_at'),
            $request->input('notes'),
        );

        return response()->json([
            'driver_document' => (new DriverDocumentResource($document))->resolve($request),
        ], 201);
    }

    public function destroy(Request $request, DriverProfile $driverProfile, DriverDocument $driverDocument): Response|JsonResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        if (! $staff->can('manageInOffice', $driverProfile)) {
            return response()->json(['error' => DriverErrorCode::WrongOffice->value], DriverErrorCode::WrongOffice->httpStatus());
        }
        if ($driverProfile->status !== DriverStatus::PreRegistered) {
            return response()->json(['error' => DriverErrorCode::LockedPostSubmission->value], DriverErrorCode::LockedPostSubmission->httpStatus());
        }
        if ($driverDocument->driver_id !== $driverProfile->user_id) {
            return response()->json(['error' => 'mismatch'], 403);
        }

        $this->service->delete($driverDocument);

        return response()->noContent();
    }
}
