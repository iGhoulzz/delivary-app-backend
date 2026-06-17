<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->public_id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->fullName(),
            'phone' => $user->phone_number,
            'email' => $user->email,
            'locale' => $user->locale,
            'account_status' => $user->account_status->value,
            'roles' => $user->getRoleNames()->values()->all(),
            'verification' => [
                'phone_verified' => $user->phone_verified_at !== null,
                'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
                'email_verified' => $user->email_verified_at !== null,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            ],
            'notification_prefs' => [
                'push' => (bool) $user->push_notifications_enabled,
                'sms' => (bool) $user->sms_notifications_enabled,
                'email' => (bool) $user->email_notifications_enabled,
            ],
            'links' => [
                'driver_public_id' => $this->whenLoaded(
                    'driverProfile',
                    fn (): ?string => $user->driverProfile === null ? null : $user->public_id,
                ),
                'merchant_public_id' => $this->whenLoaded(
                    'merchantProfile',
                    fn (): ?string => $user->merchantProfile?->public_id,
                ),
            ],
            'orders_as_customer' => $this->ordersAsCustomer($user),
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }

    /** @return array{total: int, recent: array<int, array<string, mixed>>} */
    private function ordersAsCustomer(User $user): array
    {
        $base = Order::query()
            ->where(static fn ($query) => $query
                ->where('sender_user_id', $user->id)
                ->orWhere('receiver_user_id', $user->id));

        $total = (clone $base)->count();

        $recent = (clone $base)
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(static fn (Order $order): array => [
                'id' => $order->public_id,
                'order_type' => $order->order_type->value,
                'status' => $order->status->value,
                'role' => $order->sender_user_id === $user->id ? 'sender' : 'receiver',
                'created_at' => $order->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return ['total' => $total, 'recent' => $recent];
    }
}
