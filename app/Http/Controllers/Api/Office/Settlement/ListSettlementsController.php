<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\ListOfficeSettlementsRequest;
use App\Http\Resources\Settlement\SettlementResource;
use App\Models\Settlement;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListSettlementsController extends Controller
{
    public function __invoke(ListOfficeSettlementsRequest $request): AnonymousResourceCollection
    {
        $staff = $request->user();
        abort_unless($staff !== null, 401);

        $assignedOfficeIds = $staff->officeStaffAssignments()
            ->active()
            ->pluck('office_id')
            ->all();

        abort_unless($assignedOfficeIds !== [], 403, 'Staff has no active office assignment.');

        $query = Settlement::query()
            ->whereIn('office_id', $assignedOfficeIds)
            ->with(['driver', 'office', 'processedByStaff']);

        if ($from = $request->input('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('created_at', '<=', $to);
        }

        $perPage = (int) $request->input('per_page', 20);

        return SettlementResource::collection(
            $query->latest('created_at')->paginate($perPage)
        );
    }
}
