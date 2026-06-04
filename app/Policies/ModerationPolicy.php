<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class ModerationPolicy
{
    public function moderate(User $actor, User $target): bool
    {
        return $actor->hasRole('admin') && $actor->id !== $target->id;
    }
}
