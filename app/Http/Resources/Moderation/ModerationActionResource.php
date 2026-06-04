<?php

declare(strict_types=1);

namespace App\Http\Resources\Moderation;

use App\Models\AccountModerationAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccountModerationAction */
final class ModerationActionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AccountModerationAction $action */
        $action = $this->resource;

        return [
            'id' => $action->public_id,
            'action' => $action->action->value,
            'reason_code' => $action->reason_code->value,
            'detail' => $action->detail,
            'from_status' => $action->from_status->value,
            'to_status' => $action->to_status->value,
            'actor' => $this->whenLoaded('actor', fn (): ?array => $action->actor === null ? null : [
                'id' => $action->actor->public_id,
                'name' => $action->actor->fullName(),
            ]),
            'created_at' => $action->created_at?->toIso8601String(),
        ];
    }
}
