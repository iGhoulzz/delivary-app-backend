<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SellerEarning;
use App\Models\User;

final class SellerEarningPolicy
{
    public function viewBySeller(User $seller, SellerEarning $earning): bool
    {
        return $earning->seller_user_id === $seller->id;
    }

    public function viewOwnDashboard(User $user): bool
    {
        return true;
    }
}
