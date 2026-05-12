<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ConfirmPickupRequest;
use App\Http\Resources\Order\DriverOrderResource;
use App\Models\Order;
use App\Services\Order\CodeVerificationService;

final class ConfirmPickupController extends Controller
{
    public function __construct(private readonly CodeVerificationService $codes) {}

    public function __invoke(ConfirmPickupRequest $request, Order $order): DriverOrderResource
    {
        $this->authorize('act', $order);

        return new DriverOrderResource($this->codes->confirmPickup(
            $request->user(),
            $order,
            is_string($request->input('method')) ? $request->input('method') : null,
            is_string($request->input('code')) ? $request->input('code') : null,
        ));
    }
}
