<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ReturnReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\AdminAssignOrderRequest;
use App\Http\Requests\Order\AdminCancelOrderRequest;
use App\Http\Requests\Order\AdminListOrdersRequest;
use App\Http\Requests\Order\AdminMarkDeliveryFailedRequest;
use App\Http\Requests\Order\AdminUnassignOrderRequest;
use App\Http\Requests\Order\RedirectReturnRequest;
use App\Http\Requests\Order\WaiveRetrievalFeesRequest;
use App\Http\Resources\Order\AdminOrderResource;
use App\Models\OfficeLocation;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\AdminAssignmentService;
use App\Services\Order\CancellationService;
use App\Services\Order\FailedDeliveryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OrderController extends Controller
{
    public function __construct(
        private readonly AdminAssignmentService $assignments,
        private readonly CancellationService $cancellations,
        private readonly FailedDeliveryService $failures,
    ) {}

    public function index(AdminListOrdersRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $query = Order::query()->with(['sender', 'receiverUser', 'receiverGuest', 'driver.driverProfile', 'returnOffice']);

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
        return new AdminOrderResource($order->load(['sender', 'receiverUser', 'receiverGuest', 'driver.driverProfile', 'returnOffice', 'statusLogs.actor']));
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
            $request->boolean('driver_fault'),
            is_string($request->input('notes')) ? $request->input('notes') : null,
            $request->filled('fee_amount_override') ? (string) $request->input('fee_amount_override') : null,
        ));
    }

    public function cancel(AdminCancelOrderRequest $request, Order $order): AdminOrderResource
    {
        return new AdminOrderResource($this->cancellations->cancelByAdmin(
            $request->user(),
            $order,
            is_string($request->input('reason')) ? $request->input('reason') : null,
        ));
    }

    public function markDeliveryFailed(AdminMarkDeliveryFailedRequest $request, Order $order): AdminOrderResource
    {
        return new AdminOrderResource($this->failures->markDeliveryFailedByAdmin(
            admin: $request->user(),
            order: $order,
            reason: ReturnReason::from((string) $request->input('reason')),
            notes: is_string($request->input('notes')) ? $request->input('notes') : null,
        )->load(['statusLogs.actor', 'driver.driverProfile']));
    }

    public function redirectReturn(RedirectReturnRequest $request, Order $order): AdminOrderResource
    {
        $office = OfficeLocation::query()->findOrFail((int) $request->input('office_id'));

        return new AdminOrderResource($this->failures->redirectReturn(
            admin: $request->user(),
            order: $order,
            office: $office,
            reason: is_string($request->input('reason')) ? $request->input('reason') : null,
        )->load(['statusLogs.actor']));
    }

    public function waiveRetrievalFees(WaiveRetrievalFeesRequest $request, Order $order): AdminOrderResource
    {
        return new AdminOrderResource($this->failures->waiveRetrievalFees(
            admin: $request->user(),
            order: $order,
            amount: (string) $request->input('amount'),
            reason: is_string($request->input('reason')) ? $request->input('reason') : null,
        )->load(['officeInventory', 'statusLogs.actor']));
    }
}
