<?php

declare(strict_types=1);

namespace App\Events;

use App\Http\Resources\Order\BroadcastOrderResource;
use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired per-eligible-driver when an order enters the broadcast window or
 * its radius tier escalates. Sent on `private:driver.{driver_id}`. Codex
 * never adds dispatch sites for this event — see plan tasks 8 and 9 for
 * the two places it fires.
 */
final class OrderBroadcastToDriver implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly Order $order,
        public readonly int $driverId,
        public readonly int $tier,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('driver.'.$this->driverId)];
    }

    public function broadcastAs(): string
    {
        return 'order.broadcast_to_driver';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type' => 'order.broadcast_to_driver',
            'tier' => $this->tier,
            'order' => (new BroadcastOrderResource($this->order))->resolve(),
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
