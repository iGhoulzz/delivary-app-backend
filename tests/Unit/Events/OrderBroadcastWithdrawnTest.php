<?php

declare(strict_types=1);

use App\Events\OrderBroadcastWithdrawn;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('broadcasts to one driver channel with $afterCommit', function (): void {
    $event = new OrderBroadcastWithdrawn(
        orderPublicId: 'TESTORDERPUBLIC',
        driverId: 42,
        reason: 'claimed_by_another',
    );

    expect($event->afterCommit)->toBeTrue();
    expect($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class);
    expect($event->broadcastOn()[0]->name)->toBe('private-driver.42');

    $payload = $event->broadcastWith();
    expect($payload)->toMatchArray([
        'type' => 'order.broadcast_withdrawn',
        'order_public_id' => 'TESTORDERPUBLIC',
        'reason' => 'claimed_by_another',
    ]);
});
