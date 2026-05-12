# Auth System — Design Spec

**Date:** 2026-05-05
**Status:** ✅ Implemented (2026-05-07)
**Scope:** First HTTP-layer milestone after schema phase. Phone-first registration, OTP phone verification, password login, dual-track password reset (OTP-to-phone + email link), email verification triggered by profile updates.
**Out of scope:** Driver onboarding endpoints, order endpoints, admin panel, real SMS/SMTP provider integration (abstracted but stubbed).

---

## 1. Goals

1. Public self-registration is for **regular users only** — drivers/merchants/admins are role-attached internally to existing user accounts (per `docs/SYSTEM_SPECIFICATION.md` §2).
2. Phone is the **trust anchor**: every active user has a verified phone number before they can log in.
3. Password is the daily login factor; OTP is a one-time gatekeeper at registration and the primary password-reset path.
4. Email is **optional and verified-when-set**; verified email enables a second password-reset path.
5. The auth surface is provider-agnostic at the controller layer — SMS and Mail go through interfaces with swap-via-env drivers.
6. Every tunable (OTP TTL, code length, throttle thresholds) lives in `platform_settings`, not in code.

## 2. Non-Goals

- Multi-factor authentication on every login (only at registration).
- Social login (Google/Apple/Facebook). Defer indefinitely; not relevant to Libyan market per spec.
- Magic-link passwordless login. Out of scope for MVP.
- Phone-number change flow. Schema-ready, controller deferred to v2.
- Admin self-registration. Admins are seeded or promoted by other admins.
- Email-as-primary-identifier. Phone is the unique identifier; email is supplementary.

## 3. Locked Decisions (from brainstorm 2026-05-05)

| # | Question | Decision |
|---|---|---|
| 1 | Verification gating | **Strict.** Login is rejected with `phone_not_verified` until OTP-verify succeeds. No session token issued at registration. |
| 2 | SMS delivery | **Abstraction + swap-via-env drivers.** `LogSmsDriver` (dev), `FakeSmsDriver` (test, auto-bound), real provider driver added when selected. |
| 3 | Email reset path | **Build for MVP.** Gated on `users.email_verified_at IS NOT NULL`. Email column saves immediately on profile update; verification flag is what unlocks email-based reset. |
| 4 | OTP specs | 6-digit numeric, 5 min TTL, max 5 attempts per code, max 3 requests / 15 min per phone, max 10 verify attempts / 15 min per phone, Redis-cached, namespaced by purpose. |
| 5 | Failed-login throttling | Per-phone: 5/15min → 15min cooldown. Per-IP: 20/15min → 1h cooldown. Soft cooldowns auto-resolve. No hard lockout. |

## 4. Public API Surface

All endpoints under `/api/auth/...` unless noted. JSON in/out. Sanctum bearer tokens for authenticated endpoints. All inputs validated by FormRequests; all outputs shaped by JsonResources.

### 4.1 Registration

```
POST /api/auth/register
Body: {
  phone_number: string  (E.164, required, unique)
  first_name:   string  (required, 1..50)
  last_name:    string? (optional, 1..50)
  password:     string  (required, min 8)
  locale:       string? (optional, 2..5, default 'ar')
}
Response 201: {
  user: { id (public_id), first_name, last_name, phone_number, phone_verified: false, locale }
  message: "OTP sent to your phone. Verify to activate your account."
}
```

Side effects:
1. Creates `users` row with `password = Hash::make(...)`, `phone_verified_at = null`, `account_status = 'active'`.
2. Assigns Spatie role `user`.
3. Issues a 6-digit OTP, stores it in Redis under key `otp:registration:{phone}` with 5min TTL, attempt counter `0`.
4. Calls `SmsService::send($phone, $message)`.
5. **Does not return a Sanctum token.** Login is the only token-issuing endpoint.

### 4.2 OTP Request (resend)

```
POST /api/auth/otp/request
Body: {
  phone_number: string (required, E.164)
  purpose:      enum   (required: 'registration' | 'password_reset')
}
Response 200: { message: "OTP sent." }
```

- Throttled per phone: 3 requests / 15min window. Returns `429 too_many_attempts` with `Retry-After` header on threshold.
- For `registration`: only valid if a user exists with that phone AND `phone_verified_at IS NULL`. Otherwise `409 already_verified`.
- For `password_reset`: only valid if a user exists with that phone. Always returns `200` regardless of existence (anti-enumeration), but only sends SMS when user exists.
- Generates a fresh code; previous code (if any) is overwritten.

### 4.3 OTP Verify

