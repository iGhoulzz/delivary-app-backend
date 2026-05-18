<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\DeliveryFeeStatus;
use App\Enums\DriverActivityStatus;
use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Enums\ReturnFault;
use App\Enums\ReturnReason;
use App\Exceptions\Order\OrderDomainException;
use App\Models\DriverProfile;
use App\Models\OfficeInventory;
use App\Models\OfficeLocation;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\User;
use App\Services\Driver\DriverAccountLedgerService;
use Illuminate\Support\Facades\DB;

final class FailedDeliveryService
{
    private const array POST_PICKUP_STATES = [
        OrderStatus::PickedUp,
        OrderStatus::DriverEnRouteDropoff,
        OrderStatus::DeliveryInProgress,
    ];

    public function __construct(
        private readonly StateTransitionService $transitions,
        private readonly ReturnOfficeResolver $officeResolver,
        private readonly StorageFeeCalculator $storageFee,
        private readonly DriverAccountLedgerService $ledger,
    ) {}

    public function markDeliveryFailedByDriver(
        User $driver,
        Order $order,
        ReturnReason $reason,
        ?string $notes = null,
    ): Order {
        return $this->markFailedCore($order, $driver, OrderActorType::Driver, $reason, $notes);
    }

    public function markDeliveryFailedByAdmin(
        User $admin,
        Order $order,
        ReturnReason $reason,
        ?string $notes = null,
    ): Order {
        return $this->markFailedCore($order, $admin, OrderActorType::Admin, $reason, $notes);
    }

