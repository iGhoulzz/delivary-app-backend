<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ArrivedDropoffRequest;
use App\Http\Resources\Order\DriverOrderResource;
use App\Models\Order;
use App\Services\Order\CodeVerificationService;

final class ArrivedDropoffController extends Controller
{
    public function __construct(private readonly CodeVerificationService $codes) {}

    public function __invoke(ArrivedDropoffRequest $request, Order $order): DriverOrderResource
    {
        $this->authorize('act', $order);

        return new DriverOrderResource($this->codes->arrivedDropoff($request->user(), $order));
    }
}
