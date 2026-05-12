<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\DeliveryFeePayer;
use App\Enums\DeliveryFeePaymentMethod;
use App\Enums\DeliveryFeeStatus;
use App\Enums\DeliveryMethod;
use App\Enums\DriverAccountBucket;
use App\Enums\DriverAccountTransactionReason;
use App\Enums\DriverActivityStatus;
use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Enums\PickupMethod;
use App\Exceptions\Order\OrderDomainException;
use App\Models\DriverAccount;
use App\Models\DriverAccountTransaction;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\DB;

final class CodeVerificationService
{
    public function __construct(private readonly StateTransitionService $transitions) {}

    /**
     * Generate a pickup/delivery pair guaranteed distinct for the same order.
     *
     * @return array{pickup: string, delivery: string}
     */
    public function generatePair(): array
    {
        do {
            $pickup = $this->sixDigitCode();
            $delivery = $this->sixDigitCode();
        } while ($pickup === $delivery);

        return ['pickup' => $pickup, 'delivery' => $delivery];
    }

    private function sixDigitCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function confirmPickup(User $driver, Order $order, ?string $method, ?string $code): Order
    {
        return DB::transaction(function () use ($driver, $order, $method, $code): Order {
            $order = $order->refresh();

            if ($order->status !== OrderStatus::DriverEnRoutePickup) {
                throw new OrderDomainException(
                    OrderErrorCode::InvalidStateTransition,
                    trans('order_messages.invalid_state_transition'),
                );
            }

            $resolvedMethod = $this->resolvePickupMethod($order, $driver, $method, $code);

            $order->forceFill(['picked_up_method' => $resolvedMethod->value]);

            if ($order->delivery_fee_payer === DeliveryFeePayer::Sender
                && $order->delivery_fee_payment_method === DeliveryFeePaymentMethod::Cash) {
                $order->delivery_fee_status = DeliveryFeeStatus::Paid->value;
                $order->delivery_fee_paid_at = now();
                $this->addPickupCashToDriverAccount($driver, $order);
            }

            $order->save();

            $this->transitions->transition(
                order: $order->refresh(),
                to: OrderStatus::PickedUp,
                actorType: OrderActorType::Driver,
                actorId: $driver->id,
                actorLocation: $this->driverLocation($driver),
                metadata: ['method' => $resolvedMethod->value],
            );

            return $this->transitions->transition(
                order: $order->refresh(),
                to: OrderStatus::DriverEnRouteDropoff,
                actorType: OrderActorType::Driver,
                actorId: $driver->id,
                actorLocation: $this->driverLocation($driver),
                metadata: ['event' => 'auto_chain_after_pickup'],
            );
        });
    }

    public function arrivedDropoff(User $driver, Order $order): Order
    {
        return DB::transaction(function () use ($driver, $order): Order {
            $order = $order->refresh();

            if ($order->status !== OrderStatus::DriverEnRouteDropoff) {
                throw new OrderDomainException(
                    OrderErrorCode::InvalidStateTransition,
                    trans('order_messages.invalid_state_transition'),
                );
            }

            $location = $this->driverLocation($driver);
            if ($location === null || $this->distanceMeters($location, $order->receiver_location) > (int) PlatformSetting::get('pickup.dropoff_sanity_meters', 1000)) {
                throw new OrderDomainException(
                    OrderErrorCode::DriverNotNearDropoff,
                    trans('order_messages.driver_not_near_dropoff'),
                );
            }

            return $this->transitions->transition(
                order: $order,
                to: OrderStatus::DeliveryInProgress,
                actorType: OrderActorType::Driver,
                actorId: $driver->id,
                actorLocation: $location,
            );
        });
    }

    public function confirmDelivery(User $driver, Order $order, ?string $code): Order
    {
        return DB::transaction(function () use ($driver, $order, $code): Order {
            $order = $order->refresh();

            if ($order->status !== OrderStatus::DeliveryInProgress) {
                throw new OrderDomainException(
                    OrderErrorCode::InvalidStateTransition,
                    trans('order_messages.invalid_state_transition'),
                );
            }

            $method = $this->resolveDeliveryMethod($order, $code);

            $order->forceFill(['delivered_method' => $method->value]);

            if ($order->delivery_fee_payer === DeliveryFeePayer::Receiver
                && $order->delivery_fee_payment_method === DeliveryFeePaymentMethod::Cash) {
                $order->delivery_fee_status = DeliveryFeeStatus::Paid->value;
                $order->delivery_fee_paid_at = now();
            }

            $order->save();

            $updated = $this->transitions->transition(
                order: $order->refresh(),
                to: OrderStatus::Delivered,
                actorType: OrderActorType::Driver,
                actorId: $driver->id,
                actorLocation: $this->driverLocation($driver),
                metadata: ['method' => $method->value],
            );

            $this->applyDriverDeliveryFinancials($driver, $updated);

            DriverProfile::query()
                ->where('user_id', $driver->id)
                ->update([
                    'activity_status' => DriverActivityStatus::Online->value,
                    'last_active_at' => now(),
                ]);

            return $updated->refresh();
        });
    }

    public function confirmGeofenceBySender(User $sender, Order $order): Order
    {
        $order->forceFill(['pickup_geofence_confirmed_at' => now()])->save();

        return $order->refresh();
    }

