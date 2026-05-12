<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone_number' => $this->phone_number,
            'phone_verified' => $this->phone_verified_at !== null,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'locale' => $this->locale,
            'account_status' => $this->account_status->value,
            'roles' => $this->getRoleNames()->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
