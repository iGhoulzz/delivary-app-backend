<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PlatformSetting;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        // ─── Login: per-phone (account protection) + per-IP (credential stuffing) ──
        RateLimiter::for('login', function (Request $request): array {
            $maxPhone = (int) PlatformSetting::get('login_max_attempts_per_phone', 5);
            $windowMin = $this->secondsToMinutes(PlatformSetting::get('login_attempts_window_seconds', 900));
            $maxIp = (int) PlatformSetting::get('login_max_attempts_per_ip', 20);
            $windowMinIp = $this->secondsToMinutes(PlatformSetting::get('login_attempts_window_seconds_ip', 900));

            $phone = (string) $request->input('phone_number', '');

            return [
                Limit::perMinutes($windowMin, $maxPhone)
                    ->by('login_phone:'.$phone)
                    ->response($this->throttleResponseCallback()),
                Limit::perMinutes($windowMinIp, $maxIp)
                    ->by('login_ip:'.$request->ip())
                    ->response($this->throttleResponseCallback()),
            ];
        });

        // ─── OTP request resend: per-phone ────────────────────────────────────────
        RateLimiter::for('otp_request', function (Request $request): Limit {
            $max = (int) PlatformSetting::get('otp_max_requests_per_window', 3);
            $window = $this->secondsToMinutes(PlatformSetting::get('otp_request_window_seconds', 900));
            $phone = (string) $request->input('phone_number', '');

            return Limit::perMinutes($window, $max)
                ->by('otp_request:'.$phone)
                ->response($this->throttleResponseCallback());
        });

        // ─── OTP verify: per-phone (cross-cycle defense on top of per-code cap) ───
        RateLimiter::for('otp_verify', function (Request $request): Limit {
            $max = (int) PlatformSetting::get('otp_max_verify_per_window', 10);
            $window = $this->secondsToMinutes(PlatformSetting::get('otp_verify_window_seconds', 900));
            $phone = (string) $request->input('phone_number', '');

            return Limit::perMinutes($window, $max)
                ->by('otp_verify:'.$phone)
                ->response($this->throttleResponseCallback());
        });

        // ─── Password reset via email: per-IP (no account context yet) ────────────
        RateLimiter::for('password_reset_email', function (Request $request): Limit {
            return Limit::perMinutes(15, 5)
                ->by('password_reset_email_ip:'.$request->ip())
                ->response($this->throttleResponseCallback());
        });

        // ─── Orders quote: per-authenticated-user (defends compute cost) ─────────
        RateLimiter::for('orders_quote', function (Request $request): Limit {
            $userId = (string) (optional($request->user())->id ?? '');

            return Limit::perMinute(30)
                ->by('orders_quote:'.$userId)
                ->response($this->throttleResponseCallback());
        });

        // ─── Forgot password initiation: per-identifier (phone OR email) ─────────
        // The /password/forgot endpoint accepts either a phone or an email
        // depending on `channel`, so keying on `phone_number` (like the OTP
        // request limiter) wouldn't share throttle state for the email path.
        // We key on the `identifier` field instead so each account is rate-
        // limited regardless of which channel they pick.
        RateLimiter::for('orders_create', function (Request $request): Limit {
            $userId = (string) (optional($request->user())->id ?? '');

            return Limit::perMinute(10)
                ->by('orders_create:'.$userId)
                ->response($this->throttleResponseCallback());
        });

        RateLimiter::for('me_orders_read', function (Request $request): Limit {
            $userId = (string) (optional($request->user())->id ?? '');

            return Limit::perMinute(60)
                ->by('me_orders_read:'.$userId)
                ->response($this->throttleResponseCallback());
        });

        RateLimiter::for('driver_location', function (Request $request): Limit {
            $userId = (string) (optional($request->user())->id ?? '');

            return Limit::perMinute(120)
                ->by('driver_location:'.$userId)
                ->response($this->throttleResponseCallback());
        });

        RateLimiter::for('driver_action', function (Request $request): Limit {
            $userId = (string) (optional($request->user())->id ?? '');

            return Limit::perMinute(10)
                ->by('driver_action:'.$userId)
                ->response($this->throttleResponseCallback());
        });

        RateLimiter::for('me_action', function (Request $request): Limit {
            $userId = (string) (optional($request->user())->id ?? '');

            return Limit::perMinute(10)
                ->by('me_action:'.$userId)
                ->response($this->throttleResponseCallback());
        });

        RateLimiter::for('guest_tracking', function (Request $request): Limit {
            return Limit::perMinute(120)
                ->by('guest_tracking:'.$request->ip())
                ->response($this->throttleResponseCallback());
        });

        RateLimiter::for('forgot_password', function (Request $request): Limit {
            $identifier = (string) $request->input('identifier', '');

            return Limit::perMinutes(15, 3)
                ->by('forgot_password:'.$identifier)
                ->response($this->throttleResponseCallback());
        });
    }

    private function secondsToMinutes(mixed $seconds): int
    {
        return max(1, (int) ((int) $seconds / 60));
    }

    /**
     * Response callback invoked by ThrottleRequests when a limit is exceeded.
     * Laravel passes ($request, array $headers) — `Retry-After` lives in the
     * headers array so we surface it both as a header AND in the JSON body.
     */
    private function throttleResponseCallback(): \Closure
    {
        return function (Request $request, array $headers): JsonResponse {
            $retryAfter = (int) ($headers['Retry-After'] ?? 0);

            return response()->json([
                'error' => 'too_many_attempts',
                'message' => 'Too many attempts. Try again in '.$retryAfter.' seconds.',
                'retry_after' => $retryAfter,
            ], 429, $headers);
        };
    }
}
