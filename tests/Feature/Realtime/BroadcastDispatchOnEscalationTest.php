<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Events\OrderBroadcastToDriver;
use App\Models\Order;
use App\Services\Order\EscalationService;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('driver', 'web');
});

it('dispatches OrderBroadcastToDriver on tier escalation', function (): void {
    Event::fake([OrderBroadcastToDriver::class]);

    $order = Order::factory()->create([
        'status' => OrderStatus::AwaitingDriver->value,
        'search_radius_tier' => 1,
        'awaiting_driver_at' => now()->subMinutes(4), // crosses tier-2 threshold (default 3 min)
        'pickup_location' => Point::makeGeodetic(32.8872, 13.1913),
    ]);

    makeOnlineDriverAt(32.8880, 13.1920);
    makeOnlineDriverAt(32.8881, 13.1921);

    app(EscalationService::class)->process($order);

    Event::assertDispatchedTimes(OrderBroadcastToDriver::class, 2);
    Event::assertDispatched(
        OrderBroadcastToDriver::class,
        fn (OrderBroadcastToDriver $e) => $e->tier === 2,
    );
});
