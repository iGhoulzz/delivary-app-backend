<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Exceptions\Order\OrderDomainException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CancellationService
{
    public function __construct(private readonly StateTransitionService $transitions) {}

    public function cancelByUserFromNoDriver(User $sender, Order $order, ?string $reason = null): Order
    {
        if ($order->status !== OrderStatus::NoDriverAvailable) {
            throw new OrderDomainException(
                OrderErrorCode::OrderNotCancellableFromState,
                trans('order_messages.order_not_cancellable_from_state'),
            );
        }

        return DB::transaction(function () use ($sender, $order, $reason): Order {
            $order->forceFill([
                'cancelled_by_user_id' => $sender->id,
                'cancellation_reason' => $reason,
                'cancellation_fee' => '0.00',
            ])->save();

            return $this->transitions->transition(
                order: $order->refresh(),
                to: OrderStatus::CancelledByUser,
                actorType: OrderActorType::User,
                actorId: $sender->id,
                reason: $reason,
                metadata: ['event' => 'free_cancel_from_no_driver_available'],
            );
        });
    }
}
