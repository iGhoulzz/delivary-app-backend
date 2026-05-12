<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

final class AuthSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // OTP
            ['key' => 'otp_code_length',                 'value' => '6',   'type' => 'integer',
                'description' => 'Number of digits in an OTP code.'],
            ['key' => 'otp_ttl_seconds',                 'value' => '300', 'type' => 'integer',
                'description' => 'OTP code validity window in seconds (5 min).'],
            ['key' => 'otp_max_attempts_per_code',       'value' => '5',   'type' => 'integer',
                'description' => 'Max wrong verify attempts before a code is invalidated.'],
            ['key' => 'otp_max_requests_per_window',     'value' => '3',   'type' => 'integer',
                'description' => 'Max OTP send requests per phone in the request window.'],
            ['key' => 'otp_request_window_seconds',      'value' => '900', 'type' => 'integer',
                'description' => 'OTP request rate-limit window in seconds (15 min).'],
            ['key' => 'otp_max_verify_per_window',       'value' => '10',  'type' => 'integer',
                'description' => 'Max OTP verify attempts per phone in the verify window.'],
            ['key' => 'otp_verify_window_seconds',       'value' => '900', 'type' => 'integer',
                'description' => 'OTP verify rate-limit window in seconds (15 min).'],

            // Login throttle
            ['key' => 'login_max_attempts_per_phone',    'value' => '5',    'type' => 'integer',
                'description' => 'Max failed login attempts per phone in the login window.'],
            ['key' => 'login_attempts_window_seconds',   'value' => '900',  'type' => 'integer',
                'description' => 'Login attempt rate-limit window in seconds.'],
            ['key' => 'login_lockout_seconds_per_phone', 'value' => '900',  'type' => 'integer',
                'description' => 'Lockout duration after per-phone threshold is hit (15 min).'],
            ['key' => 'login_max_attempts_per_ip',       'value' => '20',   'type' => 'integer',
                'description' => 'Max failed login attempts per IP in the login window.'],
            ['key' => 'login_attempts_window_seconds_ip', 'value' => '900',  'type' => 'integer',
                'description' => 'Login attempt rate-limit window per IP in seconds.'],
            ['key' => 'login_lockout_seconds_per_ip',    'value' => '3600', 'type' => 'integer',
                'description' => 'Lockout duration after per-IP threshold is hit (1 hour).'],

            // Misc auth
            ['key' => 'password_min_length',             'value' => '8',    'type' => 'integer',
                'description' => 'Minimum password length.'],
            ['key' => 'email_verification_ttl_hours',    'value' => '24',   'type' => 'integer',
                'description' => 'Validity window of an email verification signed link.'],
            ['key' => 'password_reset_token_ttl_seconds', 'value' => '600',  'type' => 'integer',
                'description' => 'Validity window of an OTP-track password reset token (10 min).'],
        ];

        foreach ($defaults as $row) {
            PlatformSetting::updateOrCreate(['key' => $row['key']], $row);
        }
    }
}
