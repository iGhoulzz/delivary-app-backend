# CLAUDE.md — Delivery & Logistics Platform (Libya)

> Auto-loaded by Claude Code. Full narrative: `docs/SYSTEM_SPECIFICATION.md`.

---

## Project TL;DR

**What:** Hybrid logistics + marketplace platform for Libya. Laravel + PostgreSQL + PostGIS.

**Core flows:**
- **Standard delivery** — sender pays
- **P2P sale** — seller→buyer, cash-on-delivery, platform takes commission
- **Merchant delivery** — shop→customer, similar to P2P

**Stack:** Laravel · PostgreSQL + PostGIS · Redis · Laravel Reverb · Spatie Permission · Spatie Media · Bavix Wallet · Sanctum

**Currency:** LYD only. `decimal(12, 2)`. **Market:** Libya — phone-first, Arabic-primary, cash-dominated.

---

## 🚨 Critical Rules (Non-Negotiable)

### Financial
1. **Never recalculate stored financials** — commission rates/amounts are snapshotted at order creation. Immutable.
2. **Never pay out money the platform doesn't have** — sellers withdraw only after driver settles cash AND 48h clearance passes.
3. **Never update balances without locks** — always `DB::transaction()` + `lockForUpdate()`. Race conditions = lost money.
4. **Never mix driver buckets** — `cash_to_deposit`, `earnings_balance`, `debt_balance` are separate with distinct lifecycles.
5. **Never store negative balances** — debt goes to `debt_balance` (positive number = amount owed), not a negative `earnings_balance`.
6. **Always log every balance change** — every mutation creates a row in `wallet_transactions` or `driver_account_transactions`.

### Orders & State
7. **Atomic status transitions only** — `UPDATE ... WHERE status = currentStatus`. Prevents two drivers claiming the same order.
8. **Always append to `order_status_logs`** — every status change is logged. Powers dispute resolution.
9. **Never mark delivered without code verification** — pickup + delivery codes are mandatory. Geofence is fallback only.
10. **Drivers cannot self-cancel mid-trip** — must call support after `assigned`. No cancel button in app.

### Identity & Privacy
11. **Never expose internal `id` in URLs or API responses** — use `public_id` (ULID). Internal IDs leak business data.
12. **Never expose raw phone numbers** — mask in API responses (v2, but design for it now).
13. **No driver self-registration** — face-to-face office onboarding only.

### Geographic
14. **Always use PostGIS `geography` type** (not `geometry`) for spherical accuracy.
15. **Always use SRID 4326** (WGS84).
16. **Validate orders are within active service area** before creation.

### Code Quality
17. **No hardcoded rates/fees/timeouts** — everything lives in `platform_settings` table or config files.
18. **PHP 8.1+ enums for all fixed value sets** — never magic strings.
19. **DB transactions for all multi-step writes** that must succeed atomically.
20. **Never migrate to retroactively change financial data** — historical records are sacred.

---

## Code Style

### Type Safety
- `declare(strict_types=1);` on every PHP file
- Type-hint all parameters and return types (`void` included)
- Property type declarations on every class; `readonly` where immutable
- Constructor property promotion for DI
- `final` by default on classes; favor composition over inheritance

### Architecture
- **Controllers handle HTTP. Services handle logic.** Controller = validate (FormRequest) → call service → return Resource. Nothing else.
- Services get dependencies via constructor injection — no `app()`, `resolve()`, or facades inside services
- One service per concern: `OrderCreationService` ≠ `OrderAssignmentService` ≠ `OrderStatusService`
- No static methods except pure utilities and enum factories
- Value Objects for domain concepts with invariants → `app/ValueObjects/` (Money, Coordinates, OrderCode)

### Validation & I/O
- `FormRequest` per endpoint — never inline validation in controllers
- `JsonResource` for every API response — never return raw models. Expose `public_id` as `id`, hide internal `id`
- DTOs / readonly classes for service inputs with >2 args
- Authorization via Policies, not inline `$user->can(...)` in controllers

### Eloquent & Database
- Eager-load relationships — never iterate a collection accessing relations without `with()` / `load()`
- Query Scopes for reusable `where()` chains
- Modern `Attribute` accessor syntax (PHP 8.1+), not `getXxxAttribute`
- Casts for enums, dates, JSON, geometries, money — never parse manually
- Raw SQL allowed for PostGIS/complex aggregates, but always parameterized, wrapped in scopes
- `down()` must actually undo `up()` — reversible migrations only

### Naming
- PSR-12 + Laravel Pint (`vendor/bin/pint` before every commit)
- Methods are verbs: `assignDriver()`. Properties are nouns. Booleans read as questions: `isActive()`, `canAcceptOrders()`
- No abbreviations: `customer` not `cust`, `address` not `addr`

