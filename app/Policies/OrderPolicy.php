<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;

/**
 * Authorisation for the Order model.
 *
 * Design note on role asymmetry: sender-side abilities authorise by FK
 * ownership alone (sender_user_id match) — any account that owns the
 * order can act as its sender, regardless of Spatie roles. The driver
 * ability ({@see act()}) additionally requires the `driver` role,
 * because a driver-side action is only meaningful for a Spatie-driver.
 * Do NOT add role gates to the sender-side methods; FK ownership is
 * the source of truth there.
 */
final class OrderPolicy
{
    public function viewAsSender(User $user, Order $order): bool
    {
        return $user->id === $order->sender_user_id;
    }

    public function viewAsReceiver(User $user, Order $order): bool
    {
        return $order->receiver_user_id !== null
            && $user->id === $order->receiver_user_id;
    }

    public function view(User $user, Order $order): bool
    {
        return $this->viewAsSender($user, $order)
            || $this->viewAsReceiver($user, $order);
    }

    public function act(User $user, Order $order): bool
    {
        return $order->driver_id === $user->id
            && $user->hasRole('driver');
    }

    public function retryByUser(User $user, Order $order): bool
    {
        return $this->viewAsSender($user, $order)
            && $order->status === OrderStatus::NoDriverAvailable;
    }

    public function cancelByUser(User $user, Order $order): bool
    {
        return $this->viewAsSender($user, $order)
            && $order->status === OrderStatus::NoDriverAvailable;
    }

    public function confirmGeofenceBySender(User $user, Order $order): bool
    {
        return $this->viewAsSender($user, $order)
            && $order->status === OrderStatus::DriverEnRoutePickup;
    }
}