    public function receiveReturn(
        User $staff,
        Order $order,
        ?string $shelfLocation = null,
        ?string $notes = null,
    ): Order {
        return DB::transaction(function () use ($staff, $order, $shelfLocation, $notes): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::ReturningToOffice) {
                throw new OrderDomainException(
                    OrderErrorCode::OrderNotReceivable,
                    trans('order_messages.order_not_receivable'),
                );
            }

            $inventory = OfficeInventory::create([
                'order_id' => $order->id,
                'office_id' => $order->return_office_id,
                'received_by_staff_id' => $staff->id,
                'received_at' => now(),
                'shelf_location' => $shelfLocation,
                'accrued_storage_fee' => '0.00',
                'last_fee_accrued_on' => now()->toDateString(),
                'notes' => $notes,
            ]);

            $this->transitions->transition(
                order: $order,
                to: OrderStatus::AtOffice,
                actorType: OrderActorType::OfficeStaff,
                actorId: $staff->id,
                notes: $notes,
                metadata: [
                    'event' => 'office_receive_return',
                    'office_id' => $order->return_office_id,
                    'shelf_location' => $shelfLocation,
                    'inventory_id' => $inventory->id,
                ],
            );

            $order->forceFill(['returned_to_office_at' => now()])->save();

            if ($order->driver_id !== null) {
                $driver = User::query()->findOrFail($order->driver_id);

                if (! in_array($order->return_fault, [ReturnFault::Driver, ReturnFault::Platform], true)) {
                    $this->ledger->applyDeliveryCompletionCredit($driver, $order->refresh());
                }

                DriverProfile::query()
                    ->where('user_id', $driver->id)
                    ->update([
                        'activity_status' => DriverActivityStatus::Online->value,
                        'last_active_at' => now(),
                    ]);
            }

            return $order->refresh()->load(['officeInventory', 'statusLogs', 'driver.driverProfile']);
        });
    }

    public function retrieve(User $staff, Order $order, string $cashCollected, ?string $notes = null): Order
    {
        return DB::transaction(function () use ($staff, $order, $cashCollected, $notes): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::AtOffice) {
                throw new OrderDomainException(
                    OrderErrorCode::OrderNotRetrievable,
                    trans('order_messages.order_not_retrievable'),
                );
            }

            $inventory = OfficeInventory::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $accruedStorage = $this->storageFee->compute($inventory);
            $waived = (string) $inventory->retrieval_fees_waived_amount;
            $deliveryFeeOwed = $this->deliveryFeeOwedAtRetrieval($order);
            $totalOwed = bcsub(bcadd($deliveryFeeOwed, $accruedStorage, 2), $waived, 2);

            if (bccomp($totalOwed, '0.00', 2) === -1) {
                $totalOwed = '0.00';
            }

            $cashNormalized = bcadd($cashCollected, '0', 2);

            if (bccomp($cashNormalized, $totalOwed, 2) === -1) {
                throw new OrderDomainException(
                    OrderErrorCode::InsufficientCashCollected,
                    trans('order_messages.insufficient_cash_collected'),
                    ['owed' => $totalOwed, 'cash_collected' => $cashNormalized],
                );
            }

            if (bccomp($cashNormalized, $totalOwed, 2) === 1) {
                throw new OrderDomainException(
                    OrderErrorCode::ExcessCashCollected,
                    trans('order_messages.excess_cash_collected'),
                    ['owed' => $totalOwed, 'cash_collected' => $cashNormalized],
                );
            }

            $inventory->forceFill([
                'accrued_storage_fee' => $accruedStorage,
                'cash_collected_at_retrieval' => $cashNormalized,
                'retrieved_at' => now(),
                'retrieved_by_staff_id' => $staff->id,
                'notes' => $notes ?? $inventory->notes,
            ])->save();

            $orderUpdates = ['storage_fee_accrued' => $accruedStorage];
            if (bccomp($deliveryFeeOwed, '0.00', 2) === 1) {
                $orderUpdates['delivery_fee_status'] = DeliveryFeeStatus::Paid->value;
                $orderUpdates['delivery_fee_paid_at'] = now();
            }

            $order->forceFill($orderUpdates)->save();

            $this->transitions->transition(
                order: $order->refresh(),
                to: OrderStatus::RetrievedBySeller,
                actorType: OrderActorType::OfficeStaff,
                actorId: $staff->id,
                notes: $notes,
                metadata: [
                    'event' => 'office_retrieve',
                    'cash_collected' => $cashNormalized,
                    'delivery_fee_owed' => $deliveryFeeOwed,
                    'storage_fee' => $accruedStorage,
                    'waived' => $waived,
                ],
            );

            return $order->refresh()->load(['officeInventory', 'statusLogs']);
        });
    }

    public function redirectReturn(User $admin, Order $order, OfficeLocation $office, ?string $reason = null): Order
    {
        if ($order->status !== OrderStatus::ReturningToOffice) {
            throw new OrderDomainException(
                OrderErrorCode::OrderNotRedirectable,
                trans('order_messages.order_not_redirectable'),
            );
        }

        if (! $office->is_active) {
            throw new OrderDomainException(
                OrderErrorCode::OfficeInactive,
                trans('order_messages.office_inactive'),
            );
        }

        return DB::transaction(function () use ($admin, $order, $office, $reason): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $previousOfficeId = $order->return_office_id;

            $order->forceFill(['return_office_id' => $office->id])->save();

            OrderStatusLog::create([
                'order_id' => $order->id,
                'from_status' => OrderStatus::ReturningToOffice->value,
                'to_status' => OrderStatus::ReturningToOffice->value,
                'actor_type' => OrderActorType::Admin->value,
                'actor_id' => $admin->id,
                'reason' => $reason,
                'metadata' => [
                    'event' => 'return_office_redirected',
                    'previous_office_id' => $previousOfficeId,
                    'new_office_id' => $office->id,
                ],
            ]);

            return $order->refresh()->load(['statusLogs']);
        });
    }

    public function waiveRetrievalFees(User $admin, Order $order, string $amount, ?string $reason = null): Order
    {
        if ($order->status !== OrderStatus::AtOffice) {
            throw new OrderDomainException(
                OrderErrorCode::OrderNotWaivable,
                trans('order_messages.order_not_waivable'),
            );
        }

        return DB::transaction(function () use ($admin, $order, $amount, $reason): Order {
            $inventory = OfficeInventory::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $normalized = bcadd($amount, '0', 2);
            if (bccomp($normalized, '0.00', 2) === -1) {
                $normalized = '0.00';
            }

            $inventory->forceFill(['retrieval_fees_waived_amount' => $normalized])->save();

            OrderStatusLog::create([
                'order_id' => $order->id,
                'from_status' => OrderStatus::AtOffice->value,
                'to_status' => OrderStatus::AtOffice->value,
                'actor_type' => OrderActorType::Admin->value,
                'actor_id' => $admin->id,
                'reason' => $reason,
                'metadata' => [
                    'event' => 'retrieval_fees_waived',
                    'amount' => $normalized,
                ],
            ]);

            return $order->refresh()->load(['officeInventory', 'statusLogs']);
        });
    }

    public function abandonStale(Order $order): bool
    {
        if ($order->status !== OrderStatus::AtOffice) {
            return false;
        }

        return DB::transaction(function () use ($order): bool {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== OrderStatus::AtOffice) {
                return false;
            }

            $inventory = OfficeInventory::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $accrued = $this->storageFee->compute($inventory);

            $inventory->forceFill([
                'accrued_storage_fee' => $accrued,
                'abandoned_at' => now(),
            ])->save();

            $order->forceFill(['storage_fee_accrued' => $accrued])->save();

            $this->transitions->transition(
                order: $order->refresh(),
                to: OrderStatus::Abandoned,
                actorType: OrderActorType::System,
                actorId: null,
                metadata: [
                    'event' => 'abandonment_cron',
                    'accrued_storage_fee' => $accrued,
                    'received_at' => $inventory->received_at->toIso8601String(),
                ],
            );

            return true;
        });
    }

    private function markFailedCore(
        Order $order,
        User $actor,
        OrderActorType $actorType,
        ReturnReason $reason,
        ?string $notes,
    ): Order {
        return DB::transaction(function () use ($order, $actor, $actorType, $reason, $notes): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, self::POST_PICKUP_STATES, true)) {
                throw new OrderDomainException(
                    OrderErrorCode::OrderNotFailable,
                    trans('order_messages.order_not_failable'),
                );
            }

            $fault = $this->faultFromReason($reason);
            $office = $this->officeResolver->resolveForPickup($order->pickup_location);

            $order->forceFill([
                'return_reason' => $reason->value,
                'return_fault' => $fault->value,
                'return_office_id' => $office->id,
            ])->save();

            $this->transitions->transition(
                order: $order,
                to: OrderStatus::DeliveryFailed,
                actorType: $actorType,
                actorId: $actor->id,
                reason: $notes,
                metadata: [
                    'event' => 'mark_delivery_failed',
                    'return_reason' => $reason->value,
                    'return_fault' => $fault->value,
                    'return_office_id' => $office->id,
                ],
            );

            $this->transitions->transition(
                order: $order->refresh(),
                to: OrderStatus::ReturningToOffice,
                actorType: OrderActorType::System,
                actorId: null,
                metadata: ['event' => 'auto_chain_after_failure'],
            );

            return $order->refresh()->load(['statusLogs', 'driver.driverProfile']);
        });
    }

    private function faultFromReason(ReturnReason $reason): ReturnFault
    {
        return match ($reason) {
            ReturnReason::ReceiverRefused,
            ReturnReason::ReceiverUnreachable => ReturnFault::Receiver,
            ReturnReason::AddressInvalid => ReturnFault::Sender,
            ReturnReason::ItemDamaged,
            ReturnReason::DriverFault => ReturnFault::Driver,
        };
    }

    private function deliveryFeeOwedAtRetrieval(Order $order): string
    {
        if ($order->delivery_fee_status === DeliveryFeeStatus::Paid) {
            return '0.00';
        }

        if (! in_array($order->return_fault, [ReturnFault::Sender, ReturnFault::Receiver], true)) {
            return '0.00';
        }

        return (string) $order->delivery_fee;
    }
}
