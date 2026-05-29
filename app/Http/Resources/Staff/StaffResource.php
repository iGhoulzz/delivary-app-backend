<?php

declare(strict_types=1);

namespace App\Http\Resources\Staff;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class StaffResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $u */
        $u = $this->resource;

        return [
            'id' => $u->public_id,
            'phone_number' => $u->phone_number,
            'first_name' => $u->first_name,
            'last_name' => $u->last_name,
            'email' => $u->email,
            'role' => $u->getRoleNames()->first(),
            'account_status' => $u->account_status->value,
            'must_change_password' => $u->must_change_password,
            'phone_verified_at' => $u->phone_verified_at?->toIso8601String(),
            'email_verified_at' => $u->email_verified_at?->toIso8601String(),
            'office_assignments' => $this->whenLoaded(
                'activeOfficeAssignments',
                fn () => $u->activeOfficeAssignments->map(fn ($a) => [
                    'id' => $a->id,
                    'office_id' => $a->office_id,
                    'is_manager' => (bool) $a->is_manager,
                    'assigned_at' => $a->assigned_at?->toIso8601String(),
                    'removed_at' => $a->removed_at?->toIso8601String(),
                ]),
            ),
            'created_at' => $u->created_at?->toIso8601String(),
            'updated_at' => $u->updated_at?->toIso8601String(),
        ];
    }
}
