<?php

declare(strict_types=1);

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Events\OrderBroadcastWithdrawn;
use App\Events\OrderStatusChanged;
use App\Listeners\BroadcastWithdrawnOnExit;
use App\Models\Order;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('driver', 'web');
});

it('fires withdrawn for each eligible driver when order leaves awaiting_driver', function (): void {
    Event::fake([OrderBroadcastWithdrawn::class]);

    $order = Order::factory()->create([
        'status' => OrderStatus::CancelledByUser->value,
        'pickup_location' => Point::makeGeodetic(32.8872, 13.1913),
        'search_radius_tier' => 1,
    ]);

    $alice = makeOnlineDriverAt(32.8880, 13.1920);
    $bob = makeOnlineDriverAt(32.8881, 13.1921);

    $listener = app(BroadcastWithdrawnOnExit::class);
    $listener->handle(new OrderStatusChanged(
        $order,
        OrderStatus::AwaitingDriver,
        OrderStatus::CancelledByUser,
        OrderActorType::User,
    ));

    Event::assertDispatched(OrderBroadcastWithdrawn::class, fn ($e) => $e->driverId === $alice->id);
    Event::assertDispatched(OrderBroadcastWithdrawn::class, fn ($e) => $e->driverId === $bob->id);
});

it('does nothing when from is not awaiting_driver', function (): void {
    Event::fake([OrderBroadcastWithdrawn::class]);

    $order = Order::factory()->create();

    app(BroadcastWithdrawnOnExit::class)->handle(new OrderStatusChanged(
        $order,
        OrderStatus::Assigned,
        OrderStatus::PickedUp,
        OrderActorType::Driver,
    ));

    Event::assertNotDispatched(OrderBroadcastWithdrawn::class);
});
