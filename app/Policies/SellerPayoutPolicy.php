<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SellerPayout;
use App\Models\User;

/**
 * Authorisation for SellerPayout.
 *
 * Sellers do not have a distinct Spatie role on this platform — see
 * {@see \App\Policies\SellerEarningPolicy} for the rationale. The
 * `viewBySeller` method therefore authorises by FK ownership alone
 * (`user_id` match), while the office_staff and admin methods on this
 * policy do gate by role. This asymmetry is intentional and mirrors
 * {@see \App\Policies\OrderPolicy}.
 */
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
