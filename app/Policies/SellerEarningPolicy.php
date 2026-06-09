<?php

declare(strict_types=1);

namespace App\Policies;

use App\Http\Controllers\Api\Me\Settlement\ShowEarningsController;
use App\Models\SellerEarning;
use App\Models\User;

/**
 * Authorisation for SellerEarning.
 *
 * Sellers do not have a distinct Spatie role on this platform — any
 * authenticated user becomes a "seller" implicitly by creating a P2P
 * sale order (their `id` matches `seller_user_id` on the earning).
 * For that reason, the seller-facing abilities here authorise by FK
 * ownership alone, mirroring {@see OrderPolicy::viewAsSender}.
 * Do NOT add role gates to these methods.
 */
final class SellerEarningPolicy
{
    public function viewBySeller(User $seller, SellerEarning $earning): bool
    {
        return $earning->seller_user_id === $seller->id;
    }

    /**
     * Any authenticated user may view their own earnings dashboard. The
     * dashboard query in {@see ShowEarningsController}
     * scopes by `$user->id`, so the policy is a route-auth sanity gate.
     */
    public function viewOwnDashboard(User $user): bool
    {
        return true;
    }
}
