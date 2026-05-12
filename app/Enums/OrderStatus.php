<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    // Pre-pickup
    case Created = 'created';
    case AwaitingDriver = 'awaiting_driver';
    case NoDriverAvailable = 'no_driver_available';
    case Assigned = 'assigned';

    // In transit
    case DriverEnRoutePickup = 'driver_en_route_pickup';
    case PickedUp = 'picked_up';
    case DriverEnRouteDropoff = 'driver_en_route_dropoff';
    case DeliveryInProgress = 'delivery_in_progress';

    // Terminal — happy path
    case Delivered = 'delivered';

    // Failure & return
    case DeliveryFailed = 'delivery_failed';
    case ReturningToOffice = 'returning_to_office';
    case AtOffice = 'at_office';
    case RetrievedBySeller = 'retrieved_by_seller';
    case Abandoned = 'abandoned';

    // Cancellation
    case CancelledByUser = 'cancelled_by_user';
    case CancelledByAdmin = 'cancelled_by_admin';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::AwaitingDriver => 'Awaiting Driver',
            self::NoDriverAvailable => 'No Driver Available',
            self::Assigned => 'Assigned',
            self::DriverEnRoutePickup => 'Driver En Route to Pickup',
            self::PickedUp => 'Picked Up',
            self::DriverEnRouteDropoff => 'Driver En Route to Dropoff',
            self::DeliveryInProgress => 'Delivery in Progress',
            self::Delivered => 'Delivered',
            self::DeliveryFailed => 'Delivery Failed',
            self::ReturningToOffice => 'Returning to Office',
            self::AtOffice => 'At Office',
            self::RetrievedBySeller => 'Retrieved by Seller',
            self::Abandoned => 'Abandoned',
            self::CancelledByUser => 'Cancelled (by User)',
            self::CancelledByAdmin => 'Cancelled (by Admin)',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Delivered,
            self::RetrievedBySeller,
            self::Abandoned,
            self::CancelledByUser,
            self::CancelledByAdmin,
        ], true);
    }

    public function isCancellation(): bool
    {
        return $this === self::CancelledByUser || $this === self::CancelledByAdmin;
    }

    public function isReturnFlow(): bool
    {
        return in_array($this, [
            self::DeliveryFailed,
            self::ReturningToOffice,
            self::AtOffice,
            self::RetrievedBySeller,
            self::Abandoned,
        ], true);
    }

    public function isPrePickup(): bool
    {
        return in_array($this, [
            self::Created,
            self::AwaitingDriver,
            self::NoDriverAvailable,
            self::Assigned,
            self::DriverEnRoutePickup,
        ], true);
    }

    /**
     * Allowed forward transitions from this status.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Created => [self::AwaitingDriver, self::CancelledByUser, self::CancelledByAdmin],
            self::AwaitingDriver => [self::Assigned, self::NoDriverAvailable, self::CancelledByUser, self::CancelledByAdmin],
            self::NoDriverAvailable => [self::AwaitingDriver, self::CancelledByUser, self::CancelledByAdmin],
            self::Assigned => [self::DriverEnRoutePickup, self::CancelledByUser, self::CancelledByAdmin],
            self::DriverEnRoutePickup => [self::PickedUp, self::CancelledByUser, self::CancelledByAdmin],
            self::PickedUp => [self::DriverEnRouteDropoff, self::CancelledByUser, self::CancelledByAdmin],
            self::DriverEnRouteDropoff => [self::DeliveryInProgress, self::CancelledByAdmin],
            self::DeliveryInProgress => [self::Delivered, self::DeliveryFailed, self::CancelledByAdmin],
            self::DeliveryFailed => [self::ReturningToOffice, self::CancelledByAdmin],
            self::ReturningToOffice => [self::AtOffice],
            self::AtOffice => [self::RetrievedBySeller, self::Abandoned],
            // Terminal states
            self::Delivered, self::RetrievedBySeller, self::Abandoned,
            self::CancelledByUser, self::CancelledByAdmin => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
