<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\AttachOfficeAssignmentRequest;
use App\Http\Resources\Staff\OfficeAssignmentResource;
use App\Models\OfficeStaffAssignment;
use App\Models\User;
use App\Services\Staff\OfficeAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class OfficeAssignmentController extends Controller
{
    public function __construct(private readonly OfficeAssignmentService $service) {}

    public function store(AttachOfficeAssignmentRequest $request, User $staff): JsonResponse
    {
        Gate::authorize('manageOfficeAssignments', $staff);

        try {
            $assignment = $this->service->attach(
                $staff,
                $request->integer('office_id'),
                $request->isManager(),
            );
        } catch (RuntimeException $exception) {
            return $this->stubErrorResponse($exception);
        }

        return response()->json(
            (new OfficeAssignmentResource($assignment->load('office')))->resolve(),
            201,
        );
    }

    public function destroy(User $staff, OfficeStaffAssignment $assignment): Response|JsonResponse
    {
        Gate::authorize('manageOfficeAssignments', $staff);

        abort_unless($assignment->user_id === $staff->id, 404);

        try {
            $this->service->detach($staff, $assignment);
        } catch (RuntimeException $exception) {
            return $this->stubErrorResponse($exception);
        }

        return response()->noContent();
    }

    private function stubErrorResponse(RuntimeException $exception): JsonResponse
    {
        $code = explode(':', $exception->getMessage(), 2)[0] ?: 'STAFF_ERROR';
        $status = match ($code) {
            'OFFICE_ASSIGNMENT_DUPLICATE' => 409,
            default => 422,
        };

        return response()->json([
            'error' => $code,
            'message' => $exception->getMessage(),
        ], $status);
    }
}
