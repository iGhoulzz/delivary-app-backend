<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Moderation\ModerationActionRequest;
use App\Http\Resources\Moderation\ModerationActionResource;
use App\Http\Resources\Moderation\UserModerationResource;
use App\Models\User;
use App\Services\Moderation\AccountModerationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class UserModerationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly AccountModerationService $moderation) {}

    public function suspend(ModerationActionRequest $request, User $user): UserModerationResource
    {
        $this->authorize('moderate', $user);

        $updated = $this->moderation->suspend($user, $request->user(), $request->reason(), $request->detail());

        return new UserModerationResource($this->loadLatestAction($updated));
    }

    public function ban(ModerationActionRequest $request, User $user): UserModerationResource
    {
        $this->authorize('moderate', $user);

        $updated = $this->moderation->ban($user, $request->user(), $request->reason(), $request->detail());

        return new UserModerationResource($this->loadLatestAction($updated));
    }

    public function reinstate(ModerationActionRequest $request, User $user): UserModerationResource
    {
        $this->authorize('moderate', $user);

        $updated = $this->moderation->reinstate($user, $request->user(), $request->reason(), $request->detail());

        return new UserModerationResource($this->loadLatestAction($updated));
    }

    public function history(User $user): AnonymousResourceCollection
    {
        $this->authorize('moderate', $user);

        $actions = $user->moderationActions()
            ->with('actor')
            ->latest('id')
            ->paginate(20);

        return ModerationActionResource::collection($actions);
    }

    private function loadLatestAction(User $user): User
    {
        return $user->load([
            'moderationActions' => fn ($query) => $query->with('actor')->latest('id')->limit(1),
        ]);
    }
}
