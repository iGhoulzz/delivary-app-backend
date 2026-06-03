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

        // Channel is keyed by the notifiable's public_id (Critical Rule 11),
        // never the internal id. Skip notifiables without one.
        $publicId = $event->notifiable->public_id ?? null;
        if (! is_string($publicId) || $publicId === '') {
            return;
        }

        event(new NotificationReceived($publicId, $event->response));
    }
}
