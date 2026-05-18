<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\ListSettlementsRequest;
use App\Http\Resources\Settlement\SettlementResource;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListSettlementsController extends Controller
{
    public function __invoke(ListSettlementsRequest $request): AnonymousResourceCollection
    {
        $query = Settlement::query()->with(['driver', 'office', 'processedByStaff']);

        if ($driverPublicId = $request->input('driver_public_id')) {
            $driverId = User::query()->where('public_id', $driverPublicId)->value('id');
            $query->where('driver_id', $driverId ?? -1);
        }
        if ($officeId = $request->input('office_id')) {
            $query->where('office_id', $officeId);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($from = $request->input('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('created_at', '<=', $to);
        }

        return SettlementResource::collection(
            $query->latest('created_at')->paginate((int) $request->input('per_page', 20))
        );
    }
}
