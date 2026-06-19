<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserDirectoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $sent = (int) ($user->sent_orders_count ?? 0);
        $received = (int) ($user->received_orders_count ?? 0);

        return [
            'id' => $user->public_id,
            'name' => $user->fullName(),
            'phone' => $user->phone_number,
            'email' => $user->email,
            'account_status' => $user->account_status->value,
            'roles' => $user->getRoleNames()->values()->all(),
            'phone_verified' => $user->phone_verified_at !== null,
            'email_verified' => $user->email_verified_at !== null,
            'orders_count' => $sent + $received,
            'joined' => $user->created_at?->toIso8601String(),
            'driver_public_id' => $this->whenLoaded(
                'driverProfile',
                fn (): ?string => $user->driverProfile === null ? null : $user->public_id,
            ),
            'merchant_public_id' => $this->whenLoaded(
                'merchantProfile',
                fn (): ?string => $user->merchantProfile?->public_id,
            ),
        ];
    }
}