```
POST /api/auth/otp/verify
Body: {
  phone_number: string (required, E.164)
  code:         string (required, exactly 6 digits)
  purpose:      enum   (required: 'registration' | 'password_reset')
}
Response 200 (registration): { message: "Phone verified. You may now log in." }
Response 200 (password_reset): { reset_token: <opaque, 10min TTL> }
```

- Reads from `otp:{purpose}:{phone}` cache key. Each call increments the attempt counter; the user gets up to 5 attempts per code. After 5 failed attempts (the 6th call rejects without checking) the cache entry is deleted; the user must request a new code via `/otp/request`.
- Per-phone verify-attempt throttle: 10 attempts / 15min across all OTP cycles (independent of the per-code limit — defends against resend-and-retry abuse).
- For `registration`: on success, sets `phone_verified_at = now()` and deletes the cache entry.
- For `password_reset`: on success, issues a short-lived `reset_token` (random 64-char string, single-use) that the client passes to `password/reset/otp`. Stored in Redis under key `password_reset_token:{token}` mapping to `user_id` with 10min TTL. Consumed (deleted from cache) on first use. The OTP itself is discarded.

### 4.4 Login

```
POST /api/auth/login
Body: {
  phone_number: string (required, E.164)
  password:     string (required)
}
Response 200: {
  token: <Sanctum bearer>
  user:  UserResource
}
Failure 401: { error: 'invalid_credentials' }
Failure 403: { error: 'phone_not_verified' }
Failure 429: { error: 'too_many_attempts', retry_after: <seconds> } (also as Retry-After header)
```

- Throttle: per-phone 5 fails/15min → 15min cooldown; per-IP 20 fails/15min → 1h cooldown.
- Always returns generic `invalid_credentials` for "no such user" and "wrong password" (anti-enumeration).
- `phone_not_verified` is only returned when password matches but verification is missing — this leak is acceptable because the user already proved they own the password.
- Successful login zeros the per-phone attempt counter. Per-IP counter persists.

### 4.5 Logout

```
POST /api/auth/logout
Auth: Bearer required
Response 204: (no body)
```

Revokes the current Sanctum token only. Other tokens (other devices) survive.

### 4.6 Me / Current User

```
GET /api/auth/me
Auth: Bearer required
Response 200: { user: UserResource (incl. roles[]) }
```

### 4.7 Password Reset — Forgot

```
POST /api/auth/password/forgot
Body: {
  identifier: string (required) — phone E.164 OR email
  channel:    enum   (required: 'otp' | 'email')
}
Response 200: { message: "If the account exists, instructions were sent." }
```

- `channel = 'otp'`: requires `identifier` to be an E.164 phone. Issues OTP under `otp:password_reset:{phone}` and sends SMS. Subject to OTP request throttling (3/15min/phone).
- `channel = 'email'`: requires `identifier` to be an email AND a user with that email AND `email_verified_at IS NOT NULL`. Issues a signed URL (10min TTL, includes user id + hash + expiry, signed with `APP_KEY`) and sends via Mail. If no verified-email account matches, response is still `200` with the same generic message (anti-enumeration).
- Both paths are anti-enumeration: identical `200` response whether the account exists or not.

### 4.8 Password Reset — OTP track

```
POST /api/auth/password/reset/otp
Body: {
  reset_token:  string (required, from /otp/verify)
  new_password: string (required, min 8)
}
Response 204
```

- Validates reset_token signature + TTL + single-use bit (Redis flag).
- On success: hashes new password, updates `users.password`, revokes ALL existing Sanctum tokens for the user (security best practice — log out of all devices on password change), zeros all per-phone throttle counters.

### 4.9 Password Reset — Email track

```
POST /api/auth/password/reset/email
Body: {
  signed_token: string (required, extracted from email link)
  new_password: string (required, min 8)
}
Response 204
```

- Validates signed URL token freshness, signature, and that the user's `email_verified_at IS NOT NULL` (defense-in-depth — if the email is somehow unverified at reset time, reject).
- Same post-success behavior as OTP track: revoke all tokens, zero throttle counters.
- Per-IP throttle: 5 failed token uses / 15min → 1h cooldown.

### 4.10 Email Verification

```
GET /api/auth/email/verify/{id}/{hash}?expires={ts}&signature={sig}
Auth: none — signed URL is the credential
Response 200: { message: "Email verified." }
```

- Standard Laravel signed URL pattern. Verifies signature, expiry (24h TTL), and that hash matches `sha1($user->email)`.
- On success: sets `email_verified_at = now()`. Idempotent — re-clicking a valid link after verification just returns success.
- If the user's email has been changed since the link was issued, the hash won't match and the link is rejected.

