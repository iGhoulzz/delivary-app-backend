<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ConfirmPickupGeofenceRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class GeofenceConfirmController extends Controller
{
    public function __invoke(ConfirmPickupGeofenceRequest $request, Order $order): OrderResource
    {
        $this->authorize('confirmGeofenceBySender', $order);

        DB::transaction(function () use ($order): void {
            $order->forceFill(['pickup_geofence_confirmed_at' => now()])->save();
        });

        return new OrderResource($order->refresh()->load(['driver.driverProfile']));
    }
}
