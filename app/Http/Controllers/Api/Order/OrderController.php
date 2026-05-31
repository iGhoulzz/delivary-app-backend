<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CreateOrderRequest;
use App\Http\Requests\Order\ListOrdersRequest;
use App\Http\Requests\Order\ShowOrderRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use App\Services\Order\CreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OrderController extends Controller
{
    public function __construct(private readonly CreationService $creation) {}

    public function store(CreateOrderRequest $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        $order = $this->creation->create(
            $request->user(),
            $request->validated(),
            is_string($idempotencyKey) && $idempotencyKey !== '' ? $idempotencyKey : null,
        );

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    public function index(ListOrdersRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $validated = $request->validated();
        $role = (string) ($validated['role'] ?? 'all');
        $status = $validated['status'] ?? null;

        $query = Order::query()
            ->with(['driver.driverProfile', 'returnOffice'])
            ->where(function ($query) use ($user, $role): void {
                if ($role === 'sent') {
                    $query->where('sender_user_id', $user->id);

                    return;
                }

                if ($role === 'received') {
                    $query->where('receiver_user_id', $user->id);

                    return;
                }

                $query->where('sender_user_id', $user->id)
                    ->orWhere('receiver_user_id', $user->id);
            });

        if ($status === 'active') {
            $query->active();
        } elseif (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $orders = $query
            ->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return OrderResource::collection($orders);
    }

    public function show(ShowOrderRequest $request, Order $order): OrderResource
    {
        $this->authorize('view', $order);

        return new OrderResource($order->load(['driver.driverProfile', 'returnOffice']));
    }
}
