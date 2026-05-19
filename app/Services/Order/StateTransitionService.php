<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Events\OrderBroadcastToDriver;
use App\Events\OrderStatusChanged;
use App\Exceptions\Order\InvalidOrderTransitionException;
use App\Exceptions\Order\OrderDomainException;
use App\Models\Order;
use App\Models\OrderStatusLog;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\DB;
use LogicException;

final class StateTransitionService
{
    public function __construct(private readonly BroadcastService $broadcasts) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        Order $order,
        OrderStatus $to,
        OrderActorType $actorType,
        ?int $actorId = null,
        ?string $reason = null,
        ?string $notes = null,
        ?Point $actorLocation = null,
        array $metadata = [],
    ): Order {
        $this->assertInsideTransaction();

        $from = $order->status;

        if (! $from->canTransitionTo($to)) {
            throw new InvalidOrderTransitionException($from, $to);
        }

        $now = now();
        $updates = [
            'status' => $to->value,
            'status_changed_at' => $now,
        ];

        $timestampColumn = $this->timestampColumnFor($to);
        if ($timestampColumn !== null) {
            $updates[$timestampColumn] = $now;
        }

        $updated = Order::query()
            ->whereKey($order->id)
            ->where('status', $from->value)
            ->update($updates);

        if ($updated !== 1) {
            throw new OrderDomainException(
                OrderErrorCode::InvalidStateTransition,
                trans('order_messages.invalid_state_transition'),
                ['from' => $from->value, 'to' => $to->value],
            );
        }

        OrderStatusLog::create([
            'order_id' => $order->id,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'actor_type' => $actorType->value,
            'actor_id' => $actorId,
            'reason' => $reason,
            'notes' => $notes,
            'actor_location' => $actorLocation,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);

        $order->forceFill($updates);

        event(new OrderStatusChanged($order->refresh(), $from, $to, $actorType, $actorId));

        if ($to === OrderStatus::AwaitingDriver) {
            foreach ($this->broadcasts->eligibleDriversFor($order->refresh()) as $profile) {
                event(new OrderBroadcastToDriver(
                    $order,
                    (int) $profile->user_id,
                    $order->search_radius_tier,
                ));
            }
        }

        return $order;
    }

    private function assertInsideTransaction(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Order status transitions must run inside DB::transaction().');
        }
    }

    private function timestampColumnFor(OrderStatus $status): ?string
    {
        return match ($status) {
            OrderStatus::AwaitingDriver => 'awaiting_driver_at',
            OrderStatus::NoDriverAvailable => 'no_driver_available_at',
            OrderStatus::Assigned => 'assigned_at',
            OrderStatus::DriverEnRoutePickup => 'driver_en_route_pickup_at',
            OrderStatus::PickedUp => 'picked_up_at',
            OrderStatus::DriverEnRouteDropoff => 'driver_en_route_dropoff_at',
            OrderStatus::DeliveryInProgress => 'delivery_in_progress_at',
            OrderStatus::Delivered => 'delivered_at',
            OrderStatus::DeliveryFailed => 'delivery_failed_at',
            OrderStatus::ReturningToOffice => 'returning_to_office_at',
            OrderStatus::AtOffice => 'at_office_at',
            OrderStatus::CancelledByUser, OrderStatus::CancelledByAdmin => 'cancelled_at',
            OrderStatus::Created,
            OrderStatus::RetrievedBySeller,
            OrderStatus::Abandoned => null,
        };
    }
}
