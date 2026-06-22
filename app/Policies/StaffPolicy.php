<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class StaffPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function view(User $actor, User $staff): bool
    {
        return $actor->hasRole('admin');
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function update(User $actor, User $staff): bool
    {
        return $actor->hasRole('admin');
    }

    public function suspend(User $actor, User $staff): bool
    {
        return $actor->hasRole('admin');
    }

    public function reinstate(User $actor, User $staff): bool
    {
        return $actor->hasRole('admin');
    }

    public function deactivate(User $actor, User $staff): bool
    {
        return $actor->hasRole('admin');
    }

    public function resetTempPassword(User $actor, User $staff): bool
    {
        return $actor->hasRole('admin');
    }

    public function manageOfficeAssignments(User $actor, User $staff): bool
    {
        return $actor->hasRole('admin');
    }

    public function updateNotificationPreferences(User $actor, User $target): bool
    {
        return $actor->hasRole('admin');
    }
}
