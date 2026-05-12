<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Http\Resources\Order\GuestTrackingResource;
use App\Models\Order;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GuestTrackingController extends Controller
{
    public function __invoke(string $trackingToken): GuestTrackingResource
    {
        $order = Order::query()
            ->with(['driver.driverProfile'])
            ->where('tracking_token', $trackingToken)
            ->first();

        if ($order === null) {
            throw new NotFoundHttpException;
        }

        return new GuestTrackingResource($order);
    }
}
