<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\DriverAccountTransactionReason;
use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\DriverStrikeIssuer;
use App\Enums\DriverStrikeReason;
use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Enums\VehicleType;
use App\Events\OrderDriverAssigned;
use App\Events\OrderStatusChanged;
use App\Events\OrderStatusChangedPublic;
use App\Exceptions\Order\OrderDomainException;
use App\Models\DriverProfile;
use App\Models\DriverStrike;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Driver\DriverAccountLedgerService;
use Illuminate\Support\Facades\DB;

final class AdminAssignmentService
{
    public function __construct(private readonly DriverAccountLedgerService $driverLedger) {}

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
                'driver_public_id' => $driver->public_id,
                'force' => $force,
            ]);

            $assigned = $order->refresh()->load(['driver.driverProfile', 'statusLogs']);

            event(new OrderStatusChanged($assigned, $from, OrderStatus::DriverEnRoutePickup, OrderActorType::Admin, $admin->id));
            event(new OrderStatusChangedPublic($assigned, $from, OrderStatus::DriverEnRoutePickup, OrderActorType::Admin, $admin->id));
            event(new OrderDriverAssigned($assigned, $profile->loadMissing(['user'])));

            return $assigned;
        });
    }

    public function unassign(
        User $admin,
        Order $order,
        ?string $reason = null,
        bool $resetTier = true,
        bool $driverFault = false,
        ?string $notes = null,
        ?string $feeAmountOverride = null,
    ): Order {
        return DB::transaction(function () use ($admin, $order, $reason, $resetTier, $driverFault, $notes, $feeAmountOverride): Order {
            $order = $order->refresh();

            if (! in_array($order->status, [OrderStatus::Assigned, OrderStatus::DriverEnRoutePickup], true) || $order->driver_id === null) {
                throw new OrderDomainException(
                    OrderErrorCode::OrderNotUnassignable,
                    trans('order_messages.order_not_unassignable'),
                );
            }

            $from = $order->status;
            $driverId = $order->driver_id;
            $driver = User::query()->findOrFail($driverId);
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

            if ($driverFault) {
                $feeAmount = $feeAmountOverride !== null
                    ? number_format((float) $feeAmountOverride, 2, '.', '')
                    : number_format((float) PlatformSetting::get('cancellation.driver_accept_then_cancel_fee', 0), 2, '.', '');

                $strike = DriverStrike::create([
                    'driver_id' => $driverId,
                    'order_id' => $order->id,
                    'reason' => DriverStrikeReason::AcceptThenCancel->value,
                    'fee_amount' => $feeAmount,
                    'issued_by' => DriverStrikeIssuer::System->value,
                    'issued_by_admin_id' => null,
                    'notes' => $notes,
                ]);

                $this->driverLedger->applyFee(
                    $driver,
                    $feeAmount,
                    DriverAccountTransactionReason::StrikeFee,
                    $strike,
                    $admin->id,
                    $notes,
                );
            }

            DriverProfile::query()
                ->where('user_id', $driverId)
                ->update([
                    'activity_status' => $driverFault
                        ? DriverActivityStatus::Offline->value
                        : DriverActivityStatus::Online->value,
                    'last_active_at' => $now,
                ]);

            $this->log($order, $from, OrderStatus::AwaitingDriver, $admin, [
                'event' => $driverFault ? 'admin_unassign_driver_fault' : 'admin_unassign',
                'driver_public_id' => $driver->public_id,
                'reset_tier' => $resetTier,
                'driver_fault' => $driverFault,
            ], $reason);

            $unassigned = $order->refresh()->load(['driver.driverProfile', 'statusLogs']);

            event(new OrderStatusChanged($unassigned, $from, OrderStatus::AwaitingDriver, OrderActorType::Admin, $admin->id));
            event(new OrderStatusChangedPublic($unassigned, $from, OrderStatus::AwaitingDriver, OrderActorType::Admin, $admin->id));

            return $unassigned;
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
