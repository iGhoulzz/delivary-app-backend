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
use App\Models\User;
use App\Services\Driver\DriverDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DriverDocumentController extends Controller
{
    public function __construct(private readonly DriverDocumentService $service) {}

    public function store(UploadDriverDocumentRequest $request, User $driverUser): JsonResponse
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

    public function destroy(Request $request, User $driverUser, DriverDocumentType $documentType): Response|JsonResponse
    {
        $driverProfile = $driverUser->driverProfile;
        abort_unless($driverProfile !== null, 404);

        /** @var User $staff */
        $staff = $request->user();
        if (! $staff->can('manageInOffice', $driverProfile)) {
            return response()->json(['error' => DriverErrorCode::WrongOffice->value], DriverErrorCode::WrongOffice->httpStatus());
        }
        if ($driverProfile->status !== DriverStatus::PreRegistered) {
            return response()->json(['error' => DriverErrorCode::LockedPostSubmission->value], DriverErrorCode::LockedPostSubmission->httpStatus());
        }

        $document = DriverDocument::query()
            ->where('driver_id', $driverUser->id)
            ->where('document_type', $documentType->value)
            ->firstOrFail();

        $this->service->delete($document);

        return response()->noContent();
    }
}
