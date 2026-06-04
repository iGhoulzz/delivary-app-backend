<?php

declare(strict_types=1);

namespace App\Http\Resources\Moderation;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserModerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->public_id,
            'account_status' => $user->account_status->value,
            'latest_action' => $this->whenLoaded(
                'moderationActions',
                fn (): ?ModerationActionResource => $user->moderationActions->first() === null
                    ? null
                    : new ModerationActionResource($user->moderationActions->first()),
            ),
        ];
    }
}
