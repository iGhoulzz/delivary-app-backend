<?php

declare(strict_types=1);

namespace App\Events;

use App\Http\Resources\Broadcast\DriverForOrderResource;
use App\Models\DriverProfile;
use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class OrderDriverAssigned implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    private const string EVENT_NAME = 'order.driver_assigned';

    public bool $afterCommit = true;

    public function __construct(
        public readonly Order $order,
        public readonly DriverProfile $driverProfile,
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
        $profile = $this->driverProfile->loadMissing(['user']);

        return [
            'type' => self::EVENT_NAME,
            'driver' => (new DriverForOrderResource($profile))->resolve(),
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
