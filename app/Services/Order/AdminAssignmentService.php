<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Enums\VehicleType;
use App\Exceptions\Order\OrderDomainException;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AdminAssignmentService
{
    public function assign(User $admin, Order $order, User $driver, bool $force = false): Order
    {
        return DB::transaction(function () use ($admin, $order, $driver, $force): Order {
            $order = $order->refresh();

            if (! in_array($order->status, [OrderStatus::AwaitingDriver, OrderStatus::NoDriverAvailable], true)) {
                throw new OrderDomainException(
                    OrderErrorCode::OrderNotAssignable,
                    trans('order_messages.order_not_assignable'),
                );
            }

            $profile = DriverProfile::query()
                ->where('user_id', $driver->id)
                ->lockForUpdate()
                ->first();

            if ($profile === null || $profile->status !== DriverStatus::Active) {
                throw new OrderDomainException(
                    OrderErrorCode::DriverNotActive,
                    trans('order_messages.driver_not_active'),
                );
            }

            if (! $force && ! in_array($profile->vehicle_type, VehicleType::eligibleFor($order->item_size->value), true)) {
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

            $from = $order->status;
            $now = now();
            $updated = Order::query()
                ->whereKey($order->id)
                ->whereIn('status', [OrderStatus::AwaitingDriver->value, OrderStatus::NoDriverAvailable->value])
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
                    OrderErrorCode::OrderNotAssignable,
                    trans('order_messages.order_not_assignable'),
                );
            }

            $profile->forceFill([
                'activity_status' => DriverActivityStatus::OnOrder->value,
                'last_active_at' => $now,
            ])->save();

            $this->log($order, $from, OrderStatus::DriverEnRoutePickup, $admin, [
                'event' => 'admin_manual_assign',
                'driver_id' => $driver->id,
                'force' => $force,
            ]);

            return $order->refresh()->load(['driver.driverProfile', 'statusLogs']);
        });
    }

    public function unassign(User $admin, Order $order, ?string $reason = null, bool $resetTier = true): Order
    {
        return DB::transaction(function () use ($admin, $order, $reason, $resetTier): Order {
            $order = $order->refresh();

            if (! in_array($order->status, [OrderStatus::Assigned, OrderStatus::DriverEnRoutePickup], true) || $order->driver_id === null) {
                throw new OrderDomainException(
                    OrderErrorCode::OrderNotUnassignable,
                    trans('order_messages.order_not_unassignable'),
                );
            }

            $from = $order->status;
            $driverId = $order->driver_id;
            $now = now();
            $updates = [
                'driver_id' => null,
                'status' => OrderStatus::AwaitingDriver->value,
                'status_changed_at' => $now,
                'awaiting_driver_at' => $now,
            ];

            if ($resetTier) {
                $updates['search_radius_tier'] = 1;
                $updates['delivery_fee_surcharge_percent'] = 0;
                $updates['delivery_fee'] = $order->delivery_fee_base;
            }

            Order::query()->whereKey($order->id)->update($updates);

            DriverProfile::query()
                ->where('user_id', $driverId)
                ->update([
                    'activity_status' => DriverActivityStatus::Online->value,
                    'last_active_at' => $now,
                ]);

            $this->log($order, $from, OrderStatus::AwaitingDriver, $admin, [
                'event' => 'admin_unassign',
                'driver_id' => $driverId,
                'reset_tier' => $resetTier,
            ], $reason);

            return $order->refresh()->load(['driver.driverProfile', 'statusLogs']);
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function log(Order $order, OrderStatus $from, OrderStatus $to, User $admin, array $metadata, ?string $reason = null): void
    {
        OrderStatusLog::create([
            'order_id' => $order->id,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'actor_type' => OrderActorType::Admin->value,
            'actor_id' => $admin->id,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }
}
