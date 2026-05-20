<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\NotificationReceived;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;

final class BroadcastDatabaseNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return;
        }

        if (! $event->response instanceof DatabaseNotification) {
            return;
        }

        $notifiableId = $event->notifiable->getKey();
        if ($notifiableId === null) {
            return;
        }

        event(new NotificationReceived((int) $notifiableId, $event->response));
    }
}
