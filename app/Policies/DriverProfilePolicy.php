<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DriverProfile;
use App\Models\User;

final class DriverProfilePolicy
{
    /**
     * Office staff can manage drivers tied to one of their assigned offices.
     * Admins always pass. Anyone else: deny.
     */
    public function manageInOffice(User $user, DriverProfile $driverProfile): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        if (! $user->hasRole('office_staff')) {
            return false;
        }

        return $user->officeStaffAssignments()
            ->whereNull('removed_at')
            ->where('office_id', $driverProfile->office_id)
            ->exists();
    }

    /** Driver themselves: only their own profile. */
    public function viewOwn(User $user, DriverProfile $driverProfile): bool
    {
        return $driverProfile->user_id === $user->id;
    }
}
