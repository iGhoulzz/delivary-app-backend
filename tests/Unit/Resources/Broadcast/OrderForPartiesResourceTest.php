<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Http\Resources\Broadcast\OrderForPartiesResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('strips sender/receiver-only sensitive fields from broadcast payload', function (): void {
    $order = Order::factory()->create([
        'pickup_notes' => 'leave at door',       // sender-only field, must NOT appear
        'receiver_phone' => '+218910000000',      // sender-only field, must NOT appear
        'receiver_name' => 'Ahmed',               // sender-only field, must NOT appear
        'commission_amount' => '5.00',            // sender-only field, must NOT appear
        'delivery_code' => 'XYZ123',              // receiver-only field, must NOT appear
        'pickup_code' => 'ABC456',                // sender-only field, must NOT appear
    ]);

    $array = (new OrderForPartiesResource($order))->resolve();

    expect($array)->toBeArray();
    expect($array)->toHaveKey('id', $order->public_id);
    expect($array)->toHaveKey('status');
    expect($array)->toHaveKey('display_status');
    expect($array)->toHaveKey('pickup');
    expect($array)->toHaveKey('receiver');
    expect($array)->toHaveKey('item');
    expect($array)->toHaveKey('pricing');
    expect($array)->toHaveKey('timestamps');

    // No sender/receiver-only sensitive fields
    expect($array['pickup'])->not->toHaveKey('notes');
    expect($array['pickup'])->not->toHaveKey('pickup_code');
    expect($array['receiver'])->not->toHaveKey('phone');
    expect($array['receiver'])->not->toHaveKey('name');
    expect($array['receiver'])->not->toHaveKey('delivery_code');
    expect($array['pricing'])->not->toHaveKey('commission_amount');
});

it('exposes driver block when an active driver is assigned', function (): void {
    $driver = User::factory()->create(['first_name' => 'Sami']);
    $order = Order::factory()->create([
        'driver_id' => $driver->id,
        'status' => OrderStatus::DriverEnRoutePickup->value,
    ]);

    $array = (new OrderForPartiesResource($order->load('driver.driverProfile')))->resolve();

    expect($array['driver'])->not->toBeNull();
    expect($array['driver'])->toHaveKey('first_name', 'Sami');
});

it('returns null driver block when no driver assigned', function (): void {
    $order = Order::factory()->create(['driver_id' => null]);

    $array = (new OrderForPartiesResource($order))->resolve();

    expect($array['driver'])->toBeNull();
});

it('produces identical output regardless of who is authenticated on the Request', function (): void {
    $order = Order::factory()->create([
        'pickup_notes' => 'leave at door',
        'receiver_phone' => '+218910000000',
    ]);
    $resource = new OrderForPartiesResource($order);

    $anonRequest = Request::create('/');
    $authedRequest = Request::create('/');
    $authedRequest->setUserResolver(fn () => User::factory()->create());

    expect($resource->toArray($anonRequest))->toEqual($resource->toArray($authedRequest));
});