### Testing
- Pest framework: `it('...', fn () => ...)` style
- Services get unit tests. Controllers get feature tests via `actingAs()` + JSON assertions
- `RefreshDatabase` or `DatabaseTransactions` in all tests
- Test behavior, not trivial getters/setters

### Anti-Patterns — Reject These
- ❌ Logic in controllers → move to service
- ❌ `Model::all()` in production → always paginate or scope
- ❌ Mass assignment without `$fillable` / `#[Fillable]`
- ❌ Magic strings → use enums
- ❌ Swallowing exceptions with `catch { return null; }` → at minimum log, ideally let propagate
- ❌ `optional($x)->y()` → use `$x?->y()` (PHP 8+)

---

## Key Conventions

### Public IDs
All user-facing tables use auto-increment internal `id` (for FK joins) + ULID `public_id` (for URLs/APIs).

```php
// Migration
$table->id();
$table->ulid('public_id')->unique();

// Model boot
static::creating(fn($m) => $m->public_id ??= (string) Str::ulid());

public function getRouteKeyName(): string { return 'public_id'; }

// Resource — always expose public_id as 'id', never expose $this->id
'id' => $this->public_id,
```

### Money Fields
Always `decimal(12, 2)`. Use `bcmath` for calculations — never floats.

### PostGIS Columns
Always `geography` type, SRID 4326:
```php
DB::statement('ALTER TABLE orders ADD COLUMN pickup_location geography(POINT, 4326) NOT NULL');
DB::statement('CREATE INDEX orders_pickup_location_idx ON orders USING GIST (pickup_location)');
```

### DB Naming
| Element | Convention | Example |
|---|---|---|
| Tables | snake_case, plural | `driver_profiles` |
| Pivots | alphabetical singular | `driver_region` |
| Foreign keys | `{singular}_id` | `user_id` |
| Booleans | `is_*` / `has_*` | `is_active` |
| Timestamps | `*_at` | `delivered_at` |
| Money | `*_amount` or descriptive | `commission_amount` |
| Coordinates | `*_location` | `pickup_location` |

---

## File Structure

```
app/
├── Enums/               # All PHP enums
├── Http/
│   ├── Controllers/Api/{Auth,Driver,User,Tracking}/
│   ├── Controllers/{Admin,Office}/
│   ├── Requests/        # FormRequests
│   └── Resources/       # JsonResources
├── Models/
├── Policies/
├── Services/{Order,Driver,Financial,Geographic,Notification}/
├── Support/             # Generic helpers/traits
└── ValueObjects/        # Immutable value objects

database/
├── factories/
├── migrations/
└── seeders/
```

---

## Key Code Patterns

### Atomic Order Claim
```php
$claimed = Order::where('id', $order->id)
    ->where('status', OrderStatus::AwaitingDriver)
    ->update(['driver_id' => $driver->id, 'status' => OrderStatus::Assigned, 'assigned_at' => now()]);

// $claimed === 1 means success; 0 means another driver got it first
```

### Driver Bucket Update (always locked)
```php
DB::transaction(function () use ($account, $amount, $bucket, $reason, $order) {
    $account = DriverAccount::where('driver_id', $id)->lockForUpdate()->firstOrFail();
    $account->{$bucket} += $amount;
    $account->save();

    DriverAccountTransaction::create([
        'driver_id' => $account->driver_id,
        'bucket'    => $bucket,
        'amount'    => $amount, // positive = credit, negative = debit
        'reason'    => $reason,
        'balance_after' => $account->{$bucket},
    ]);
});
```

### Order Status Transition
```php
DB::transaction(function () use ($order, $newStatus, $actor) {
    if (!in_array($newStatus, $order->status->allowedTransitions())) {
        throw new InvalidOrderTransitionException();
    }
    $order->update(['status' => $newStatus, 'status_changed_at' => now()]);
    OrderStatusLog::create(['order_id' => $order->id, 'from_status' => $oldStatus, 'to_status' => $newStatus, 'actor_id' => $actor->id]);
    event(new OrderStatusChanged($order, $oldStatus, $newStatus));
});
```

### Enum (Template)
```php
enum OrderStatus: string
{
    case AwaitingDriver = 'awaiting_driver';
    case Assigned       = 'assigned';
    // ... 14 more cases

    public function isTerminal(): bool { ... }

    public function allowedTransitions(): array
    {
        return match($this) {
            self::AwaitingDriver => [self::Assigned, self::NoDriverAvailable, self::CancelledByUser],
            // ...
            default => [],
        };
    }
}
```

### PostGIS Nearby Query
```php
Driver::join('driver_profiles', ...)
    ->whereRaw('ST_DWithin(current_location, ST_MakePoint(?, ?)::geography, ?)', [$lng, $lat, $radius])
    ->orderByRaw('ST_Distance(current_location, ST_MakePoint(?, ?)::geography)', [$lng, $lat])
    ->limit(20)->get();
```

