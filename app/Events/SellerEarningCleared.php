<?php

declare(strict_types=1);

namespace App\Events;

use App\Http\Resources\Settlement\SellerEarningResource;
use App\Models\SellerEarning;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts one event per cleared seller earning. Per-row fan-out keeps the
 * payload auditable and lets clients update individual earning rows directly.
 */
final class SellerEarningCleared implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    private const string EVENT_NAME = 'seller.earning_cleared';

    public bool $afterCommit = true;

    public function __construct(
        public readonly SellerEarning $earning,
        public readonly string $newAvailableTotal,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        // Channel is keyed by the seller's User public_id (Critical Rule 11),
        // never the internal seller_user_id.
        $publicId = $this->earning->loadMissing('seller')->seller?->public_id;

        return [new PrivateChannel('user.'.$publicId)];
    }

    public function broadcastAs(): string
    {
        return self::EVENT_NAME;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $earning = $this->earning->loadMissing(['order:id,public_id,item_description']);

        return [
            'type' => self::EVENT_NAME,
            'earning' => (new SellerEarningResource($earning))->resolve(),
            'new_available_total' => $this->newAvailableTotal,
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
