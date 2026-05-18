<?php

declare(strict_types=1);

return [
    // Pricing / quoting
    'pickup_out_of_service_area' => 'The pickup location is outside our active service area.',
    'invalid_quote_token' => 'Invalid quote. Request a fresh quote and try again.',
    'quote_expired' => 'Your quote has expired. Request a fresh quote and try again.',
    'quote_price_changed' => 'The price changed since you previewed it. Review the updated quote and confirm.',

    // Creation
    'sender_is_receiver' => 'You cannot create an order to yourself.',
    'merchant_use_merchant_flow' => 'Merchant accounts must use the merchant order flow.',
    'idempotency_conflict' => 'A different request is already in flight with this idempotency key.',

    // Lifecycle
    'order_already_claimed' => 'This order was claimed by another driver.',
    'order_not_retryable' => 'This order cannot be retried in its current state.',
    'order_not_cancellable_from_state' => 'This order cannot be cancelled in its current state.',
    'order_not_assignable' => 'This order cannot be assigned in its current state.',
    'order_not_unassignable' => 'This order cannot be unassigned in its current state.',
    'order_has_no_driver' => 'This order does not have an assigned driver.',
    'invalid_state_transition' => 'That transition is not allowed.',
    'not_your_order' => 'This order does not belong to you.',

    // Codes / geofence
    'invalid_pickup_code' => 'The pickup code is incorrect.',
    'invalid_delivery_code' => 'The delivery code is incorrect.',
    'code_locked' => 'Too many incorrect attempts. Contact support.',
    'method_required' => 'A verification method is required.',
    'code_required' => 'The verification code is required.',
    'geofence_not_confirmed' => 'The sender has not confirmed your arrival yet.',
    'driver_not_at_pickup' => 'You are not within the pickup geofence yet.',
    'driver_not_near_dropoff' => 'You are not within the dropoff area yet.',

    // Driver presence
    'driver_not_active' => 'Driver account is not active.',
    'driver_no_regions' => 'No regions assigned. Pick at least one region first.',
    'driver_gps_required' => 'GPS coordinates are required.',
    'driver_out_of_service_area' => 'You are outside the active service area.',
    'driver_liability_max' => 'You have reached your cash liability ceiling. Settle at office to continue.',
    'driver_liability_insufficient' => 'Driver liability headroom is insufficient for this order.',
    'driver_has_active_order' => 'Driver already has an active order.',
    'driver_location_stale' => 'Your GPS update is stale. Refresh and try again.',
    'driver_blocked_by_debt' => 'Account is blocked due to outstanding debt.',

    // Admin
    'vehicle_mismatch' => 'Vehicle type does not match item size.',
    'driver_region_mismatch' => 'Driver is not assigned to the pickup region.',

    // Failed delivery / return-to-office
    'order_not_failable' => 'This order cannot be marked failed in its current state.',
    'order_not_receivable' => 'This order cannot be received at office in its current state.',
    'order_not_retrievable' => 'This order cannot be retrieved in its current state.',
    'order_not_waivable' => 'Retrieval fees can only be waived while the order is at office.',
    'order_not_redirectable' => 'This order cannot be redirected to another office in its current state.',
    'wrong_office_for_order' => 'This order is bound for a different office.',
    'no_return_office_available' => 'No active office could be resolved for this return.',
    'insufficient_cash_collected' => 'Cash collected is less than the amount owed.',
    'excess_cash_collected' => 'Cash collected is more than the amount owed.',
    'office_inactive' => 'Target office is not active.',
];
