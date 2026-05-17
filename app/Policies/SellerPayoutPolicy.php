<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SellerPayout;
use App\Models\User;

final class SellerPayoutPolicy
{
    public function process(User $staff): bool
    {
        return $staff->hasRole('office_staff')
            && $staff->officeStaffAssignments()->active()->exists();
    }

    public function lookupSeller(User $staff): bool
    {
        return $this->process($staff);
    }

    public function viewBySeller(User $seller, SellerPayout $payout): bool
    {
        return $payout->user_id === $seller->id;
    }

    public function viewByOffice(User $staff, SellerPayout $payout): bool
    {
        return $staff->hasRole('office_staff')
            && $staff->isAssignedToOffice($payout->office_id);
    }

    public function viewByAdmin(User $admin, SellerPayout $payout): bool
    {
        return $admin->hasRole('admin');
    }
}
