<?php

declare(strict_types=1);

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Enums\SellerEarningStatus;
use App\Events\DriverAccountUpdated;
use App\Events\NotificationReceived;
use App\Events\OrderDriverAssigned;
use App\Events\OrderDriverLocationUpdated;
use App\Events\OrderStatusChanged;
use App\Events\OrderStatusChangedPublic;
use App\Events\SellerEarningCleared;
use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\SellerEarning;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;

uses(RefreshDatabase::class);

it('broadcasts private order status changes with a broadcast-safe order payload', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::AwaitingDriver->value]);
    $event = new OrderStatusChanged($order, OrderStatus::Created, OrderStatus::AwaitingDriver, OrderActorType::System);

    $channels = $event->broadcastOn();
    $payload = $event->broadcastWith();

    expect($event->afterCommit)->toBeTrue();
    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-order.'.$order->public_id);
    expect($payload['type'])->toBe('order.status_changed');
    expect($payload['order']['id'])->toBe($order->public_id);
    expect($payload['transition'])->toMatchArray([
        'from' => OrderStatus::Created->value,
        'to' => OrderStatus::AwaitingDriver->value,
    ]);
});

it('broadcasts public order status changes to the tracking channel', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::AwaitingDriver->value]);
    $event = new OrderStatusChangedPublic($order, OrderStatus::Created, OrderStatus::AwaitingDriver, OrderActorType::System);

    $channels = $event->broadcastOn();
    $payload = $event->broadcastWith();

    expect($event->afterCommit)->toBeTrue();
    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(Channel::class);
    expect($channels[0]->name)->toBe('track.'.$order->tracking_token);
    expect($payload['type'])->toBe('order.status_changed_public');
    expect($payload['order']['id'])->toBe($order->public_id);
});

it('broadcasts assigned driver details to order parties and public tracking', function (): void {
    $driver = User::factory()->create();
    $profile = DriverProfile::factory()->create(['user_id' => $driver->id, 'vehicle_color' => 'white']);
    $order = Order::factory()->create(['driver_id' => $driver->id]);
    $event = new OrderDriverAssigned($order, $profile);

    $channels = $event->broadcastOn();
    $payload = $event->broadcastWith();

    expect($event->afterCommit)->toBeTrue();
    expect($channels)->toHaveCount(2);
    expect($channels[0]->name)->toBe('private-order.'.$order->public_id);
    expect($channels[1]->name)->toBe('track.'.$order->tracking_token);
    expect($payload['type'])->toBe('order.driver_assigned');
    expect($payload['driver'])->toHaveKeys(['first_name', 'vehicle_type', 'vehicle_color', 'rating_average', 'lifetime_deliveries', 'current_location']);
    expect($payload['driver'])->not->toHaveKeys(['id', 'user_id', 'office_id', 'vehicle_plate']);
});

it('broadcasts driver location updates synchronously to order parties and public tracking', function (): void {
    $order = Order::factory()->create();
    $event = new OrderDriverLocationUpdated(
        order: $order,
        lat: 32.8872,
        lng: 13.1913,
        heading: 90.0,
        accuracy: 8.0,
        recordedAt: now()->toIso8601String(),
    );

    $channels = $event->broadcastOn();
    $payload = $event->broadcastWith();

    expect($event->afterCommit)->toBeTrue();
    expect($channels)->toHaveCount(2);
    expect($channels[0]->name)->toBe('private-order.'.$order->public_id);
    expect($channels[1]->name)->toBe('track.'.$order->tracking_token);
    expect($payload)->toMatchArray([
        'type' => 'order.driver_location_updated',
        'order_public_id' => $order->public_id,
        'lat' => 32.8872,
        'lng' => 13.1913,
        'heading' => 90.0,
        'accuracy' => 8.0,
    ]);
});

it('broadcasts driver account updates to the driver channel', function (): void {
    $driver = User::factory()->create();
    $account = DriverAccount::factory()->create(['driver_id' => $driver->id, 'earnings_balance' => '12.50']);
    $event = new DriverAccountUpdated($account);

    $channels = $event->broadcastOn();
    $payload = $event->broadcastWith();

    expect($event->afterCommit)->toBeTrue();
    expect($channels)->toHaveCount(1);
    expect($channels[0]->name)->toBe('private-driver.'.$driver->public_id);
    expect($payload['type'])->toBe('driver.account_updated');
    expect($payload['account']['earnings_balance'])->toBe('12.50');
});

it('broadcasts database notifications to the notifiable user channel', function (): void {
    $notification = (new DatabaseNotification)->forceFill([
        'id' => 'notification-1',
        'type' => 'test.notification',
        'data' => ['message' => 'hello'],
        'created_at' => now(),
    ]);
    $event = new NotificationReceived('01HZXUSERPUBLIC', $notification);

    $channels = $event->broadcastOn();
    $payload = $event->broadcastWith();

    expect($event->afterCommit)->toBeTrue();
    expect($channels)->toHaveCount(1);
    expect($channels[0]->name)->toBe('private-user.01HZXUSERPUBLIC');
    expect($payload['type'])->toBe('notification.received');
    expect($payload['notification'])->toMatchArray([
        'id' => 'notification-1',
        'type' => 'test.notification',
        'data' => ['message' => 'hello'],
    ]);
});

it('broadcasts cleared seller earnings to the seller user channel', function (): void {
    $seller = User::factory()->create();
    $order = Order::factory()->create(['sender_user_id' => $seller->id]);
    $earning = SellerEarning::create([
        'order_id' => $order->id,
        'seller_user_id' => $seller->id,
        'amount' => '25.00',
        'status' => SellerEarningStatus::Available->value,
        'available_at' => now(),
    ]);
    $event = new SellerEarningCleared($earning, '25.00');

    $channels = $event->broadcastOn();
    $payload = $event->broadcastWith();

    expect($event->afterCommit)->toBeTrue();
    expect($channels)->toHaveCount(1);
    expect($channels[0]->name)->toBe('private-user.'.$seller->public_id);
    expect($payload['type'])->toBe('seller.earning_cleared');
    expect($payload['earning']['id'])->toBe($earning->public_id);
    expect($payload['new_available_total'])->toBe('25.00');
});