---

## Glossary

| Term | Meaning |
|---|---|
| `cash_to_deposit` | Driver bucket: cash from buyers, owed to platform |
| `earnings_balance` | Driver bucket: delivery fees owed by platform to driver |
| `debt_balance` | Driver bucket: money driver owes platform |
| Settlement | Driver hands cash to office, balances are reconciled |
| Snapshot | Rates stored on the order at creation — never recalculated |
| Atomic Claim | Race-safe order acceptance via conditional `UPDATE WHERE status = ?` |
| Public ID | ULID exposed in URLs/APIs — never expose internal auto-increment `id` |
| Type 1 Receiver | Registered app user |
| Type 2 Receiver | Guest — no account, gets SMS + web tracking link |
| Tracking Token | ULID in public URLs for guest tracking |
| Strike | Driver penalty (3 in 30 days → admin review) |

---

## Rejected Decisions (Do Not Propose These)

| Idea | Why Rejected |
|---|---|
| Single driver balance | 3 buckets have distinct lifecycles — one number loses that info |
| Runtime commission recalculation | Rate changes would alter historical order records |
| UUIDs for all PKs | Index fragmentation in PostgreSQL; use int PK + ULID public_id |
| Auto-increment IDs in URLs | Leaks order volume, enables enumeration attacks |
| Driver self-cancel mid-trip | Fraud vector; support must handle it |
| Skip `order_status_logs` | Disputes require audit trail — table is non-negotiable |
| Force receiver registration | Kills most delivery use cases; guest model is required |
| Floats for money | `0.1 + 0.2 ≠ 0.3` — use `decimal` + `bcmath` |
| Partial settlement records on disagreement | Half-committed financial state is confusing; no record = come back fresh |
| Bank transfers for payouts (MVP) | Cash-at-office only for MVP; wallet-ready architecture for v2 |

---

## Packages

| Package | Version | Used For |
|---|---|---|
| `spatie/laravel-permission` | latest | Roles & permissions |
| `spatie/laravel-medialibrary` | latest | File uploads |
| `bavix/laravel-wallet` | ^12.0 | **User wallets only** — not drivers |
| `laravel/sanctum` | latest | API auth |
| `laravel/reverb` | latest | WebSockets |
| `clickbar/laravel-magellan` | ^2.1 | PostGIS (chosen over mstaack for Laravel 13 compat) |

**Bavix** is for users only. Drivers use a custom 3-bucket `driver_accounts` implementation.

---

## Current Project State

**Last updated:** 2026-05-10
**Status:** Schema phase (1–9) ✅ done. **Auth milestone ✅ done. Driver onboarding ✅ done.** Moving to orders milestone.

| Group | Tables | Status |
|---|---|---|
| 1. Foundation | `users`, `platform_settings`, Spatie/Sanctum/Bavix tables | ✅ |
| 2. Geographic | `service_areas`, `regions`, `office_locations`, `office_staff_assignments` | ✅ |
| 3. Profiles | `driver_profiles`, `driver_documents`, `merchant_profiles`, `driver_region` | ✅ |
| 4. Receivers | `guest_recipients` | ✅ |
| 5. Driver Ops | `driver_accounts`, `driver_account_transactions`, `driver_locations`, `driver_strikes` | ✅ |
| 6. Wallets | Bavix tables (users only) | ✅ |
| 7. Orders Core | `orders`, `order_status_logs` | ✅ |
| 8. Operations | `settlements`, `settlement_orders`, `seller_payouts`, `office_inventory` | ✅ |
| 9. Future-Ready | `payment_methods`, `topup_requests` | ✅ |

**Cross-cutting:** `declare(strict_types=1)`, `final` classes, full type hints, query scopes, modern accessors, bcmath for money, payout = cash-at-office only (no bank transfers), Pint enforced (PSR-12 + Laravel preset).

### Auth milestone (2026-05-07)

| Endpoint | Method | Throttle | Auth |
|---|---|---|---|
| `/api/auth/register` | POST | — | public |
| `/api/auth/login` | POST | `throttle:login` (5/15min phone, 20/15min IP) | public |
| `/api/auth/logout` | POST | — | sanctum |
| `/api/auth/me` | GET | — | sanctum |
| `/api/auth/otp/request` | POST | `throttle:otp_request` (3/15min phone) | public |
| `/api/auth/otp/verify` | POST | `throttle:otp_verify` (10/15min phone) | public |
| `/api/auth/email/verify/{id}/{hash}` | GET | `signed` middleware | public |
| `/api/auth/email/verify-resend` | POST | — | sanctum |
| `/api/auth/password/forgot` | POST | `throttle:forgot_password` (3/15min identifier) | public |
| `/api/auth/password/reset/otp` | POST | — | public |
| `/api/auth/password/reset/email` | POST | `throttle:password_reset_email` (5/15min IP) | public |
| `/api/me/profile` | GET / PATCH | — | sanctum |

