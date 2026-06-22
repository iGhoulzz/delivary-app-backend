<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffActivityRequest;
use App\Http\Resources\Admin\StaffActivityItemResource;
use App\Models\User;
use App\Services\Reporting\StaffActivityService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

final class StaffActivityController extends Controller
{
    public function __construct(private readonly StaffActivityService $service) {}

    public function __invoke(StaffActivityRequest $request, User $staff): AnonymousResourceCollection
    {
        $perPage = (int) ($request->validated('per_page') ?? 20);
        $currentPage = LengthAwarePaginator::resolveCurrentPage();

        $all = $this->service->timeline($staff, $request->validated('kinds'));

        $total = count($all);
        $offset = ($currentPage - 1) * $perPage;
        $slice = array_slice($all, $offset, $perPage);

        $paginator = new LengthAwarePaginator(
            items: $slice,
            total: $total,
            perPage: $perPage,
            currentPage: $currentPage,
            options: ['path' => LengthAwarePaginator::resolveCurrentPath()],
        );

        return StaffActivityItemResource::collection($paginator);
    }
}
