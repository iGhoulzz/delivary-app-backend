<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ReceiveReturnRequest;
use App\Http\Resources\Order\OfficeOrderResource;
use App\Models\Order;
use App\Services\Order\FailedDeliveryService;

final class ReceiveReturnController extends Controller
{
    public function __construct(private readonly FailedDeliveryService $failures) {}

    public function __invoke(ReceiveReturnRequest $request, Order $order): OfficeOrderResource
    {
        $this->authorize('receiveReturnByOffice', $order);

        return new OfficeOrderResource($this->failures->receiveReturn(
            staff: $request->user(),
            order: $order,
            shelfLocation: is_string($request->input('shelf_location')) ? $request->input('shelf_location') : null,
            notes: is_string($request->input('notes')) ? $request->input('notes') : null,
        ));
    }
}
