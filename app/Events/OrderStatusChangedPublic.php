<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Http\Resources\Order\GuestTrackingResource;
use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class OrderStatusChangedPublic implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    private const string EVENT_NAME = 'order.status_changed_public';

    public bool $afterCommit = true;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $fromStatus,
        public readonly OrderStatus $toStatus,
        public readonly OrderActorType $actorType,
        public readonly ?int $actorId = null,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('track.'.$this->order->tracking_token)];
    }

    public function broadcastAs(): string
    {
        return self::EVENT_NAME;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $order = $this->order->loadMissing(['driver.driverProfile']);

        return [
            'type' => self::EVENT_NAME,
            'order' => (new GuestTrackingResource($order))->resolve(),
            'transition' => [
                'from' => $this->fromStatus->value,
                'to' => $this->toStatus->value,
                'changed_at' => $this->order->status_changed_at?->toIso8601String(),
            ],
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
