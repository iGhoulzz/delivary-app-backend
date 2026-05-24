<?php

declare(strict_types=1);

use App\Enums\DriverAccountTransactionReason;
use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Enums\SellerEarningStatus;
use App\Events\DriverAccountUpdated;
use App\Events\OrderDriverAssigned;
use App\Events\OrderDriverLocationUpdated;
use App\Events\OrderStatusChanged;
use App\Events\OrderStatusChangedPublic;
use App\Events\SellerEarningCleared;
use App\Jobs\ClearSellerEarningsJob;
use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\SellerEarning;
use App\Models\User;
use App\Services\Driver\DriverAccountLedgerService;
use App\Services\Driver\PresenceService;
use App\Services\Order\AdminAssignmentService;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('dispatches paired status events and assigned-driver event for admin assignment', function (): void {
    Event::fake([OrderStatusChanged::class, OrderStatusChangedPublic::class, OrderDriverAssigned::class]);

    $admin = User::factory()->create();
    $driver = User::factory()->create();
    $order = Order::factory()->create([
        'status' => OrderStatus::AwaitingDriver->value,
        'awaiting_driver_at' => now(),
        'driver_id' => null,
    ]);
    DriverProfile::factory()->create([
        'user_id' => $driver->id,
        'status' => DriverStatus::Active->value,
        'activity_status' => DriverActivityStatus::Online->value,
    ]);
    DriverAccount::factory()->create(['driver_id' => $driver->id, 'max_cash_liability' => '200.00']);

    app(AdminAssignmentService::class)->assign($admin, $order, $driver);

    Event::assertDispatched(OrderStatusChanged::class);
    Event::assertDispatched(OrderStatusChangedPublic::class);
    Event::assertDispatched(OrderDriverAssigned::class);
});

it('dispatches realtime location after a driver location update commits', function (): void {
    Event::fake([OrderDriverLocationUpdated::class]);

    $driver = User::factory()->create();
    DriverProfile::factory()->create([
        'user_id' => $driver->id,
        'status' => DriverStatus::Active->value,
        'activity_status' => DriverActivityStatus::OnOrder->value,
        'current_location' => Point::makeGeodetic(32.8872, 13.1913),
    ]);
    Order::factory()->create([
        'driver_id' => $driver->id,
        'status' => OrderStatus::DriverEnRoutePickup->value,
    ]);

    app(PresenceService::class)->updateLocation($driver, [
        'lat' => 32.88,
        'lng' => 13.19,
        'heading' => 120,
        'accuracy_meters' => 9,
    ]);

    Event::assertDispatched(
        OrderDriverLocationUpdated::class,
        fn (OrderDriverLocationUpdated $event): bool => $event->lat === 32.88
            && $event->lng === 13.19
            && $event->heading === 120.0
            && $event->accuracy === 9.0,
    );
});

it('dispatches driver account updates from the ledger service', function (): void {
    Event::fake([DriverAccountUpdated::class]);

    $driver = User::factory()->create();
    $order = Order::factory()->create();
    DriverAccount::factory()->create(['driver_id' => $driver->id]);

    app(DriverAccountLedgerService::class)->applyFee(
        driver: $driver,
        amount: '4.00',
        reason: DriverAccountTransactionReason::StrikeFee,
        reference: $order,
    );

    Event::assertDispatched(DriverAccountUpdated::class);
});

it('dispatches seller earning cleared events per advanced earning', function (): void {
    Event::fake([SellerEarningCleared::class]);

    $seller = User::factory()->create();
    $order = Order::factory()->create(['sender_user_id' => $seller->id]);
    $earning = SellerEarning::create([
        'order_id' => $order->id,
        'seller_user_id' => $seller->id,
        'amount' => '30.00',
        'status' => SellerEarningStatus::PendingClearance->value,
        'cleared_at' => now()->subDays(3),
    ]);

    app(ClearSellerEarningsJob::class)->handle();

    Event::assertDispatched(
        SellerEarningCleared::class,
        fn (SellerEarningCleared $event): bool => $event->earning->is($earning)
            && $event->newAvailableTotal === '30.00',
    );
});
