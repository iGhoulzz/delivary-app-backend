<?php

declare(strict_types=1);

/*
 * Auth-flow message bag (separate from Laravel's built-in auth.php which
 * holds login/throttle/password-validator strings). Keys here mirror values
 * in App\Enums\AuthErrorCode where applicable.
 *
 * Currently the controllers return hardcoded English strings. Wiring these
 * keys via __('auth_messages.invalid_credentials') is a future pass — the
 * file exists now so the locale infrastructure is in place when we make
 * that pass.
 */
return [
    // Error codes (mirror AuthErrorCode enum)
    'invalid_credentials' => 'Phone number or password is incorrect.',
    'phone_not_verified' => 'Verify your phone number before logging in.',
    'too_many_attempts' => 'Too many attempts. Try again in :seconds seconds.',
    'otp_invalid' => 'OTP is invalid or expired.',
    'otp_expired' => 'OTP has expired. Request a new one.',
    'reset_token_invalid' => 'Reset token is invalid or expired.',
    'verification_link_invalid' => 'Verification link is invalid or expired.',
    'email_not_verified' => 'Email is not verified.',
    'already_verified' => 'Already verified.',
    'no_pending_registration' => 'No pending registration for this phone.',
    'no_email_on_file' => 'No email address on file. Add one in your profile first.',

    // Successes & informational
    'otp_sent' => 'OTP sent.',
    'phone_verified' => 'Phone verified. You may now log in.',
    'email_verified' => 'Email verified.',
    'verification_link_sent' => 'Verification link sent.',
    'forgot_generic' => 'If the account exists, instructions were sent.',
    'registration_pending_otp' => 'OTP sent to your phone. Verify to activate your account.',
];