    private function resolvePickupMethod(Order $order, User $driver, ?string $method, ?string $code): PickupMethod
    {
        if (! (bool) PlatformSetting::get('codes.enforce_pickup', true)) {
            return PickupMethod::Bypassed;
        }

        if ($method === null) {
            throw new OrderDomainException(OrderErrorCode::MethodRequired, trans('order_messages.method_required'));
        }

        if ($method === 'geofence') {
            return $this->verifyPickupGeofence($order, $driver);
        }

        if ($code === null) {
            throw new OrderDomainException(OrderErrorCode::CodeRequired, trans('order_messages.code_required'));
        }

        if ($order->pickup_code_attempts >= (int) PlatformSetting::get('codes.max_attempts', 5)) {
            throw new OrderDomainException(OrderErrorCode::CodeLocked, trans('order_messages.code_locked'));
        }

        if (! hash_equals((string) $order->pickup_code, $code)) {
            $order->increment('pickup_code_attempts');

            throw new OrderDomainException(OrderErrorCode::InvalidPickupCode, trans('order_messages.invalid_pickup_code'));
        }

        return PickupMethod::Code;
    }

    private function verifyPickupGeofence(Order $order, User $driver): PickupMethod
    {
        if ($order->pickup_geofence_confirmed_at === null
            || $order->pickup_geofence_confirmed_at->lt(now()->subMinutes(5))) {
            throw new OrderDomainException(
                OrderErrorCode::GeofenceNotConfirmed,
                trans('order_messages.geofence_not_confirmed'),
            );
        }

        $location = $this->driverLocation($driver);
        if ($location === null || $this->distanceMeters($location, $order->pickup_location) > (int) PlatformSetting::get('pickup.geofence_meters', 500)) {
            throw new OrderDomainException(
                OrderErrorCode::DriverNotAtPickup,
                trans('order_messages.driver_not_at_pickup'),
            );
        }

        return PickupMethod::GeofenceConfirmation;
    }

    private function resolveDeliveryMethod(Order $order, ?string $code): DeliveryMethod
    {
        if (! (bool) PlatformSetting::get('codes.enforce_delivery', true)) {
            return DeliveryMethod::Bypassed;
        }

        if ($code === null) {
            throw new OrderDomainException(OrderErrorCode::CodeRequired, trans('order_messages.code_required'));
        }

        if ($order->delivery_code_attempts >= (int) PlatformSetting::get('codes.max_attempts', 5)) {
            throw new OrderDomainException(OrderErrorCode::CodeLocked, trans('order_messages.code_locked'));
        }

        if (! hash_equals((string) $order->delivery_code, $code)) {
            $order->increment('delivery_code_attempts');

            throw new OrderDomainException(OrderErrorCode::InvalidDeliveryCode, trans('order_messages.invalid_delivery_code'));
        }

        return DeliveryMethod::Code;
    }

    private function driverLocation(User $driver): ?Point
    {
        return DriverProfile::query()->where('user_id', $driver->id)->first()?->current_location;
    }

    private function applyDriverDeliveryFinancials(User $driver, Order $order): void
    {
        $account = DriverAccount::query()
            ->where('driver_id', $driver->id)
            ->lockForUpdate()
            ->firstOrFail();

        $cash = $order->cashCollectedAtDelivery();
        if (bccomp($cash, '0.00', 2) === 1) {
            $this->mutateBucket($account, DriverAccountBucket::CashToDeposit, $cash, DriverAccountTransactionReason::OrderCompleted, $order);
        }

        $earnings = bcsub((string) $order->delivery_fee, (string) $order->driver_fee_cut_amount, 2);
        if (bccomp($earnings, '0.00', 2) !== 1) {
            return;
        }

        if (bccomp((string) $account->debt_balance, '0.00', 2) === 1) {
            $offset = bccomp((string) $account->debt_balance, $earnings, 2) === 1
                ? $earnings
                : (string) $account->debt_balance;

            $this->mutateBucket($account, DriverAccountBucket::DebtBalance, bcmul($offset, '-1', 2), DriverAccountTransactionReason::DebtOffset, $order);
            $earnings = bcsub($earnings, $offset, 2);
        }

        if (bccomp($earnings, '0.00', 2) === 1) {
            $this->mutateBucket($account, DriverAccountBucket::EarningsBalance, $earnings, DriverAccountTransactionReason::OrderCompleted, $order);
        }
    }

    private function addPickupCashToDriverAccount(User $driver, Order $order): void
    {
        if (bccomp((string) $order->delivery_fee, '0.00', 2) !== 1) {
            return;
        }

        $account = DriverAccount::query()
            ->where('driver_id', $driver->id)
            ->lockForUpdate()
            ->firstOrFail();

        $this->mutateBucket(
            $account,
            DriverAccountBucket::CashToDeposit,
            (string) $order->delivery_fee,
            DriverAccountTransactionReason::OrderCompleted,
            $order,
        );
    }

    private function mutateBucket(
        DriverAccount $account,
        DriverAccountBucket $bucket,
        string $amount,
        DriverAccountTransactionReason $reason,
        Order $order,
    ): void {
        $column = $bucket->value;
        $account->{$column} = bcadd((string) $account->{$column}, $amount, 2);
        $account->save();

        DriverAccountTransaction::create([
            'driver_id' => $account->driver_id,
            'bucket' => $bucket->value,
            'amount' => $amount,
            'reason' => $reason->value,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'balance_after' => $account->{$column},
        ]);
    }

    private function distanceMeters(Point $a, Point $b): float
    {
        $earthRadius = 6371000;
        $lat1 = deg2rad($a->getLatitude());
        $lat2 = deg2rad($b->getLatitude());
        $deltaLat = deg2rad($b->getLatitude() - $a->getLatitude());
        $deltaLng = deg2rad($b->getLongitude() - $a->getLongitude());

        $haversine = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * (sin($deltaLng / 2) ** 2);

        return $earthRadius * 2 * atan2(sqrt($haversine), sqrt(1 - $haversine));
    }
}
