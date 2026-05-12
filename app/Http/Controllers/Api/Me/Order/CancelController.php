<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CancelOrderRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use App\Services\Order\CancellationService;

final class CancelController extends Controller
{
    public function __construct(private readonly CancellationService $cancellations) {}

    public function __invoke(CancelOrderRequest $request, Order $order): OrderResource
    {
        $this->authorize('cancelByUser', $order);

        return new OrderResource($this->cancellations->cancelByUserFromNoDriver(
            $request->user(),
            $order,
            $request->input('reason'),
        ));
    }
}
