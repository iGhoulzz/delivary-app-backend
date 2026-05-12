<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrderStatus;

final class OrderDisplayStatus
{
    /**
     * Collapse the internal 16-state machine into the customer-facing surface.
     * The DB always holds the granular state; this is presentation only.
     */
    public static function fromInternal(OrderStatus $s): string
    {
        return match ($s) {
            OrderStatus::Created => 'creating',
            OrderStatus::AwaitingDriver => 'awaiting_driver',
            OrderStatus::NoDriverAvailable => 'no_driver_available',
            OrderStatus::Assigned,
            OrderStatus::DriverEnRoutePickup => 'assigned',
            OrderStatus::PickedUp,
            OrderStatus::DriverEnRouteDropoff => 'picked_up',
            OrderStatus::DeliveryInProgress => 'delivery_in_progress',
            OrderStatus::Delivered => 'delivered',
            OrderStatus::DeliveryFailed,
            OrderStatus::ReturningToOffice,
            OrderStatus::AtOffice,
            OrderStatus::RetrievedBySeller,
            OrderStatus::Abandoned => 'failed',          // sub-project D will refine this
            OrderStatus::CancelledByUser,
            OrderStatus::CancelledByAdmin => 'cancelled',
        };
    }
}
