<?php

declare(strict_types=1);

use App\Events\NotificationReceived;
use App\Listeners\BroadcastDatabaseNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('dispatches realtime notification event for database notifications', function (): void {
    Event::fake([NotificationReceived::class]);

    $user = User::factory()->create();
    $notification = (new DatabaseNotification)->forceFill([
        'id' => 'notification-1',
        'type' => 'test.notification',
        'data' => ['message' => 'hello'],
        'created_at' => now(),
    ]);

    app(BroadcastDatabaseNotification::class)->handle(new NotificationSent(
        $user,
        new class extends Notification {},
        'database',
        $notification,
    ));

    Event::assertDispatched(
        NotificationReceived::class,
        fn (NotificationReceived $event): bool => $event->userId === $user->id
            && $event->notification->id === 'notification-1',
    );
});

it('ignores non-database notification channels', function (): void {
    Event::fake([NotificationReceived::class]);

    app(BroadcastDatabaseNotification::class)->handle(new NotificationSent(
        User::factory()->create(),
        new class extends Notification {},
        'mail',
    ));

    Event::assertNotDispatched(NotificationReceived::class);
});
