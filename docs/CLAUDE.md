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
9. **Never mark delivered without code verification** — pickup + delivery codes are mandatory. Geofence is fallback only. **Exception (incident-response override):** the `codes.enforce_pickup` and `codes.enforce_delivery` `platform_settings` flags can be flipped to `false` to bypass the code requirement system-wide for the duration of an incident (SMS provider outage, encrypted-codes bug, etc.). Bypasses are fully audited via `picked_up_method = bypassed` / `delivered_method = bypassed` on the order row plus `order_status_logs.metadata`. Defaults ON; flipping requires admin DB/Tinker action (no UI in MVP).
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

**Exception — reference/lookup tables:** `regions` and `service_areas` intentionally have **no** `public_id`. They are stable, non-sensitive geographic reference data (not user/business records), so exposing or accepting their numeric `id` is an accepted, deliberate exception to Critical Rule 11. Do not add `public_id` to these tables. (Established during the 2026-05-31 internal-id exposure remediation.)

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

**Last updated:** 2026-06-02
**Status:** Schema phase (1–9) ✅ done. **Auth ✅. Driver onboarding ✅. Order lifecycle A+B ✅. Sub-project C pre-pickup tail ✅ (Slice 10). Sub-project D failed delivery + return-to-office ✅ (Slice 11). Settlement & seller payouts ✅ (milestone 2026-05-17). Staff CRUD ✅ (milestone 2026-05-20, Slices A+B). Internal-ID exposure remediation ✅ (PR #5, merged 2026-06-02). Real-time / Reverb ✅ (milestone 2026-06-02).** Cash loop closed end-to-end; admin staff account management live; real-time push live across order/driver/user channels. Account moderation is next.

| Group | Tables | Status |
|---|---|---|
| 1. Foundation | `users`, `platform_settings`, Spatie/Sanctum/Bavix tables | ✅ |
| 2. Geographic | `service_areas`, `regions`, `office_locations`, `office_staff_assignments` | ✅ |
| 3. Profiles | `driver_profiles`, `driver_documents`, `merchant_profiles`, `driver_region` | ✅ |
| 4. Receivers | `guest_recipients` | ✅ |
| 5. Driver Ops | `driver_accounts`, `driver_account_transactions`, `driver_locations`, `driver_strikes` | ✅ |
| 6. Wallets | Bavix tables (users only) | ✅ |
| 7. Orders Core | `orders`, `order_status_logs` | ✅ |
| 8. Operations | `settlements`, `settlement_orders`, `seller_payouts`, `seller_payout_orders`, `seller_earnings`, `office_inventory` | ✅ |
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

### Order lifecycle milestone (2026-05-12) — A+B + pre-pickup C

| Endpoint | Method | Auth |
|---|---|---|
| `/api/orders/quote` | POST | sanctum |
| `/api/orders` | POST | sanctum, phone-verified |
| `/api/me/orders` | GET | sanctum |
| `/api/me/orders/{public_id}` | GET | sanctum + OrderPolicy::view |
| `/api/me/orders/{public_id}/retry` | POST | sanctum + sender + state-guarded |
| `/api/me/orders/{public_id}/cancel` | POST | sanctum + sender + pre-pickup states only |
| `/api/me/orders/{public_id}/confirm-pickup-geofence` | POST | sanctum + sender + en_route_pickup |
| `/api/track/{tracking_token}` | GET | public |
| `/api/driver/go-online` | POST | sanctum + role:driver |
| `/api/driver/go-offline` | POST | sanctum + role:driver |
| `/api/driver/location` | POST | sanctum + role:driver |
| `/api/driver/orders/broadcast` | GET | sanctum + role:driver |
| `/api/driver/orders/current` | GET | sanctum + role:driver |
| `/api/driver/orders/{public_id}/claim` | POST | sanctum + role:driver |
| `/api/driver/orders/{public_id}/confirm-pickup` | POST | sanctum + role:driver + OrderPolicy::act |
| `/api/driver/orders/{public_id}/arrived-dropoff` | POST | sanctum + role:driver + OrderPolicy::act |
| `/api/driver/orders/{public_id}/confirm-delivery` | POST | sanctum + role:driver + OrderPolicy::act |
| `/api/admin/orders` | GET | sanctum + role:admin |
| `/api/admin/orders/{public_id}` | GET | sanctum + role:admin |
| `/api/admin/orders/{public_id}/assign` | POST | sanctum + role:admin |
| `/api/admin/orders/{public_id}/unassign` | POST | sanctum + role:admin (supports `driver_fault=true` strike path) |
| `/api/admin/orders/{public_id}/cancel` | POST | sanctum + role:admin (pre-pickup states) |

**Locked decisions:** per-region flat base fee with admin-tunable item-size modifiers + per-km surcharge in `platform_settings`; quote-then-create with 5-min signed `quote_token` (HMAC SHA-256, namespaced); polling broadcast (push deferred to Real-time milestone); atomic conditional UPDATE for claim (spec §10.2); hybrid auto + explicit transitions; collapsed `display_status` for sender + receiver, raw `status` for driver + admin; 6-digit pickup/delivery codes encrypted at rest with two `codes.enforce_*` kill-switch flags (see Critical Rule 9 exception); sender retry resets tier 1; admin manual assign/unassign with `force=true` softening for vehicle/region mismatches; tier escalation is silent column update (only the `no_driver_available` flip is logged).

**Architecture:** `StateTransitionService` is the sole writer of `orders.status` for non-atomic flows; `ClaimService` + `AdminAssignmentService` bypass it intentionally to include `driver_id IS NULL` race guards in their atomic UPDATE statements (each writes its own `order_status_logs` rows + `OrderStatusChanged` events). Financial side-effects (driver bucket credit, fee-status flip, auto-debt-offset) live inline in `CodeVerificationService` rather than a hook registry — simpler, fully transactional.

**Sub-project C (Slice 10) decisions:** Pre-pickup user cancel allowed from `awaiting_driver` (free), `no_driver_available` (free), `assigned`/`driver_en_route_pickup` (fee from `cancellation.user_pre_pickup_fee`). Post-pickup user cancel rejects with `ORDER_NOT_CANCELLABLE_FROM_STATE` (depends on return-to-office flow in sub-project D). Admin can cancel from any pre-pickup operational state. Admin unassign supports `driver_fault=true` → creates `driver_strikes` row with `accept_then_cancel` reason + applies fee through `DriverAccountLedgerService` (debits earnings first, remainder to debt_balance, driver flipped offline). Two new platform settings: `cancellation.user_pre_pickup_fee` (default 0.00), `cancellation.driver_accept_then_cancel_fee` (default 0.00).

**Background jobs:** `EscalateBroadcastingOrdersJob` + `AutoOfflineIdleDriversJob`, both scheduled `everyMinute()->withoutOverlapping()` in `routes/console.php`. Escalation does silent column updates for tier-2 (+20%) and tier-3 (+50%) surcharges, then `StateTransitionService` for the timeout to `no_driver_available`. Auto-offline flips idle/GPS-lost online drivers offline (skips mid-trip drivers); presence events go to `driver_presence_logs`.

**Smoke test:** `scripts/orders-e2e.php` — 9 rollback-wrapped scenarios run via `php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"`.

### Failed delivery + return-to-office milestone (2026-05-13)

| Endpoint | Method | Auth |
|---|---|---|
| `/api/driver/orders/{public_id}/mark-delivery-failed` | POST | sanctum + role:driver + OrderPolicy::markDeliveryFailedByDriver |
| `/api/office/orders` | GET | sanctum + role:office_staff |
| `/api/office/orders/{public_id}` | GET | sanctum + role:office_staff + office assignment |
| `/api/office/orders/{public_id}/receive-return` | POST | sanctum + role:office_staff + office assignment |
| `/api/office/orders/{public_id}/retrieve` | POST | sanctum + role:office_staff + office assignment |
| `/api/admin/orders/{public_id}/mark-delivery-failed` | POST | sanctum + role:admin |
| `/api/admin/orders/{public_id}/redirect-return` | POST | sanctum + role:admin |
| `/api/admin/orders/{public_id}/waive-retrieval-fees` | POST | sanctum + role:admin |

**Locked decisions:** driver/admin can mark failed from `picked_up`, `driver_en_route_dropoff`, or `delivery_in_progress`; sender post-pickup cancel stays rejected. Failure auto-chains through `delivery_failed` to `returning_to_office`; office staff confirms physical receipt to move to `at_office`. Driver earnings are credited only at office receipt, except driver/platform-fault failures. Storage fees are just-in-time using `storage.grace_days`, `storage.daily_fee`, and `storage.abandonment_days`.

**Background job:** `AbandonStaleOrdersJob`, scheduled daily as `orders.abandon-stale`.

**Smoke test:** `scripts/orders-e2e.php` now covers 17 rollback-wrapped scenarios.

### Settlement & Seller Payouts milestone (2026-05-17) ✅

| Endpoint | Method | Auth |
|---|---|---|
| `/api/driver/settlements` | GET | sanctum + role:driver |
| `/api/driver/settlements/{public_id}` | GET | sanctum + role:driver + SettlementPolicy::viewByDriver |
| `/api/me/earnings` | GET | sanctum (throttle:seller_earnings_read) |
| `/api/me/seller-payouts` | GET | sanctum |
| `/api/me/seller-payouts/{public_id}` | GET | sanctum + SellerPayoutPolicy::viewBySeller |
| `/api/office/drivers/{driverPublicId}/settlement-preview` | GET | sanctum + role:office_staff + active assignment |
| `/api/office/settlements` | POST | sanctum + role:office_staff + active assignment (throttle:office_settlement) |
| `/api/office/settlements` | GET | sanctum + role:office_staff + active assignment |
| `/api/office/seller-payouts/lookup` | GET | sanctum + role:office_staff + active assignment |
| `/api/office/seller-payouts` | POST | sanctum + role:office_staff + active assignment (throttle:office_payout) |
| `/api/office/seller-payouts` | GET | sanctum + role:office_staff + active assignment |
| `/api/admin/settlements` | GET | sanctum + role:admin |
| `/api/admin/settlements/{public_id}` | GET | sanctum + role:admin |
| `/api/admin/settlements/{public_id}/reverse` | POST | sanctum + role:admin |
| `/api/admin/seller-payouts` | GET | sanctum + role:admin |

**Locked decisions:** all-or-nothing settlement (single atomic POST); excess rejected (must be handed back); disagreement leaves zero DB trace (per spec §11.4); new `seller_earnings` table is 1:1 with sale orders (orders table untouched); 48h clearance window via `payouts.clearance_hours`; any driver/seller at any office, gated by staff's active assignment; admin reversal blocked once any contributing earning leaves `pending_clearance`; identity verification at counter is visual (no payout codes); Bavix wallet NOT used for sellers (earnings computed from SUM); seller_earnings row spawned at delivery time in `CodeVerificationService`, never at order creation.

**Architecture:** Three services own all writes — `SettlementService` (preview + process, sole writer of `settlements` + `settlement_orders` + `driver_account_transactions`), `SellerPayoutService` (lookup + process, sole writer of `seller_payouts` + `seller_payout_orders`), `SettlementReversalService` (admin correcting-settlement pattern, immutable original + reversed twin). All exceptions carry `errorCode(): SettlementErrorCode` mapped to HTTP via `httpStatus()`. `User::isAssignedToOffice(int)` helper extracted from `OrderPolicy` and reused by all settlement-side policies; sellers correctly authorise by FK ownership alone (no Spatie `seller` role exists — documented in `SellerEarningPolicy` + `SellerPayoutPolicy` class docblocks).

**Background job:** `ClearSellerEarningsJob`, scheduled daily as `seller-earnings.clearance`. Flips `pending_clearance → available` once `cleared_at <= now() - payouts.clearance_hours`.

**Schema deltas:** new `seller_earnings` table (1:1 with sale orders); new `seller_payout_orders` pivot; `seller_payouts` had the request/approval-flow columns dropped (`approved_at`, `approved_by_admin_id`, `rejected_at`, `rejected_by_admin_id`, `rejection_reason`, `requested_at`) and `paid_by_admin_id` renamed to `paid_by_staff_id`. Four new platform settings: `payouts.clearance_hours` (48), `payouts.min_amount` (20.00), `payouts.allow_partial` (true), `settlement.reverse_window_hours` (empty/optional cap).

**Bug fixed during build:** `PayoutValidationException` and `SettlementNotReversibleException` used `public readonly SettlementErrorCode $code` constructor params — this shadowed `RuntimeException::$code` and caused fatal at construction. Renamed to `$errorCode` (with matching accessor).

**Smoke test:** `scripts/orders-e2e.php` now covers 31 rollback-wrapped scenarios (17 existing + 14 new for settlement: happy match, empty/excess/shortage/zero-net settlements, sale-order earning flips, clearance cron 48h cutoff, payout happy path + partial + mismatch + below-minimum, reversal happy path + blocked-once-past-clearance).

### Staff CRUD milestone (2026-05-20) ✅ — Slices A + B

| Endpoint | Method | Auth |
|---|---|---|
| `/api/admin/staff` | GET | sanctum + role:admin + StaffPolicy::viewAny |
| `/api/admin/staff` | POST | sanctum + role:admin + StaffPolicy::create |
| `/api/admin/staff/{staff}` | GET | sanctum + role:admin + StaffPolicy::view |
| `/api/admin/staff/{staff}` | PATCH | sanctum + role:admin + StaffPolicy::update |
| `/api/admin/staff/{staff}/suspend` | POST | sanctum + role:admin + StaffPolicy::suspend |
| `/api/admin/staff/{staff}/reinstate` | POST | sanctum + role:admin + StaffPolicy::reinstate |
| `/api/admin/staff/{staff}/deactivate` | POST | sanctum + role:admin + StaffPolicy::deactivate |
| `/api/admin/staff/{staff}/reset-temp-password` | POST | sanctum + role:admin + StaffPolicy::resetTempPassword |
| `/api/admin/staff/{staff}/office-assignments` | POST | sanctum + role:admin + StaffPolicy::manageOfficeAssignments |
| `/api/admin/staff/{staff}/office-assignments/{assignment}` | DELETE | sanctum + role:admin + StaffPolicy::manageOfficeAssignments |
| `/api/me/password/change-from-temp` | POST | sanctum (throttle:password_change_temp) — exempt from password-change gate |

**Locked decisions:** admin-mediated creation only (no self-registration); system generates a temp password returned **once**, employee forced to change on first use via `EnsurePasswordChanged` middleware (blocks all authed routes except change-from-temp + logout while `must_change_password=true`); soft lifecycle only (never hard-delete — FK preservation). **suspend** = revoke tokens, keep office assignments (reversible); **deactivate** = suspend + soft-remove all office assignments. Last-active-admin and self-modify guards on suspend/deactivate/reset. `office_staff_assignments` got a `public_id` + a partial-unique index `(user_id, office_id) WHERE removed_at IS NULL` (allows re-assigning a removed office). Login blocked for suspended/banned **after** password check (anti-enumeration), enforced in `LoginService`.

**Architecture:** `StaffService` (create + lifecycle, sole writer), `OfficeAssignmentService` (attach/detach/attachMany with `lockForUpdate` + last-assignment guard; `attachMany` runs inside the create transaction), `TempPasswordChangeService` (verify-current → set-new → revoke-all-tokens → issue-new), `TempPasswordGenerator` (`random_int` CSPRNG). All staff exceptions carry `StaffErrorCode` → HTTP via `httpStatus()`, rendered by `bootstrap/app.php`. `StaffResource` embeds `OfficeAssignmentResource` via null-safe `whenLoaded`.

**Process:** first parallel-worktree milestone (Claude = Slice A, Codex = Slice B). Slice A merged first (PR #3); Codex rebased + integrated Slice B (PR #4). Claude's Slice B review caught a blocking suspend/deactivate assignment-lifecycle inversion (fixed pre-merge) and ran a post-merge `security-review` (no HIGH/MEDIUM findings).

**Smoke test:** new `scripts/staff-e2e.php` (6 rollback-wrapped scenarios). Merged-main verification: Pest 92/92, staff-e2e + orders-e2e (32/32) green.

### Real-time (Reverb) milestone (2026-06-02) ✅

WebSocket push replaces polling. Built per `docs/superpowers/specs/2026-05-18-realtime-reverb-design.md` (Phase 1 foundation + Phase 2 events merged earlier; Phase 3 smoke + docs this close-out). Full detail in SYSTEM_SPECIFICATION §17.13.

**Transport:** Reverb (port 8080) ↔ Laravel via Redis pub/sub. Business events `ShouldBroadcast` on the `broadcasts` queue with `$afterCommit = true`; driver location is `ShouldBroadcastNow` (queue-bypassing, ephemeral). **Driver app stays HTTP-only** — server fans out `POST /api/driver/location`. Channel auth is Sanctum-on-`/broadcasting/auth` (`routes/channels.php`).

**Channels (all key on `public_id`, never internal id — Critical Rule 11):** `private:user.{public_id}`, `private:order.{public_id}` (sender/receiver), `private:driver.{public_id}` (driver's User public_id, `+hasRole('driver')`), `public:track.{token}`.

**9 events:** `OrderBroadcastToDriver` / `OrderBroadcastWithdrawn` (driver pool), `OrderStatusChanged` + `OrderStatusChangedPublic`, `OrderDriverAssigned`, `OrderDriverLocationUpdated`, `DriverAccountUpdated`, `NotificationReceived`, `SellerEarningCleared`.

**Broadcast-safe Resources (mandatory pattern):** queued broadcasts run off the HTTP request, so never broadcast `OrderResource`/`DriverProfileResource` (they branch on `$request->user()`). Use request-independent `App\Http\Resources\Broadcast\OrderForPartiesResource` + `DriverForOrderResource` (audience-neutral, strip phone/name/codes/commission/plate/internal-ids); reuse `GuestTrackingResource` + `BroadcastOrderResource`.

**Ops:** `composer dev` runs Reverb + `broadcasts` worker; `docs/deployment/reverb-supervisor.conf.example` for prod.

**Schema delta:** added the standard Laravel `notifications` table (`2026_06_03_000100_create_notifications_table`) — specced in §13.6 but never built (Laravel doesn't ship it by default; §17.1's groups covered only business tables). `NotificationReceived` is now live end-to-end.

**Smoke:** `scripts/realtime-smoke.php` (recording broadcaster, real lifecycle, asserts event sequence + channels + payload safety; commits-then-cleans, no rollback because `$afterCommit` events only fire post-commit). Verified: Pest 133/133, orders-e2e 32/32, realtime-smoke green (incl. live database-notification scenario).

**Channel public-ID hardening (2026-06-03):** `user`/`driver` channels migrated from internal id → `public_id` (id-exposure spec §10). Events carry `*PublicId` strings or resolve the owner's public_id in `broadcastOn()`; dispatch sites pass public_id. Done TDD, full suite green.

### Next Steps (in order)
1. **Account moderation** — global ban/suspend with reason history (builds on the staff suspend/reinstate + `AccountStatus` foundation; adds a staff-action audit table deferred from this milestone).
2. **Test infrastructure** — promote Tinker smoke tests to Pest feature tests against a separate test DB.
3. **Merchant deliveries (sub-project E)** — blocked on merchant onboarding flow.
4. **Cash delivery to seller's address (settlement v2)** — currently office-pickup only per spec §4.10; v2 milestone would build an outbound payout-delivery flow on top of the existing order pipeline.

### Open Questions
- Storage fee policy specifics (flat daily after grace period? Tiered?)
- Retrieval payment flow at office (cash-only? Wallet allowed?)
- Abandonment disposition policy after 30 days
- SMS provider (Plutu or other Libyan gateway)
- Rating & review system
- Admin panel scope

---

## Agent Instructions

**When conflicting with a rule:** Refuse, cite the rule, suggest the correct alternative.

**On edge cases:** Apply closest rule conservatively → flag it → suggest updating this file.

**When adding code:** Ask "Are there existing patterns for [task]?" before writing new implementations.

**On schema changes:** Never retroactively alter financial data. Add new columns forward only.

Update this file when architectural decisions are locked. Keep it accurate and lean.
