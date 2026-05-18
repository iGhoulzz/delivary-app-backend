<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\DriverActivityStatus;
use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Exceptions\Order\OrderDomainException;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CancellationService
{
    public function __construct(private readonly StateTransitionService $transitions) {}

    public function cancelByUserFromNoDriver(User $sender, Order $order, ?string $reason = null): Order
    {
        return $this->cancelByUser($sender, $order, $reason);
    }

    public function cancelByUser(User $sender, Order $order, ?string $reason = null): Order
    {
        if ($order->status !== OrderStatus::NoDriverAvailable) {
            $this->assertUserCancellable($order);
        }

        return DB::transaction(function () use ($sender, $order, $reason): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertUserCancellable($order);

            $fee = $this->userCancellationFee($order);

            $order->forceFill([
                'cancelled_by_user_id' => $sender->id,
                'cancellation_reason' => $reason,
                'cancellation_fee' => $fee,
            ])->save();

            $updated = $this->transitions->transition(
                order: $order->refresh(),
                to: OrderStatus::CancelledByUser,
                actorType: OrderActorType::User,
                actorId: $sender->id,
                reason: $reason,
                metadata: [
                    'event' => 'sender_cancel_pre_pickup',
                    'fee_amount' => $fee,
                ],
            );

            $this->releaseDriver($updated);

            return $updated->refresh()->load(['driver.driverProfile', 'statusLogs']);
        });
    }

    public function cancelByAdmin(User $admin, Order $order, ?string $reason = null): Order
    {
        if (! in_array($order->status, $this->adminCancellableStatuses(), true)) {
            throw new OrderDomainException(
                OrderErrorCode::OrderNotCancellableFromState,
                trans('order_messages.order_not_cancellable_from_state'),
            );
        }

        return DB::transaction(function () use ($admin, $order, $reason): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, $this->adminCancellableStatuses(), true)) {
                throw new OrderDomainException(
                    OrderErrorCode::OrderNotCancellableFromState,
                    trans('order_messages.order_not_cancellable_from_state'),
                );
            }

            $order->forceFill([
                'cancelled_by_user_id' => $admin->id,
                'cancellation_reason' => $reason,
                'cancellation_fee' => '0.00',
            ])->save();

            $updated = $this->transitions->transition(
                order: $order->refresh(),
                to: OrderStatus::CancelledByAdmin,
                actorType: OrderActorType::Admin,
                actorId: $admin->id,
                reason: $reason,
                metadata: ['event' => 'admin_cancel_pre_pickup'],
            );

            $this->releaseDriver($updated);

            return $updated->refresh()->load(['driver.driverProfile', 'statusLogs']);
        });
    }

    private function assertUserCancellable(Order $order): void
    {
        if (! in_array($order->status, $this->userCancellableStatuses(), true)) {
            throw new OrderDomainException(
                OrderErrorCode::OrderNotCancellableFromState,
                trans('order_messages.order_not_cancellable_from_state'),
            );
        }
    }

    /**
     * @return array<int, OrderStatus>
     */
    private function userCancellableStatuses(): array
    {
        return [
            OrderStatus::AwaitingDriver,
            OrderStatus::NoDriverAvailable,
            OrderStatus::Assigned,
            OrderStatus::DriverEnRoutePickup,
        ];
    }

    /**
     * @return array<int, OrderStatus>
     */
    private function adminCancellableStatuses(): array
    {
        return [
            OrderStatus::AwaitingDriver,
            OrderStatus::NoDriverAvailable,
            OrderStatus::Assigned,
            OrderStatus::DriverEnRoutePickup,
        ];
    }

    private function userCancellationFee(Order $order): string
    {
        if (in_array($order->status, [OrderStatus::AwaitingDriver, OrderStatus::NoDriverAvailable], true)) {
            return '0.00';
        }

        return number_format((float) PlatformSetting::get('cancellation.user_pre_pickup_fee', 0), 2, '.', '');
    }

    private function releaseDriver(Order $order): void
    {
        if ($order->driver_id === null) {
            return;
        }

        DriverProfile::query()
            ->where('user_id', $order->driver_id)
            ->update([
                'activity_status' => DriverActivityStatus::Online->value,
                'last_active_at' => now(),
            ]);
    }
}
