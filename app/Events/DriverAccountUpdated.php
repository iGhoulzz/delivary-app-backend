<?php

declare(strict_types=1);

namespace App\Events;

use App\Http\Resources\DriverAccountResource;
use App\Models\DriverAccount;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DriverAccountUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    private const string EVENT_NAME = 'driver.account_updated';

    public bool $afterCommit = true;

    public function __construct(public readonly DriverAccount $account) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        // Channel is keyed by the driver's User public_id (Critical Rule 11),
        // never the internal driver_id.
        $publicId = $this->account->loadMissing('driver')->driver?->public_id;

        return [new PrivateChannel('driver.'.$publicId)];
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
            'account' => (new DriverAccountResource($this->account))->resolve(),
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