**Locked decisions:** strict phone-verification gate (login blocked until OTP-verified), OTP=6 digits/5min/5 attempts/Redis-cached/hashed, dual-track password reset (OTP-to-phone primary, email link if `email_verified_at IS NOT NULL`), all Sanctum tokens revoked on password reset, anti-enumeration on every public lookup endpoint.

**SMS abstraction:** `App\Services\Sms\SmsService` interface + `LogSmsDriver` (dev) + `FakeSmsDriver` (test). `SMS_DRIVER` env var selects driver. Real provider (likely Plutu) deferred until production-prep.

**Mail:** `MAIL_MAILER=log` in dev. `EmailVerificationNotification` and `PasswordResetEmailNotification` use Laravel signed URLs — no DB-tracked tokens. Real SMTP provider deferred.

**Key gotcha discovered:** Laravel's named-limiter middleware stores counters under `md5($limiterName . $rawKey)`. To clear from a controller (e.g. on successful login), call `RateLimiter::clear(md5('login' . $rawKey))` — the raw key won't match.

**Localization:** `lang/en/auth_messages.php` + `lang/ar/auth_messages.php` scaffolded. Controllers still emit hardcoded strings; wiring `__()` calls is a future pass.

### Driver onboarding milestone (2026-05-10)

| Endpoint | Method | Auth |
|---|---|---|
| `/api/me/driver` | GET | sanctum |
| `/api/me/driver/preregister` | POST | sanctum, phone-verified |
| `/api/office/drivers` | GET | sanctum + role:office_staff |
| `/api/office/drivers/lookup` | POST | sanctum + role:office_staff |
| `/api/office/drivers/onboard` | POST | sanctum + role:office_staff |
| `/api/office/drivers/{id}/verify-phone` | POST | sanctum + role:office_staff |
| `/api/office/drivers/{id}/documents` | POST/DELETE | sanctum + role:office_staff |
| `/api/office/drivers/{id}/submit` | POST | sanctum + role:office_staff |
| `/api/admin/drivers` | GET | sanctum + role:admin |
| `/api/admin/drivers/{id}` | GET | sanctum + role:admin |
| `/api/admin/drivers/{id}/{approve,reject,suspend,reinstate}` | POST | sanctum + role:admin |
| `/api/driver/{profile,account}` | GET | sanctum + role:driver |
| `/api/driver/regions` | GET / PATCH | sanctum + role:driver |

**Locked decisions:** unified flow handles existing-pre-registered + existing-no-profile + cold-walk-in via single `/onboard` endpoint (cold walk-in triggers in-office OTP). Document upload is staff-only (face-to-face is the trust model). Admin-only approval (atomic side-effects: state→active, driver_account auto-created with max_cash_liability=100, `driver` Spatie role assigned, all docs marked verified). Driver picks regions post-approval (empty selection = all regions in their office's service area). One office per driver.

**Spatie media linkage by convention:** `driver_documents` table holds metadata (UNIQUE(driver_id, document_type)). Files live in Spatie Media against User with collection_name = `driver_document_{type}` (single-file collection per type). Mime allowlist enforced at FormRequest layer only — Spatie's content-sniffing was rejecting test uploads.

**Spatie role middleware aliases** registered in `bootstrap/app.php` (`role`, `permission`, `role_or_permission`).

**Bug fix during build:** `phone_verified_at` and `email_verified_at` weren't in User's `$fillable` — mass assignment silently dropped them. Added to fillable.

### Next Steps (in order)
1. **Order lifecycle** — creation, atomic claim, state machine, code verification, settlement
2. **Office staff operations** — settlement processing, seller payout handover
3. **Real-time** — Reverb channels for driver location + order status
4. **Staff CRUD** — admin creates/manages internal accounts (deferred from driver onboarding milestone)
5. **Account moderation** — global ban/suspend with reason history (deferred from driver onboarding milestone)
6. **Test infrastructure** — promote tinker smoke tests to Pest feature tests against a separate test DB

### Open Questions
- Order creation UX / form field specifics
- Admin panel scope
- Rating & review system
- SMS provider
- Cancellation fee amounts

---

## Agent Instructions

**When conflicting with a rule:** Refuse, cite the rule, suggest the correct alternative.

**On edge cases:** Apply closest rule conservatively → flag it → suggest updating this file.

**When adding code:** Ask "Are there existing patterns for [task]?" before writing new implementations.

**On schema changes:** Never retroactively alter financial data. Add new columns forward only.

Update this file when architectural decisions are locked. Keep it accurate and lean.