```
POST /api/auth/email/verify-resend
Auth: Bearer required
Response 200: { message: "Verification link sent." }
```

- Resends to the currently-attached email if `email_verified_at IS NULL`.
- Throttled per user: 3 requests / 15min.

### 4.11 Profile (separate namespace, but coupled to email verification)

```
GET   /api/me/profile        Auth required, returns UserResource
PATCH /api/me/profile        Auth required, accepts: first_name?, last_name?, locale?, email?
```

- Updating `email` to a new value (different from current):
  1. Sets `users.email = <new>` and `users.email_verified_at = null` in one transaction.
  2. Triggers a fresh email verification link to the new address.
- Updating any other field has no email side effects.
- Email uniqueness is enforced at the DB level (`unique` constraint already on column). Setting an email someone else owns returns `422`.

## 5. Component Architecture

```
app/Http/Controllers/Api/Auth/
  RegisterController.php           single action: __invoke
  OtpController.php                two actions: request, verify
  LoginController.php              single action: __invoke
  LogoutController.php             single action: __invoke
  MeController.php                 single action: __invoke
  PasswordController.php           three actions: forgot, resetViaOtp, resetViaEmail
  EmailVerificationController.php  two actions: verify (signed URL), resend

app/Http/Controllers/Api/Profile/
  ProfileController.php            two actions: show, update

app/Http/Requests/Auth/
  RegisterRequest.php
  RequestOtpRequest.php
  VerifyOtpRequest.php
  LoginRequest.php
  ForgotPasswordRequest.php
  ResetPasswordViaOtpRequest.php
  ResetPasswordViaEmailRequest.php
  ResendEmailVerificationRequest.php

app/Http/Requests/Profile/
  UpdateProfileRequest.php

app/Http/Resources/
  UserResource.php                 hides password, exposes public_id as id, includes roles[]

app/Services/Auth/
  RegistrationService.php          create user + assign role + send registration OTP
  LoginService.php                 verify credentials + issue Sanctum token + reset throttle
  PasswordResetService.php         forgot/reset orchestration for both tracks
  EmailVerificationService.php     send + verify email links

app/Services/Otp/
  OtpService.php                   generate, send, verify codes; namespaced by purpose
  OtpPurpose.php (enum)            'registration' | 'password_reset'  (extensible)

app/Services/Sms/
  SmsService.php (interface)       send(string $phone, string $message): void
  Drivers/LogSmsDriver.php
  Drivers/FakeSmsDriver.php
  SmsServiceProvider.php           binds interface to driver based on config('services.sms.driver')

app/Services/Mail/
  (uses Laravel built-in Mail facade with MAIL_MAILER=log in dev, array in test)

app/Notifications/Auth/             (Laravel notifications used for email only)
  EmailVerificationNotification.php
  PasswordResetEmailNotification.php

app/Enums/
  OtpPurpose.php                   already proposed above
  AuthErrorCode.php                'invalid_credentials' | 'phone_not_verified' | 'too_many_attempts' | 'otp_invalid' | 'otp_expired' | 'reset_token_invalid'
```

### Why services not controllers

Per CLAUDE.md "Services receive dependencies via constructor injection" and "Controllers handle HTTP, services handle logic." Each controller action is one of:

```php
public function __invoke(RegisterRequest $request, RegistrationService $service): JsonResponse
{
    $user = $service->register($request->validated());
    return (new UserResource($user))->response()->setStatusCode(201);
}
```

Controllers are 5–10 lines. All branching, transaction wrapping, and side-effect orchestration lives in services. Services are unit-tested directly; controllers are feature-tested via `actingAs()` + JSON assertions.

## 6. Data Model

**No new tables.** All necessary columns exist from Group 1:

- `users.password` (nullable on the column for future passwordless support, but required-at-registration in the FormRequest)
- `users.phone_verified_at`, `users.email_verified_at`
- `users.account_status` (already an enum cast)
- `personal_access_tokens` (Sanctum)
- Spatie permission tables

**New rows in `platform_settings`** (added via a new seeder `AuthSettingsSeeder`):

```
otp_code_length              integer  6
otp_ttl_seconds              integer  300
otp_max_attempts_per_code    integer  5
otp_max_requests_per_window  integer  3
otp_request_window_seconds   integer  900
otp_max_verify_per_window    integer  10
otp_verify_window_seconds    integer  900

login_max_attempts_per_phone     integer  5
login_attempts_window_seconds    integer  900
login_lockout_seconds_per_phone  integer  900
login_max_attempts_per_ip        integer  20
login_attempts_window_seconds_ip integer  900
login_lockout_seconds_per_ip     integer  3600

password_min_length              integer  8
email_verification_ttl_hours     integer  24
password_reset_token_ttl_seconds integer  600
```

