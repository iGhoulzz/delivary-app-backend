<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderBroadcastWithdrawn;
use App\Events\OrderStatusChanged;
use App\Services\Order\BroadcastService;

/**
 * Maps "order left awaiting_driver" to per-driver OrderBroadcastWithdrawn
 * events so subscribed drivers can drop the order from their local list.
 * The reason is derived from the transition target.
 */
final class BroadcastWithdrawnOnExit
{
    public function __construct(private readonly BroadcastService $broadcasts) {}

    public function handle(OrderStatusChanged $event): void
    {
        if ($event->fromStatus !== OrderStatus::AwaitingDriver) {
            return;
        }
        if ($event->toStatus === OrderStatus::AwaitingDriver) {
            return;
        }

        $reason = $this->reasonFor($event->toStatus);

        foreach ($this->broadcasts->eligibleDriversFor($event->order) as $profile) {
            event(new OrderBroadcastWithdrawn(
                orderPublicId: $event->order->public_id,
                driverId: (int) $profile->user_id,
                reason: $reason,
            ));
        }
    }

    private function reasonFor(OrderStatus $to): string
    {
        return match ($to) {
            OrderStatus::Assigned, OrderStatus::DriverEnRoutePickup => 'claimed',
            OrderStatus::NoDriverAvailable => 'timeout',
            OrderStatus::CancelledByUser, OrderStatus::CancelledByAdmin => 'cancelled',
            default => $to->value,
        };
    }
}
