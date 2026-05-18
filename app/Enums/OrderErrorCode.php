<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderErrorCode: string
{
    case PickupOutOfServiceArea = 'pickup_out_of_service_area';
    case InvalidQuoteToken = 'invalid_quote_token';
    case QuoteExpired = 'quote_expired';
    case QuotePriceChanged = 'quote_price_changed';
    case SenderIsReceiver = 'sender_is_receiver';
    case MerchantUseMerchantFlow = 'merchant_use_merchant_flow';
    case IdempotencyConflict = 'idempotency_conflict';
    case OrderAlreadyClaimed = 'order_already_claimed';
    case OrderNotRetryable = 'order_not_retryable';
    case OrderNotCancellableFromState = 'order_not_cancellable_from_state';
    case OrderNotAssignable = 'order_not_assignable';
    case OrderNotUnassignable = 'order_not_unassignable';
    case OrderHasNoDriver = 'order_has_no_driver';
    case InvalidStateTransition = 'invalid_state_transition';
    case NotYourOrder = 'not_your_order';
    case InvalidPickupCode = 'invalid_pickup_code';
    case InvalidDeliveryCode = 'invalid_delivery_code';
    case CodeLocked = 'code_locked';
    case MethodRequired = 'method_required';
    case CodeRequired = 'code_required';
    case GeofenceNotConfirmed = 'geofence_not_confirmed';
    case DriverNotAtPickup = 'driver_not_at_pickup';
    case DriverNotNearDropoff = 'driver_not_near_dropoff';
    case DriverNotActive = 'driver_not_active';
    case DriverNoRegions = 'driver_no_regions';
    case DriverGpsRequired = 'driver_gps_required';
    case DriverOutOfServiceArea = 'driver_out_of_service_area';
    case DriverLiabilityMax = 'driver_liability_max';
    case DriverLiabilityInsufficient = 'driver_liability_insufficient';
    case DriverHasActiveOrder = 'driver_has_active_order';
    case DriverLocationStale = 'driver_location_stale';
    case DriverBlockedByDebt = 'driver_blocked_by_debt';
    case VehicleMismatch = 'vehicle_mismatch';
    case DriverRegionMismatch = 'driver_region_mismatch';
    case OrderNotFailable = 'order_not_failable';
    case OrderNotReceivable = 'order_not_receivable';
    case OrderNotRetrievable = 'order_not_retrievable';
    case OrderNotWaivable = 'order_not_waivable';
    case OrderNotRedirectable = 'order_not_redirectable';
    case WrongOfficeForOrder = 'wrong_office_for_order';
    case NoReturnOfficeAvailable = 'no_return_office_available';
    case InsufficientCashCollected = 'insufficient_cash_collected';
    case ExcessCashCollected = 'excess_cash_collected';
    case OfficeInactive = 'office_inactive';

    public function httpStatus(): int
    {
        return match ($this) {
            self::PickupOutOfServiceArea, self::InvalidQuoteToken => 400,
            self::NotYourOrder,
            self::WrongOfficeForOrder => 403,
            self::SenderIsReceiver,
            self::MerchantUseMerchantFlow,
            self::InvalidPickupCode,
            self::InvalidDeliveryCode,
            self::MethodRequired,
            self::CodeRequired,
            self::DriverNotActive,
            self::DriverNoRegions,
            self::DriverGpsRequired,
            self::DriverOutOfServiceArea,
            self::DriverLiabilityInsufficient,
            self::VehicleMismatch,
            self::DriverRegionMismatch,
            self::NoReturnOfficeAvailable,
            self::InsufficientCashCollected,
            self::ExcessCashCollected,
            self::OfficeInactive => 422,
            self::QuotePriceChanged,
            self::IdempotencyConflict,
            self::OrderAlreadyClaimed,
            self::OrderNotRetryable,
            self::OrderNotCancellableFromState,
            self::OrderNotAssignable,
            self::OrderNotUnassignable,
            self::OrderHasNoDriver,
            self::InvalidStateTransition,
            self::GeofenceNotConfirmed,
            self::DriverNotAtPickup,
            self::DriverNotNearDropoff,
            self::DriverLiabilityMax,
            self::DriverHasActiveOrder,
            self::DriverLocationStale,
            self::DriverBlockedByDebt,
            self::OrderNotFailable,
            self::OrderNotReceivable,
            self::OrderNotRetrievable,
            self::OrderNotWaivable,
            self::OrderNotRedirectable => 409,
            self::QuoteExpired => 410,
            self::CodeLocked => 429,
        };
    }
}
