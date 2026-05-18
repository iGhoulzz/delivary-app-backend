<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\ListSellerPayoutsRequest;
use App\Http\Resources\Settlement\SellerPayoutResource;
use App\Models\SellerPayout;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListSellerPayoutsController extends Controller
{
    public function __invoke(ListSellerPayoutsRequest $request): AnonymousResourceCollection
    {
        $staff = $request->user();
        abort_unless($staff !== null, 401);

        $assignedOfficeIds = $staff->officeStaffAssignments()
            ->active()
            ->pluck('office_id')
            ->all();

        abort_unless($assignedOfficeIds !== [], 403, 'Staff has no active office assignment.');

        $query = SellerPayout::query()
            ->whereIn('office_id', $assignedOfficeIds)
            ->with(['user', 'office', 'paidByStaff']);

        if ($from = $request->input('from')) {
            $query->where('paid_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('paid_at', '<=', $to);
        }

        return SellerPayoutResource::collection(
            $query->latest('paid_at')->paginate((int) $request->input('per_page', 20))
        );
    }
}
