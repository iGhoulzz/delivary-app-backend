<?php

declare(strict_types=1);

namespace App\Events;

use App\Http\Resources\Order\BroadcastOrderResource;
use App\Models\Order;
use App\Models\PlatformSetting;
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

    private const string EVENT_NAME = 'order.broadcast_to_driver';

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
        return self::EVENT_NAME;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type' => self::EVENT_NAME,
            'tier' => $this->tier,
            'expires_at' => $this->expiresAt(),
            'order' => (new BroadcastOrderResource($this->order))->resolve(),
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    /**
     * When this broadcast cycle closes for the driver. Tracks the order's
     * broadcast-window deadline (awaiting_driver_at + no_driver_after_minutes)
     * so the driver app can stop showing the order once the platform-side
     * timeout fires. Re-broadcasts on tier escalation refresh this same value
     * since awaiting_driver_at is unchanged by EscalationService::applyTier().
     */
    private function expiresAt(): ?string
    {
        $startedAt = $this->order->awaiting_driver_at;
        if ($startedAt === null) {
            return null;
        }

        $timeoutMinutes = (int) PlatformSetting::get('broadcast.no_driver_after_minutes', 10);

        return $startedAt->copy()->addMinutes($timeoutMinutes)->toIso8601String();
    }
}
