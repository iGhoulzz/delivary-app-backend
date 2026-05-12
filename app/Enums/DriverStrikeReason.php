<?php

declare(strict_types=1);

namespace App\Enums;

enum DriverStrikeReason: string
{
    case AcceptThenCancel = 'accept_then_cancel';
    case NoShowAtPickup = 'no_show_at_pickup';
    case NoShowAtDelivery = 'no_show_at_delivery';
    case AbandonedOrder = 'abandoned_order';
    case RepeatedLateness = 'repeated_lateness';
    case CustomerComplaint = 'customer_complaint';
    case ManualAdmin = 'manual_admin';

    public function label(): string
    {
        return match ($this) {
            self::AcceptThenCancel => 'Accepted then Cancelled',
            self::NoShowAtPickup => 'No-show at Pickup',
            self::NoShowAtDelivery => 'No-show at Delivery',
            self::AbandonedOrder => 'Abandoned Order',
            self::RepeatedLateness => 'Repeated Lateness',
            self::CustomerComplaint => 'Customer Complaint',
            self::ManualAdmin => 'Manual (Admin)',
        };
    }
}
