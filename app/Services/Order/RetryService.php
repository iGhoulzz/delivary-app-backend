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

final class RetryService
{
    public function __construct(private readonly StateTransitionService $transitions) {}

    public function retry(User $sender, Order $order): Order
    {
        if ($order->status !== OrderStatus::NoDriverAvailable) {
            throw new OrderDomainException(
                OrderErrorCode::OrderNotRetryable,
                trans('order_messages.order_not_retryable'),
            );
        }

        return DB::transaction(function () use ($sender, $order): Order {
            $order->forceFill([
                'search_radius_tier' => 1,
                'delivery_fee_surcharge_percent' => 0,
                'delivery_fee' => $order->delivery_fee_base,
                'no_driver_available_at' => null,
            ])->save();

            return $this->transitions->transition(
                order: $order->refresh(),
                to: OrderStatus::AwaitingDriver,
                actorType: OrderActorType::User,
                actorId: $sender->id,
                metadata: ['event' => 'retry_from_no_driver_available'],
            );
        });
    }
}
