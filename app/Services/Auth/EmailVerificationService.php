<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\Auth\EmailVerificationNotification;

final class EmailVerificationService
{
    /**
     * Send a fresh email verification link to the user's currently-attached
     * email. No-op if the user has no email on file.
     */
    public function sendVerificationLink(User $user): void
    {
        if ($user->email === null) {
            return;
        }
        $user->notify(new EmailVerificationNotification);
    }

    /**
     * Verify a hash from the signed URL against the user's CURRENT email.
     * If the user changed their email after the link was issued, the hashes
     * won't match and verification is rejected — defends against
     * already-mailed-but-now-stale links being replayed.
     *
     * Idempotent on success: re-clicking a still-valid link after the first
     * verification just returns true without rewriting the timestamp.
     */
    public function verify(User $user, string $hashFromUrl): bool
    {
        if ($user->email === null) {
            return false;
        }
        if (! hash_equals(sha1($user->email), $hashFromUrl)) {
            return false;
        }
        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
            $user->save();
        }

        return true;
    }
}
