<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateNotificationPreferencesRequest;
use App\Http\Resources\Admin\NotificationPreferencesResource;
use App\Models\User;
use App\Services\User\NotificationPreferenceService;

final class UserNotificationPreferenceController extends Controller
{
    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    public function __invoke(UpdateNotificationPreferencesRequest $request, User $user): NotificationPreferencesResource
    {
        $this->authorize('updateNotificationPreferences', $user);

        return new NotificationPreferencesResource(
            $this->preferences->update($user, $request->preferences(), $request->user()),
        );
    }
}
