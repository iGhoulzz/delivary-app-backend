<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class NotificationPreferenceService
{
    /**
     * @param  array{push?: bool, sms?: bool, email?: bool}  $preferences
     */
    public function update(User $target, array $preferences, User $actor): User
    {
        return DB::transaction(function () use ($target, $preferences, $actor): User {
            foreach ($preferences as $key => $enabled) {
                $target->{$this->columnFor($key)} = $enabled;
            }

            $target->save();

            Log::info('admin.notification_prefs.updated', [
                'actor' => $actor->public_id,
                'target' => $target->public_id,
                'changed' => array_keys($preferences),
            ]);

            return $target->refresh();
        });
    }

    private function columnFor(string $key): string
    {
        return match ($key) {
            'push' => 'push_notifications_enabled',
            'sms' => 'sms_notifications_enabled',
            'email' => 'email_notifications_enabled',
        };
    }
}
