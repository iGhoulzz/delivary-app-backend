<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class OrderDriverLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    private const string EVENT_NAME = 'order.driver_location_updated';

    public bool $afterCommit = true;

    public function __construct(
        public readonly Order $order,
        public readonly float $lat,
        public readonly float $lng,
        public readonly ?float $heading,
        public readonly ?float $accuracy,
        public readonly string $recordedAt,
    ) {}

    /** @return array<int, Channel|PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('order.'.$this->order->public_id),
            new Channel('track.'.$this->order->tracking_token),
        ];
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
            'order_public_id' => $this->order->public_id,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'heading' => $this->heading,
            'accuracy' => $this->accuracy,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
