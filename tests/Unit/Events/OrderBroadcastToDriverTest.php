<?php

declare(strict_types=1);

use App\Events\OrderBroadcastToDriver;
use App\Models\Order;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('broadcasts to private driver channel', function (): void {
    $driver = User::factory()->create();
    $order = Order::factory()->create();

    $event = new OrderBroadcastToDriver($order, $driver->id, tier: 1);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-driver.'.$driver->id);
});

it('has $afterCommit set to true', function (): void {
    $driver = User::factory()->create();
    $order = Order::factory()->create();

    $event = new OrderBroadcastToDriver($order, $driver->id, tier: 1);

    expect($event->afterCommit)->toBeTrue();
});

it('payload includes broadcast order resource and tier metadata', function (): void {
    $driver = User::factory()->create();
    $order = Order::factory()->create(['search_radius_tier' => 2]);

    $event = new OrderBroadcastToDriver($order, $driver->id, tier: 2);
    $payload = $event->broadcastWith();

    expect($payload)->toHaveKey('type', 'order.broadcast_to_driver');
    expect($payload)->toHaveKey('tier', 2);
    expect($payload)->toHaveKey('order');
    expect($payload['order'])->toBeArray();
    expect($payload['order'])->toHaveKey('id', $order->public_id);
});
