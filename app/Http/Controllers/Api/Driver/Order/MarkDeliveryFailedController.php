<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver\Order;

use App\Enums\ReturnReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\MarkDeliveryFailedRequest;
use App\Http\Resources\Order\DriverOrderResource;
use App\Models\Order;
use App\Services\Order\FailedDeliveryService;

final class MarkDeliveryFailedController extends Controller
{
    public function __construct(private readonly FailedDeliveryService $failures) {}

    public function __invoke(MarkDeliveryFailedRequest $request, Order $order): DriverOrderResource
    {
        $this->authorize('markDeliveryFailedByDriver', $order);

        return new DriverOrderResource($this->failures->markDeliveryFailedByDriver(
            driver: $request->user(),
            order: $order,
            reason: ReturnReason::from((string) $request->input('reason')),
            notes: is_string($request->input('notes')) ? $request->input('notes') : null,
        ));
    }
}
