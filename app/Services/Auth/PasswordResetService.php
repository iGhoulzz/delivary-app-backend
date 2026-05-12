<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuthErrorCode;
use App\Enums\OtpPurpose;
use App\Models\User;
use App\Notifications\Auth\PasswordResetEmailNotification;
use Illuminate\Support\Facades\Cache;

final class PasswordResetService
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * Issue an OTP for the password-reset purpose.
     *
     * Anti-enumeration: caller always returns 200 to the client whether or
     * not the phone exists. We only actually send SMS if the user is real.
     */
    public function sendOtp(string $phone): void
    {
        if (User::where('phone_number', $phone)->exists()) {
            $this->otp->issue($phone, OtpPurpose::PasswordReset);
        }
    }

    /**
     * Send a signed email reset link.
     *
     * Anti-enumeration: caller always returns 200 either way. We only mail
     * if (a) a user exists with that email AND (b) their email is verified.
     */
    public function sendEmailLink(string $email): void
    {
        $user = User::where('email', $email)
            ->whereNotNull('email_verified_at')
            ->first();

        if ($user === null) {
            return;
        }

        $token = $this->buildSignedToken($user);
        $user->notify(new PasswordResetEmailNotification($token));
    }

    /**
     * Reset via the OTP track. Consumes the single-use cache token issued by
     * OtpController::verify() (purpose=password_reset).
     *
     * On success: hashes new password, revokes ALL existing Sanctum tokens
     * (security: log out of all devices on password change).
     *
     * @return AuthErrorCode|true true on success, error code on failure
     */
    public function resetViaOtp(string $token, string $newPassword): AuthErrorCode|true
    {
        $key = "password_reset_token:{$token}";
        $userId = Cache::get($key);
        if ($userId === null) {
            return AuthErrorCode::ResetTokenInvalid;
        }
        Cache::forget($key); // single-use — burn before any other work

        $user = User::find($userId);
        if ($user === null) {
            return AuthErrorCode::ResetTokenInvalid;
        }

        $user->password = $newPassword; // 'hashed' cast on User model
        $user->save();
        $user->tokens()->delete();      // log out of every device

        return true;
    }

    /**
     * Reset via the email link track. Validates the signed payload, then the
     * user's still-verified-email status, then HMAC. Same post-success
     * behavior as the OTP track (revoke all tokens).
     */
    public function resetViaEmail(string $signedToken, string $newPassword): AuthErrorCode|true
    {
        $payload = $this->parseSignedToken($signedToken);
        if ($payload === null) {
            return AuthErrorCode::ResetTokenInvalid;
        }
        if ($payload['expires'] < now()->timestamp) {
            return AuthErrorCode::ResetTokenInvalid;
        }

        $user = User::find($payload['id']);
        if ($user === null
            || $user->email === null
            || ! hash_equals(sha1($user->email), $payload['hash'])
        ) {
            return AuthErrorCode::ResetTokenInvalid;
        }

        if ($user->email_verified_at === null) {
            return AuthErrorCode::EmailNotVerified;
        }

        // Verify HMAC matches what we'd generate from this user's current
        // state. If the email or expires has been tampered, the sig won't
        // match.
        $expectedSig = hash_hmac(
            'sha256',
            $user->id.'|'.$user->email.'|'.$payload['expires'],
            (string) config('app.key'),
        );
        if (! hash_equals($expectedSig, $payload['sig'])) {
            return AuthErrorCode::ResetTokenInvalid;
        }

        $user->password = $newPassword;
        $user->save();
        $user->tokens()->delete();

        return true;
    }

    private function buildSignedToken(User $user): string
    {
        $expires = now()->addHour()->timestamp; // 60 min validity

        return base64_encode((string) json_encode([
            'id' => $user->id,
            'hash' => sha1((string) $user->email),
            'expires' => $expires,
            'sig' => hash_hmac(
                'sha256',
                $user->id.'|'.$user->email.'|'.$expires,
                (string) config('app.key'),
            ),
        ]));
    }

    /** @return array{id: int, hash: string, expires: int, sig: string}|null */
    private function parseSignedToken(string $token): ?array
    {
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return null;
        }
        $data = json_decode($decoded, true);
        if (! is_array($data)) {
            return null;
        }
        foreach (['id', 'hash', 'expires', 'sig'] as $key) {
            if (! array_key_exists($key, $data)) {
                return null;
            }
        }

        return [
            'id' => (int) $data['id'],
            'hash' => (string) $data['hash'],
            'expires' => (int) $data['expires'],
            'sig' => (string) $data['sig'],
        ];
    }
}
