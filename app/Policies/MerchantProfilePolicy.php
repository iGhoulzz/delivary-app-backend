<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MerchantProfile;
use App\Models\User;

final class MerchantProfilePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function view(User $actor, MerchantProfile $merchant): bool
    {
        return $actor->hasRole('admin');
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function update(User $actor, MerchantProfile $merchant): bool
    {
        return $actor->hasRole('admin');
    }

    public function suspend(User $actor, MerchantProfile $merchant): bool
    {
        return $actor->hasRole('admin');
    }

    public function reactivate(User $actor, MerchantProfile $merchant): bool
    {
        return $actor->hasRole('admin');
    }

    public function ban(User $actor, MerchantProfile $merchant): bool
    {
        return $actor->hasRole('admin');
    }
}
