<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class NotificationPreferencesResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'notification_preferences' => [
                'push' => (bool) $user->push_notifications_enabled,
                'sms' => (bool) $user->sms_notifications_enabled,
                'email' => (bool) $user->email_notifications_enabled,
            ],
        ];
    }
}
