<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\CreateStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\Staff\StaffResource;
use App\Models\User;
use App\Services\Staff\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class StaffController extends Controller
{
    public function __construct(private readonly StaffService $staff) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'office_staff']))
            ->with(['roles', 'activeOfficeAssignments']);

        if ($role = request('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        if ($status = request('account_status')) {
            $query->where('account_status', $status);
        }

        if ($officeId = request('office_id')) {
            $query->whereHas('activeOfficeAssignments', fn ($q) => $q->where('office_id', $officeId));
        }

        return StaffResource::collection($query->paginate((int) request('per_page', 20)));
    }

    public function show(User $staff): StaffResource
    {
        $this->authorize('view', $staff);

        return new StaffResource($staff->load(['roles', 'activeOfficeAssignments']));
    }

    public function store(CreateStaffRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $result = $this->staff->create($request->toInput(), $request->user());

        return response()->json([
            'staff' => (new StaffResource($result['user']->load('roles', 'activeOfficeAssignments')))->resolve(),
            'temporary_password' => $result['temporary_password'],
        ], 201);
    }

    public function update(UpdateStaffRequest $request, User $staff): StaffResource
    {
        $this->authorize('update', $staff);

        $updated = $this->staff->update($staff, $request->toInput());

        return new StaffResource($updated->load('roles', 'activeOfficeAssignments'));
    }

    public function suspend(User $staff): StaffResource
    {
        $this->authorize('suspend', $staff);

        return new StaffResource(
            $this->staff->suspend($staff, request()->user())->load('roles', 'activeOfficeAssignments'),
        );
    }

    public function reinstate(User $staff): StaffResource
    {
        $this->authorize('reinstate', $staff);

        return new StaffResource(
            $this->staff->reinstate($staff, request()->user())->load('roles', 'activeOfficeAssignments'),
        );
    }

    public function deactivate(User $staff): StaffResource
    {
        $this->authorize('deactivate', $staff);

        return new StaffResource(
            $this->staff->deactivate($staff, request()->user())->load('roles', 'activeOfficeAssignments'),
        );
    }

    public function resetTempPassword(User $staff): JsonResponse
    {
        $this->authorize('resetTempPassword', $staff);

        $result = $this->staff->resetTempPassword($staff, request()->user());

        return response()->json([
            'staff' => (new StaffResource($result['user']->load('roles', 'activeOfficeAssignments')))->resolve(),
            'temporary_password' => $result['temporary_password'],
        ]);
    }
}
