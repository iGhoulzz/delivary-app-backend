<?php

declare(strict_types=1);

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Events\OrderBroadcastToDriver;
use App\Models\Order;
use App\Services\Order\StateTransitionService;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('driver', 'web');
});

it('dispatches OrderBroadcastToDriver per eligible driver on transition into awaiting_driver', function (): void {
    Event::fake([OrderBroadcastToDriver::class]);

    // Start in Created status (factory default) — Created → AwaitingDriver is allowed.
    $order = Order::factory()->create([
        'pickup_location' => Point::makeGeodetic(32.8872, 13.1913),
    ]);

    $alice = makeOnlineDriverAt(32.8880, 13.1920);
    $bob = makeOnlineDriverAt(32.8881, 13.1921);

    DB::transaction(function () use ($order): void {
        app(StateTransitionService::class)->transition(
            order: $order->fresh(),
            to: OrderStatus::AwaitingDriver,
            actorType: OrderActorType::System,
            metadata: ['event' => 'test_into_awaiting'],
        );
    });

    Event::assertDispatchedTimes(OrderBroadcastToDriver::class, 2);
    Event::assertDispatched(
        OrderBroadcastToDriver::class,
        fn (OrderBroadcastToDriver $e) => $e->driverPublicId === $alice->public_id,
    );
    Event::assertDispatched(
        OrderBroadcastToDriver::class,
        fn (OrderBroadcastToDriver $e) => $e->driverPublicId === $bob->public_id,
    );
});

it('does not dispatch when transition target is not awaiting_driver', function (): void {
    Event::fake([OrderBroadcastToDriver::class]);

    // Start in AwaitingDriver; transition to CancelledByUser (a valid non-AwaitingDriver target)
    $order = Order::factory()->create([
        'status' => OrderStatus::AwaitingDriver->value,
        'awaiting_driver_at' => now(),
    ]);

    DB::transaction(function () use ($order): void {
        app(StateTransitionService::class)->transition(
            order: $order,
            to: OrderStatus::CancelledByUser,
            actorType: OrderActorType::User,
            metadata: ['event' => 'test_cancel'],
        );
    });

    Event::assertNotDispatched(OrderBroadcastToDriver::class);
});
