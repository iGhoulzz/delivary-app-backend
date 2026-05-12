<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\AdminAssignOrderRequest;
use App\Http\Requests\Order\AdminListOrdersRequest;
use App\Http\Requests\Order\AdminUnassignOrderRequest;
use App\Http\Resources\Order\AdminOrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\AdminAssignmentService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OrderController extends Controller
{
    public function __construct(private readonly AdminAssignmentService $assignments) {}

    public function index(AdminListOrdersRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $query = Order::query()->with(['driver.driverProfile']);

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['type'])) {
            $query->where('order_type', $validated['type']);
        }

        return AdminOrderResource::collection(
            $query->orderByDesc('created_at')
                ->paginate((int) ($validated['per_page'] ?? 25))
        );
    }

    public function show(Order $order): AdminOrderResource
    {
        return new AdminOrderResource($order->load(['driver.driverProfile', 'statusLogs']));
    }

    public function assign(AdminAssignOrderRequest $request, Order $order): AdminOrderResource
    {
        $driver = User::query()->findOrFail($request->integer('driver_id'));

        return new AdminOrderResource($this->assignments->assign(
            $request->user(),
            $order,
            $driver,
            $request->boolean('force'),
        ));
    }

    public function unassign(AdminUnassignOrderRequest $request, Order $order): AdminOrderResource
    {
        return new AdminOrderResource($this->assignments->unassign(
            $request->user(),
            $order,
            is_string($request->input('reason')) ? $request->input('reason') : null,
            $request->boolean('reset_tier', true),
        ));
    }
}