The existing `PlatformSetting::get($key)` cache-aware accessor reads these.

## 7. SMS Abstraction Detail

```php
namespace App\Services\Sms;

interface SmsService
{
    public function send(string $phone, string $message): void;
}
```

```php
final class LogSmsDriver implements SmsService
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function send(string $phone, string $message): void
    {
        $this->logger->info('SMS_OUT', ['phone' => $phone, 'message' => $message]);
    }
}
```

```php
final class FakeSmsDriver implements SmsService
{
    /** @var array<int, array{phone: string, message: string}> */
    public array $sent = [];

    public function send(string $phone, string $message): void
    {
        $this->sent[] = ['phone' => $phone, 'message' => $message];
    }

    public function assertSentTo(string $phone): void { /* ... */ }
    public function lastCodeFor(string $phone): ?string { /* extracts 6-digit code from message */ }
}
```

`SmsServiceProvider` binds:
- `config('services.sms.driver') === 'log'` (default in `.env.example`) → `LogSmsDriver`
- `config('services.sms.driver') === 'fake'` (set by Pest base test case) → `FakeSmsDriver` as singleton (so tests can assert against the same instance the service uses)
- `config('services.sms.driver') === 'plutu'` (future) → `PlutuSmsDriver` with credentials from env

## 8. Mail / Email Verification Detail

- Use Laravel's built-in `URL::temporarySignedRoute('email.verify', $expiresAt, ['id' => $user->id, 'hash' => sha1($user->email)])`.
- Verification link route applies `signed` middleware. Controller verifies hash matches current email (defends against email-change race).
- Mail driver: `MAIL_MAILER=log` in dev (writes the rendered email to `storage/logs/laravel.log`), `MAIL_MAILER=array` in tests (assertable via `Mail::fake()`), real SMTP/API mailer at production-prep time.
- Two notification classes:
  - `EmailVerificationNotification` — sent when email is added or changed
  - `PasswordResetEmailNotification` — sent on `password/forgot` with `channel=email`

## 9. Throttling Detail

Use Laravel's `Illuminate\Cache\RateLimiter` with named limiters defined in `RouteServiceProvider::configureRateLimiting()`:

```php
RateLimiter::for('login', fn (Request $r) => [
    Limit::perMinutes(15, $maxPerPhone)->by('login_phone:' . $r->input('phone_number'))
        ->response(fn () => $this->lockoutResponse(/* uses platform_settings */)),
    Limit::perMinutes(15, $maxPerIp)->by('login_ip:' . $r->ip()),
]);

RateLimiter::for('otp_request',  /* 3 per 15min per phone */);
RateLimiter::for('otp_verify',   /* 10 per 15min per phone */);
RateLimiter::for('password_reset_email', /* 5 per 15min per IP */);
```

Limit values read from `platform_settings` at boot via the cache-aware getter. A `php artisan config:clear` is enough to pick up changes if rates are tweaked.

Routes apply via middleware: `Route::post('login', LoginController::class)->middleware('throttle:login')`.

## 10. OTP Service Design

```php
final class OtpService
{
    public function __construct(
        private readonly SmsService $sms,
        private readonly Repository $cache,    // Redis store
        private readonly PlatformSettings $settings,
    ) {}

    public function issue(string $phone, OtpPurpose $purpose): void
    {
        $code = $this->generateCode();
        $this->cache->put(
            $this->key($phone, $purpose),
            ['code' => Hash::make($code), 'attempts' => 0],
            $this->settings->otpTtl()
        );
        $this->sms->send($phone, $this->formatMessage($code, $purpose));
    }

    public function verify(string $phone, string $candidate, OtpPurpose $purpose): bool
    {
        $key = $this->key($phone, $purpose);
        $entry = $this->cache->get($key);
        if ($entry === null) return false;

        // Attempts already at the cap means this code has been exhausted.
        // (The increment on each call means the 6th call sees attempts=5
        //  with max=5 and bails here without revealing the code.)
        if ($entry['attempts'] >= $this->settings->otpMaxAttemptsPerCode()) {
            $this->cache->forget($key);
            return false;
        }

        // Increment first so a hash-comparison crash still counts the attempt.
        $this->cache->put($key, [
            'code' => $entry['code'],
            'attempts' => $entry['attempts'] + 1,
        ], $this->ttlRemaining($key));

        if (! Hash::check($candidate, $entry['code'])) return false;

        // Success — burn the code so it can't be replayed.
        $this->cache->forget($key);
        return true;
    }

    private function key(string $phone, OtpPurpose $purpose): string
    {
        return "otp:{$purpose->value}:{$phone}";
    }
}
```

