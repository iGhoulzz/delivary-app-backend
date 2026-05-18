<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Order;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\OfficeOrdersListRequest;
use App\Http\Resources\Order\OfficeOrderResource;
use App\Models\Order;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OrderController extends Controller
{
    public function index(OfficeOrdersListRequest $request): AnonymousResourceCollection
    {
        $assignedOfficeIds = $request->user()
            ->officeStaffAssignments()
            ->active()
            ->pluck('office_id')
            ->all();

        $query = Order::query()->whereIn('return_office_id', $assignedOfficeIds);

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        } else {
            $query->whereIn('status', [
                OrderStatus::ReturningToOffice->value,
                OrderStatus::AtOffice->value,
            ]);
        }

        return OfficeOrderResource::collection(
            $query->with(['officeInventory', 'driver.driverProfile'])
                ->orderByDesc('status_changed_at')
                ->paginate((int) $request->input('per_page', 30))
        );
    }

    public function show(Order $order): OfficeOrderResource
    {
        $this->authorize('viewByOffice', $order);

        return new OfficeOrderResource($order->load(['officeInventory', 'driver.driverProfile', 'statusLogs']));
    }
}
