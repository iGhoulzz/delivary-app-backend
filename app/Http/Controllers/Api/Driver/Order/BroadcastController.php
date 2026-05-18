<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\DriverBroadcastRequest;
use App\Http\Requests\Order\DriverCurrentOrderRequest;
use App\Http\Resources\Order\BroadcastOrderResource;
use App\Http\Resources\Order\DriverOrderResource;
use App\Models\Order;
use App\Services\Order\BroadcastService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class BroadcastController extends Controller
{
    public function __construct(private readonly BroadcastService $broadcasts) {}

    public function index(DriverBroadcastRequest $request): AnonymousResourceCollection
    {
        return BroadcastOrderResource::collection($this->broadcasts->candidatesFor($request->user()));
    }

    public function current(DriverCurrentOrderRequest $request): DriverOrderResource|Response
    {
        $order = Order::query()
            ->with(['driver.driverProfile'])
            ->forDriver($request->user()->id)
            ->activeForDriver()
            ->latest('status_changed_at')
            ->first();

        if ($order === null) {
            return response()->noContent();
        }

        return new DriverOrderResource($order);
    }
}
