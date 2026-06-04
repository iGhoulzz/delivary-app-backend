<?php

declare(strict_types=1);

namespace App\Http\Resources\Moderation;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserLookupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->public_id,
            'name' => $user->fullName(),
            'phone' => $user->phone_number,
            'roles' => $user->getRoleNames()->values()->all(),
            'account_status' => $user->account_status->value,
        ];
    }
}
