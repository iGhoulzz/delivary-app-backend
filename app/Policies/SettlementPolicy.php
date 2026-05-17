<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Settlement;
use App\Models\User;

final class SettlementPolicy
{
    public function process(User $staff): bool
    {
        return $staff->hasRole('office_staff')
            && $staff->officeStaffAssignments()->active()->exists();
    }

    public function previewForDriver(User $staff, User $driver): bool
    {
        return $this->process($staff) && $driver->hasRole('driver');
    }

    public function viewByDriver(User $driver, Settlement $settlement): bool
    {
        return $settlement->driver_id === $driver->id
            && $driver->hasRole('driver');
    }

    public function viewByOffice(User $staff, Settlement $settlement): bool
    {
        return $staff->hasRole('office_staff')
            && $staff->isAssignedToOffice($settlement->office_id);
    }

    public function reverse(User $admin, Settlement $settlement): bool
    {
        return $admin->hasRole('admin');
    }
}
