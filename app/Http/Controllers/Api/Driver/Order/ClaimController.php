<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ClaimOrderRequest;
use App\Http\Resources\Order\DriverOrderResource;
use App\Models\Order;
use App\Services\Order\ClaimService;

final class ClaimController extends Controller
{
    public function __construct(private readonly ClaimService $claims) {}

    public function __invoke(ClaimOrderRequest $request, Order $order): DriverOrderResource
    {
        return new DriverOrderResource($this->claims->claim($request->user(), $order));
    }
}
