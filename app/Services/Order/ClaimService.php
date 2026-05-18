<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\DriverActivityStatus;
use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Enums\VehicleType;
use App\Events\OrderStatusChanged;
use App\Exceptions\Order\OrderDomainException;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ClaimService
{
    public function __construct(private readonly BroadcastService $broadcasts) {}

    public function claim(User $driver, Order $order): Order
    {
        return DB::transaction(function () use ($driver, $order): Order {
            $profile = $this->broadcasts->freshOnlineProfile($driver);
            $this->assertDriverCanClaim($driver, $profile, $order);

            $now = now();
            $updated = Order::query()
                ->whereKey($order->id)
                ->where('status', OrderStatus::AwaitingDriver->value)
                ->whereNull('driver_id')
                ->update([
                    'driver_id' => $driver->id,
                    'status' => OrderStatus::DriverEnRoutePickup->value,
                    'status_changed_at' => $now,
                    'assigned_at' => $now,
                    'driver_en_route_pickup_at' => $now,
                    'driver_assignment_attempts' => DB::raw('driver_assignment_attempts + 1'),
                ]);

            if ($updated !== 1) {
                throw new OrderDomainException(
                    OrderErrorCode::OrderAlreadyClaimed,
                    trans('order_messages.order_already_claimed'),
                );
            }

            $profile->forceFill([
                'activity_status' => DriverActivityStatus::OnOrder->value,
                'last_active_at' => $now,
            ])->save();

            $this->logTransition($order, OrderStatus::AwaitingDriver, OrderStatus::Assigned, $driver);
            $this->logTransition($order, OrderStatus::Assigned, OrderStatus::DriverEnRoutePickup, $driver);

            $claimed = $order->refresh()->load(['driver.driverProfile']);

            event(new OrderStatusChanged($claimed, OrderStatus::AwaitingDriver, OrderStatus::Assigned, OrderActorType::Driver, $driver->id));
            event(new OrderStatusChanged($claimed, OrderStatus::Assigned, OrderStatus::DriverEnRoutePickup, OrderActorType::Driver, $driver->id));

            return $claimed;
        });
    }

    private function assertDriverCanClaim(User $driver, DriverProfile $profile, Order $order): void
    {
        if ($driver->deliveredOrders()->activeForDriver()->exists()) {
            throw new OrderDomainException(
                OrderErrorCode::DriverHasActiveOrder,
                trans('order_messages.driver_has_active_order'),
            );
        }

        if (! in_array($profile->vehicle_type, VehicleType::eligibleFor($order->item_size->value), true)) {
            throw new OrderDomainException(
                OrderErrorCode::VehicleMismatch,
                trans('order_messages.vehicle_mismatch'),
            );
        }

        $account = $driver->driverAccount()->lockForUpdate()->first();

        if ($account === null || ! $account->canHoldAdditionalCash($order->cashCollectedAtDelivery())) {
            throw new OrderDomainException(
                OrderErrorCode::DriverLiabilityInsufficient,
                trans('order_messages.driver_liability_insufficient'),
            );
        }
    }

    private function logTransition(Order $order, OrderStatus $from, OrderStatus $to, User $driver): void
    {
        OrderStatusLog::create([
            'order_id' => $order->id,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'actor_type' => OrderActorType::Driver->value,
            'actor_id' => $driver->id,
            'actor_location' => $driver->driverProfile?->current_location,
            'metadata' => ['event' => 'driver_claim'],
        ]);
    }
}
