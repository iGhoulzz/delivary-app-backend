<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\RetrieveOrderRequest;
use App\Http\Resources\Order\OfficeOrderResource;
use App\Models\Order;
use App\Services\Order\FailedDeliveryService;

final class RetrieveOrderController extends Controller
{
    public function __construct(private readonly FailedDeliveryService $failures) {}

    public function __invoke(RetrieveOrderRequest $request, Order $order): OfficeOrderResource
    {
        $this->authorize('retrieveByOffice', $order);

        $order = $this->failures->retrieve(
            staff: $request->user(),
            order: $order,
            cashCollected: $request->normalizedCashCollected(),
            notes: is_string($request->input('notes')) ? $request->input('notes') : null,
        );

        return new OfficeOrderResource($order->loadMissing(OfficeOrderResource::RELATIONS));
    }
}
