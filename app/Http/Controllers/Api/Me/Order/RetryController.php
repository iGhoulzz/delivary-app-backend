<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\RetryOrderRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use App\Services\Order\RetryService;

final class RetryController extends Controller
{
    public function __construct(private readonly RetryService $retries) {}

    public function __invoke(RetryOrderRequest $request, Order $order): OrderResource
    {
        $this->authorize('retryByUser', $order);

        return new OrderResource($this->retries->retry($request->user(), $order));
    }
}
