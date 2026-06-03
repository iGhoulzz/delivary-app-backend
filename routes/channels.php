<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{userPublicId}', function (User $user, string $userPublicId): bool {
    return $user->public_id === $userPublicId;
});

Broadcast::channel('order.{publicId}', function (User $user, string $publicId): bool {
    $order = Order::query()->where('public_id', $publicId)->first();
    if ($order === null) {
        return false;
    }

    return $user->id === $order->sender_user_id
        || ($order->receiver_user_id !== null && $user->id === $order->receiver_user_id);
});

Broadcast::channel('driver.{driverPublicId}', function (User $user, string $driverPublicId): bool {
    return $user->public_id === $driverPublicId && $user->hasRole('driver');
});

// `track.{trackingToken}` is a public channel — no callback needed.
