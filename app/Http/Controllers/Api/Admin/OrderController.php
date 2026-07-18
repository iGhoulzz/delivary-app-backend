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
use App\Support\OrderNumber\OrderNumberGenerator;
use App\Support\Resolvers\PublicIdResolver;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OrderController extends Controller
{
    public function __construct(
        private readonly AdminAssignmentService $assignments,
        private readonly CancellationService $cancellations,
        private readonly FailedDeliveryService $failures,
        private readonly OrderNumberGenerator $orderNumbers,
    ) {}

    public function index(AdminListOrdersRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $query = Order::query()->with(AdminOrderResource::LIST_RELATIONS);

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['type'])) {
            $query->where('order_type', $validated['type']);
        }

        if (isset($validated['driver_public_id'])) {
            $query->where('driver_id', PublicIdResolver::userId($validated['driver_public_id']));
        }

        if (isset($validated['merchant_public_id'])) {
            $query->where('merchant_profile_id', PublicIdResolver::merchantProfileId($validated['merchant_public_id']));
        }

        if (isset($validated['search'])) {
            $search = trim((string) $validated['search']);
            $normalized = $this->orderNumbers->normalizeSearchTerm($search);
            $query->where(static function ($q) use ($search, $normalized): void {
                $q->where('public_id', 'ilike', '%'.$search.'%')
                    ->orWhere('tracking_token', 'ilike', '%'.$search.'%')
                    ->orWhere('sender_name', 'ilike', '%'.$search.'%')
                    ->orWhere('sender_phone', 'like', '%'.$search.'%')
                    ->orWhere('receiver_name', 'ilike', '%'.$search.'%')
                    ->orWhere('receiver_phone', 'like', '%'.$search.'%');

                // Guard: normalizeSearchTerm('---') === '' — without this check the LIKE
                // below would become '%%' and match every order.
                if ($normalized !== '') {
                    $q->orWhereRaw("upper(replace(order_number, '-', '')) like ?", ['%'.$normalized.'%']);
                }
            });
        }

        return AdminOrderResource::collection(
            $query->orderByDesc('created_at')
                ->paginate((int) ($validated['per_page'] ?? 25))
        );
    }

    public function show(Order $order): AdminOrderResource
    {
        return new AdminOrderResource($order->loadMissing(AdminOrderResource::RELATIONS));
    }

    public function assign(AdminAssignOrderRequest $request, Order $order): AdminOrderResource
    {
        $driver = User::query()->findOrFail($request->driverUserId());

        $order = $this->assignments->assign(
            $request->user(),
            $order,
            $driver,
            $request->boolean('force'),
        );

        return new AdminOrderResource($order->loadMissing(AdminOrderResource::RELATIONS));
    }

    public function unassign(AdminUnassignOrderRequest $request, Order $order): AdminOrderResource
    {
        $order = $this->assignments->unassign(
            $request->user(),
            $order,
            is_string($request->input('reason')) ? $request->input('reason') : null,
            $request->boolean('reset_tier', true),
            $request->boolean('driver_fault'),
            is_string($request->input('notes')) ? $request->input('notes') : null,
            $request->filled('fee_amount_override') ? (string) $request->input('fee_amount_override') : null,
        );

        return new AdminOrderResource($order->loadMissing(AdminOrderResource::RELATIONS));
    }

    public function cancel(AdminCancelOrderRequest $request, Order $order): AdminOrderResource
    {
        $order = $this->cancellations->cancelByAdmin(
            $request->user(),
            $order,
            is_string($request->input('reason')) ? $request->input('reason') : null,
        );

        return new AdminOrderResource($order->loadMissing(AdminOrderResource::RELATIONS));
    }

    public function markDeliveryFailed(AdminMarkDeliveryFailedRequest $request, Order $order): AdminOrderResource
    {
        $order = $this->failures->markDeliveryFailedByAdmin(
            admin: $request->user(),
            order: $order,
            reason: ReturnReason::from((string) $request->input('reason')),
            notes: is_string($request->input('notes')) ? $request->input('notes') : null,
        );

        return new AdminOrderResource($order->loadMissing(AdminOrderResource::RELATIONS));
    }

    public function redirectReturn(RedirectReturnRequest $request, Order $order): AdminOrderResource
    {
        $office = OfficeLocation::query()->findOrFail($request->officeId());

        $order = $this->failures->redirectReturn(
            admin: $request->user(),
            order: $order,
            office: $office,
            reason: is_string($request->input('reason')) ? $request->input('reason') : null,
        );

        return new AdminOrderResource($order->loadMissing(AdminOrderResource::RELATIONS));
    }

    public function waiveRetrievalFees(WaiveRetrievalFeesRequest $request, Order $order): AdminOrderResource
    {
        $order = $this->failures->waiveRetrievalFees(
            admin: $request->user(),
            order: $order,
            amount: (string) $request->input('amount'),
            reason: is_string($request->input('reason')) ? $request->input('reason') : null,
        );

        return new AdminOrderResource($order->loadMissing(AdminOrderResource::RELATIONS));
    }
}
