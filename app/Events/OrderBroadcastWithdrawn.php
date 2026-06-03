<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired per-driver when an order is no longer eligible to be claimed by
 * the broadcast pool: claimed by another driver, admin-cancelled, timed
 * out, or sender-cancelled pre-pickup. Listening clients remove the order
 * from their local broadcast list.
 *
 * Dispatched by BroadcastWithdrawnOnExit listener whenever
 * OrderStatusChanged fires with from=AwaitingDriver.
 */
final class OrderBroadcastWithdrawn implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    private const string EVENT_NAME = 'order.broadcast_withdrawn';

    public bool $afterCommit = true;

    public function __construct(
        public readonly string $orderPublicId,
        public readonly string $driverPublicId,
        public readonly string $reason,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('driver.'.$this->driverPublicId)];
    }

    public function broadcastAs(): string
    {
        return self::EVENT_NAME;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type' => self::EVENT_NAME,
            'order_public_id' => $this->orderPublicId,
            'reason' => $this->reason,
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
