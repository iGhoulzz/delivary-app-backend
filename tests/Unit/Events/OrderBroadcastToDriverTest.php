<?php

declare(strict_types=1);

use App\Events\OrderBroadcastToDriver;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('broadcasts to private driver channel', function (): void {
    $driver = User::factory()->create();
    $order = Order::factory()->create();

    $event = new OrderBroadcastToDriver($order, $driver->public_id, tier: 1);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-driver.'.$driver->public_id);
});

it('has $afterCommit set to true', function (): void {
    $driver = User::factory()->create();
    $order = Order::factory()->create();

    $event = new OrderBroadcastToDriver($order, $driver->public_id, tier: 1);

    expect($event->afterCommit)->toBeTrue();
});

it('payload includes broadcast order resource and tier metadata', function (): void {
    $driver = User::factory()->create();
    $order = Order::factory()->create(['search_radius_tier' => 2]);

    $event = new OrderBroadcastToDriver($order, $driver->public_id, tier: 2);
    $payload = $event->broadcastWith();

    expect($payload)->toHaveKey('type', 'order.broadcast_to_driver');
    expect($payload)->toHaveKey('tier', 2);
    expect($payload)->toHaveKey('expires_at');
    expect($payload)->toHaveKey('order');
    expect($payload['order'])->toBeArray();
    expect($payload['order'])->toHaveKey('id', $order->public_id);
});

it('expires_at is awaiting_driver_at + broadcast.no_driver_after_minutes', function (): void {
    PlatformSetting::set('broadcast.no_driver_after_minutes', '10');

    $driver = User::factory()->create();
    $startedAt = now()->subMinutes(2);
    $order = Order::factory()->create([
        'awaiting_driver_at' => $startedAt,
    ]);

    $payload = (new OrderBroadcastToDriver($order, $driver->public_id, tier: 1))->broadcastWith();

    expect($payload['expires_at'])->toBe(
        $startedAt->copy()->addMinutes(10)->toIso8601String(),
    );
});

it('expires_at is null when awaiting_driver_at is null', function (): void {
    $driver = User::factory()->create();
    $order = Order::factory()->create(['awaiting_driver_at' => null]);

    $payload = (new OrderBroadcastToDriver($order, $driver->public_id, tier: 1))->broadcastWith();

    expect($payload['expires_at'])->toBeNull();
});