OTP codes are stored hashed (Laravel's `Hash::make` / `Hash::check`). Even if an attacker dumps Redis, they don't get plaintext codes.

## 11. Error Handling

Standard JSON error envelope:

```json
{
  "error": "invalid_credentials",
  "message": "Phone number or password is incorrect.",
  "details": {}
}
```

Error codes (PHP enum `AuthErrorCode`):
- `invalid_credentials` (401)
- `phone_not_verified` (403)
- `too_many_attempts` (429, includes `retry_after` integer)
- `otp_invalid` (422)
- `otp_expired` (422)
- `reset_token_invalid` (422)
- `email_not_verified` (422 — only on email-channel reset attempts when the email isn't verified)
- `validation_failed` (422 — standard Laravel validation, includes `errors` map)

Localizable messages: keys live in `lang/en/auth.php` and `lang/ar/auth.php`. Locale resolved from `Accept-Language` header or authenticated user's `locale` column.

## 12. Testing Strategy

- **Pest feature tests** for every endpoint, covering: happy path, every documented error code, throttle thresholds, anti-enumeration assertions (response shape identical for existing/non-existing accounts).
- **Pest unit tests** for `OtpService`, `RegistrationService`, `LoginService`, `PasswordResetService`, `EmailVerificationService`. These hit Redis directly (via `RefreshDatabase` + `cache->flush()` in `beforeEach`).
- `FakeSmsDriver` and `Mail::fake()` swapped in via the base test case so no real SMS or email goes out in CI.
- A Pest dataset of edge-case phone numbers (E.164 vs invalid, leading zeros, non-Libyan prefixes for future) drives validation tests.
- `RefreshDatabase` resets the DB between tests; cache flushed in `beforeEach`.

## 13. Security Notes

1. **Anti-enumeration:** identical responses for "no such phone/email" and "exists but other failure" on register / forgot endpoints.
2. **All tokens revoked on password reset** — protects against the "attacker stole my session, I reset my password" gap.
3. **OTP codes hashed at rest in Redis** — even Redis dump leak doesn't reveal codes.
4. **Signed URL on email verify** — no token table needed, no DB lookup, signature is the credential, expiry is intrinsic.
5. **Per-phone AND per-IP throttles** on login — defends against both targeted and stuffing attacks.
6. **Password hashing** — Laravel default `bcrypt` (`Hash::make`).
7. **Password minimum length** — 8 chars (configurable). No complexity rules — research consistently shows length > complexity for real-world security.
8. **Rate-limited OTP resend** — prevents using your service as an SMS-bomb tool.

## 14. Open Items / Deferred

- Real SMS provider — pick at production-prep, drop in a `PlutuSmsDriver` (or whichever).
- Real Mail provider — same story; Laravel built-in mail with SMTP/API config.
- Phone-number change flow — schema-ready, controller+service deferred.
- 2FA on login (TOTP, etc.) — not in scope; phone+password is enough for MVP given the verified-phone trust anchor.
- Admin "force-suspend account" UI — admins can already update `account_status` via DB; admin panel feature comes later.
- Account deletion / GDPR-ish self-service — deferred.

## 15. Implementation Order

The implementation plan (next document, produced via `writing-plans` skill) will sequence these tasks. Approximate order:

1. SMS abstraction (interface + log + fake drivers + service provider + config)
2. `OtpService` + `OtpPurpose` enum + Pest unit tests
3. `AuthSettingsSeeder` adds the new `platform_settings` rows
4. Throttle definitions in `RouteServiceProvider`
5. `RegistrationService` + `RegisterController` + `RegisterRequest` + `UserResource` + Pest feature test
6. `OtpController` (request + verify) + FormRequests + Pest feature test
7. `LoginService` + `LoginController` + `LoginRequest` + Pest feature test (incl. throttle assertions)
8. `LogoutController` + `MeController` + Pest feature tests
9. Email-side: `EmailVerificationService` + `EmailVerificationController` + notification class + Pest feature test
10. Profile: `ProfileController` + `UpdateProfileRequest` + email-change side-effect test
11. Password reset: `PasswordResetService` + `PasswordController` (forgot + reset/otp + reset/email) + notification class + Pest feature tests for both tracks

Each step is a vertical slice — DB, service, controller, FormRequest, resource, test — landing one user-visible capability at a time.

---

**End of design spec.**
