<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

final class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $fromStatus,
        public readonly OrderStatus $toStatus,
        public readonly OrderActorType $actorType,
        public readonly ?int $actorId = null,
    ) {}
}
