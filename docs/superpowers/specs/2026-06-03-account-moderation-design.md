# Account Moderation — Design Spec

**Date:** 2026-06-03
**Status:** Approved (design); awaiting user review of this written spec
**Depends on:** Staff CRUD milestone (AccountStatus + LoginService gate + StaffService lifecycle), id-exposure remediation (public_id contract)

---

## 0. Context

The platform can today moderate exactly one population — **staff** — and only crudely: `StaffService::suspend/reinstate/deactivate` flip `users.account_status` and revoke tokens, scoped to `/api/admin/staff`, with **no reason and no audit trail**. Regular users (senders/buyers) have **no** moderation path at all. The `AccountStatus` enum already defines `Suspended`, `SuspendedUnpaidFees`, and `Banned`, and `LoginService` already blocks non-`canLogin()` statuses (anti-enumeration) — but nothing writes `Suspended`/`Banned` for non-staff, and `SuspendedUnpaidFees` is **declared but written nowhere** (dead state).

This milestone introduces a **single, audited, admin-only account-moderation layer** over the `AccountStatus` axis for *any* user, and routes the existing staff suspensions through it so there is one audited path. It also delivers the **staff-action audit table deferred from Staff CRUD** (scoped to moderation).

§2.5 of the system spec already assigns admin *"disputes, suspensions"* and *"reviews driver strikes"* — this milestone implements the "suspensions" part.

---

## 1. Locked decisions (from brainstorming — do not relitigate)

| # | Decision | Rationale |
|---|---|---|
| 1 | **Target = all user types, unified** | One audited path for customers, drivers, staff |
| 2 | **Axis = `AccountStatus` only** (`Active` ⇄ `Suspended`/`Banned`) | "Can this person use the platform at all" — distinct from operational `DriverStatus` |
| 3 | **Admin-only** (double-gated `role:admin` + policy) | Matches §2.5; bans are high-sensitivity |
| 4 | **Targeted cascade** | Revoke tokens always; driver → force offline + pulled from dispatch (do NOT touch `DriverStatus`); staff → preserve office assignments; never auto-cancel a live delivery (flag for support) |
| 5 | **Indefinite suspensions + manual reinstate** | No timed/auto-expiry job; ban is terminal-intent but reversible by admin |
| 6 | **Reason = structured code + free-text detail** | Reportable + specific |
| 7 | **Approach A — `AccountModerationService` is the sole `AccountStatus` authority; `StaffService` delegates to it** | Removes the duplicate, unaudited staff-suspend path |
| 8 | **Reinstate respects finance** | Reinstating a user who still owes fees lands them in `SuspendedUnpaidFees`, not `Active` |
| 9 | **Driver account-ban ≠ DriverStatus change** | Two independent axes; ban cascades (offline + block) but leaves `DriverStatus` to ops/strikes |
| 10 | **Minimal `GET /api/admin/users/lookup?phone=` only — no full directory** | Admins need to resolve an abuse report (a phone number) into a moderation target; this mirrors the existing `*/lookup` pattern (`office/drivers/lookup`). A full searchable/filterable/paginated user *directory* belongs to the future internal-dashboard milestone. (Per Codex review.) |

### Out of scope (deliberate)
- Operational `DriverStatus` suspend/ban and the **strike system** (§9.1/§9.6) — separate axis, already built.
- **Finance-driven auto-suspension** (debt → `SuspendedUnpaidFees` at settlement/retrieval time) — a separate trigger; this milestone only *reads* outstanding-fee state during reinstate and becomes the first writer of `SuspendedUnpaidFees`.
- Timed/auto-expiring suspensions.
- User-facing ban notifications (no realtime/push to the moderated user in MVP).
- Full admin user **directory** — searchable/filterable/paginated index (deferred to internal dashboard). The single-purpose phone **lookup** (§8) is in scope.
- Self-service appeals.

---

## 2. AccountStatus semantics after this milestone

| Status | Owner | Set by |
|---|---|---|
| `Active` | shared | registration verify, reinstate |
| `PendingVerification` | auth | registration |
| `Suspended` | **moderation** | `AccountModerationService::suspend` (manual) |
| `Banned` | **moderation** | `AccountModerationService::ban` (manual) |
| `SuspendedUnpaidFees` | finance (future) + moderation reinstate | reinstate-with-debt (first writer); later: settlement/retrieval flows |

`canLogin()` already returns false for `Suspended`/`SuspendedUnpaidFees`/`Banned`; `canCreateOrders()`/`canWithdraw()` only true for `Active`. No enum changes required.

---

## 3. `AccountModerationService` (sole authority)

`app/Services/Moderation/AccountModerationService.php` — constructor-injected, no facades.

```
suspend(User $target, User $actor, ModerationReason $code, string $detail): User
ban(User $target, User $actor, ModerationReason $code, string $detail): User
reinstate(User $target, User $actor, ModerationReason $code, string $detail): User
```

Each method, inside one `DB::transaction()`:
1. **Guard** (throws `ModerationException` with a `ModerationErrorCode`):
   - `CANNOT_MODERATE_SELF` (422) — `actor->id === target->id`
   - `LAST_ACTIVE_ADMIN` (422) — target is an admin and is the last `Active` admin (suspend/ban only) — reuses the Staff CRUD guard helper
   - `INVALID_TRANSITION` (422) — see §5 transition table (e.g. suspend an already-`Banned` user, reinstate an `Active` user)
2. **Write** `account_status` via `forceFill(['account_status' => ...])->save()` (snapshot `from`/`to`).
3. **Revoke tokens** on suspend/ban: `$target->tokens()->delete()`.
4. **Targeted cascade** (§4).
5. **Append audit row** (§6).
6. Return fresh `$target`.

**Guards reused:** extract/reuse the "last active admin" check that Staff CRUD added (currently in `StaffService`) into a shared location both services call (e.g. a `User` query scope `scopeActiveAdmins()` or a small `AdminGuard` helper) — no duplicated logic.

---

## 4. Targeted cascade

| Target kind | Cascade on suspend/ban |
|---|---|
| Any user | Revoke all Sanctum tokens |
| Driver (has `driver` role / driver profile) | Set `driver_profiles.activity_status = offline` (drops them from `BroadcastService::eligibleDriversFor`, which filters `Online`). **Do not** touch `DriverStatus`. **Do not** auto-cancel a live delivery — if the driver has an active order, the moderation still succeeds; resolution is a support/admin-assignment concern (existing `AdminAssignmentService::unassign`). |
| Staff (admin/office_staff) | Tokens revoked; office assignments **preserved** (restored implicitly on reinstate — same semantics as Staff `suspend`, not `deactivate`) |

Reinstate performs **no** cascade beyond the status change (tokens are simply not re-issued; the user logs in fresh).

The driver-offline write is the only cascade side-effect; it is idempotent and safe if the driver is already offline.

---

## 5. Status transition rules

| Action | Allowed from | Result |
|---|---|---|
| `suspend` | `Active`, `PendingVerification` | `Suspended` |
| `ban` | any except `Banned` | `Banned` |
| `reinstate` | `Suspended`, `Banned` | `Active`, **or** `SuspendedUnpaidFees` if `target->hasOutstandingFees()` |

- Manual reinstate is **not** allowed from `SuspendedUnpaidFees` (that state is finance-owned; clearing it is a finance/settlement concern, not moderation). Attempting → `INVALID_TRANSITION`.
- No-op transitions (e.g. suspend an already-`Suspended` user) → `INVALID_TRANSITION` (422).

---

## 6. Data model — `account_moderation_actions`

Append-only audit table (this is the deferred staff-action audit table, scoped to moderation).

```php
Schema::create('account_moderation_actions', function (Blueprint $table): void {
    $table->id();
    $table->ulid('public_id')->unique();
    $table->foreignId('user_id')->constrained('users')->restrictOnDelete();   // target
    $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();  // admin
    $table->string('action');        // ModerationAction
    $table->string('reason_code');   // ModerationReason
    $table->text('detail');          // required free-text
    $table->string('from_status');   // AccountStatus snapshot
    $table->string('to_status');     // AccountStatus snapshot
    $table->timestamp('created_at')->nullable();   // append-only; no updated_at
    $table->index(['user_id', 'created_at']);
    $table->index('action');
});
```

`AccountModerationAction` model: `getRouteKeyName() = public_id`; `belongsTo(User, target)` as `user`, `belongsTo(User, actor_id)` as `actor`; casts `action`/`reason_code` to enums, statuses to `AccountStatus`. Append-only (no updates/deletes in app code).

---

## 7. Enums & errors

- `App\Enums\ModerationAction`: `Suspend`, `Ban`, `Reinstate`.
- `App\Enums\ModerationReason`: `Fraud`, `Abuse`, `NonPayment`, `PolicyViolation`, `Other` (+ `label()`).
- `App\Enums\ModerationErrorCode`: `CannotModerateSelf` (422), `LastActiveAdmin` (422), `InvalidTransition` (422) — each with `httpStatus()`.
- `App\Exceptions\Moderation\ModerationException` carrying `errorCode(): ModerationErrorCode`, rendered to JSON in `bootstrap/app.php` (mirrors `StaffDomainException`).

---

## 8. API surface (admin-only)

All under `auth:sanctum` + `role:admin` + `ModerationPolicy`; `{user}` route-bound by `public_id` (Critical Rule 11).

| Endpoint | Method | Body / Query | Returns |
|---|---|---|---|
| `/api/admin/users/lookup` | GET | `?phone=` (E.164) | thin `UserLookupResource` (0–1 match) |
| `/api/admin/users/{user}/suspend` | POST | `reason_code`, `detail` | `UserModerationResource` (status + latest action) |
| `/api/admin/users/{user}/ban` | POST | `reason_code`, `detail` | same |
| `/api/admin/users/{user}/reinstate` | POST | `reason_code`, `detail` | same |
| `/api/admin/users/{user}/moderation-history` | GET | — | paginated `ModerationActionResource` |

- **Lookup:** by exact E.164 `phone` (the natural moderation entry point). Returns at most one match as a thin identity payload `{id: public_id, name, phone, roles, account_status}` — admin context, so phone is shown. Anti-enumeration is moot (admin-only, double-gated). Mirrors the existing `office/drivers/lookup` / `office/seller-payouts/lookup` pattern. **Not** a directory: no listing, filters, pagination, or name search (those ship with the dashboard).
- **FormRequest** per write endpoint: `reason_code` (`required`, `Rule::enum(ModerationReason::class)`) — admins must pick a real reason on direct endpoints; `detail` (`required`, string, `min:3`, `max:1000`). (StaffService delegation supplies a default `reason_code = Other` for backward compatibility — §9.)
- **`ModerationPolicy`**: `moderate(User $admin, User $target)` — admin role; deny self (defense-in-depth alongside service guard). Lookup + history are admin-only via `role:admin`.
- **Resources** expose only `public_id`; `ModerationActionResource` nests `actor` as `{id: public_id, name}` (id-exposure pattern), never internal ids.
- **Rate limit:** `throttle:moderation` (e.g. 30/min/admin) on writes + lookup — modest, matches other admin write limiters.

---

## 9. StaffService delegation (Approach A)

- `StaffService::suspend($staff, $actor, ?reason, ?detail)` → `AccountModerationService::suspend(...)` (account_status + tokens + audit). Default `reason_code = Other`, `detail = 'Staff lifecycle suspension'` when not supplied.
- `StaffService::reinstate(...)` → `AccountModerationService::reinstate(...)`.
- `StaffService::deactivate(...)` → `AccountModerationService::suspend(...)` **then** its existing office-assignment soft-removal (unchanged).
- Staff suspend/deactivate **FormRequests gain optional** `reason_code` + `detail` (backward compatible). Existing Staff CRUD behavior and `staff-e2e.php` stay green; staff suspensions are now audited automatically.
- `reset-temp-password` and `create` are unchanged (not moderation).

---

## 10. `User::hasOutstandingFees(): bool`

MVP definition: `true` if the user has a `driverAccount` with `debt_balance > 0` (the only concrete user-level debt today). Documented as extensible (e.g. future seller unpaid-retrieval-fee tracking). Used solely by reinstate (§5). This makes the milestone the **first writer** of `SuspendedUnpaidFees`.

---

## 11. Testing strategy

**Unit (Pest):**
- `AccountModerationService`: suspend/ban/reinstate happy paths; each guard (`CANNOT_MODERATE_SELF`, `LAST_ACTIVE_ADMIN`, `INVALID_TRANSITION`); reinstate→`Active` vs reinstate-with-debt→`SuspendedUnpaidFees`; token revocation; driver cascade sets `activity_status=offline`; staff assignments preserved; audit row written with correct snapshots.
- `ModerationPolicy` positive/negative.
- Transition table edge cases (ban-from-suspended, no-op rejects).

**Feature:**
- Each endpoint `actingAs(admin)` → 200 + status changed + audit persisted; non-admin → 403; self → 422; last-active-admin → 422; `{user}` resolves by `public_id`; validation (missing `reason_code`/`detail`, `detail` under `min`) → 422; `moderation-history` pagination + nested public ids only.
- **Lookup:** admin finds a user by exact `phone` → thin payload with `public_id`; non-admin → 403; unknown phone → empty/`null` (200); no internal ids in payload.
- Regression: existing Staff CRUD feature tests + `staff-e2e.php` still green after delegation; staff suspend now produces an audit row.

**Smoke:** new `scripts/moderation-e2e.php` (rollback-wrapped): suspend a customer → `LoginService` blocks login; ban an online driver → `activity_status=offline` + excluded from `eligibleDriversFor`; reinstate a driver with `debt_balance>0` → `SuspendedUnpaidFees`; reinstate a clean user → `Active`; audit rows present with correct `from/to`; staff suspend via `StaffService` writes an audit row.

---

## 12. File map

**New:**
- `app/Services/Moderation/AccountModerationService.php`
- `app/Enums/ModerationAction.php`, `ModerationReason.php`, `ModerationErrorCode.php`
- `app/Exceptions/Moderation/ModerationException.php`
- `app/Models/AccountModerationAction.php`
- `app/Policies/ModerationPolicy.php`
- `app/Http/Controllers/Api/Admin/UserModerationController.php` (suspend/ban/reinstate/history)
- `app/Http/Controllers/Api/Admin/AdminUserLookupController.php` (single-action lookup; or a `lookup` method on the moderation controller)
- `app/Http/Requests/Admin/Moderation/{ModerationActionRequest, UserLookupRequest}.php` (shared action request; lookup request validates `phone` E.164)
- `app/Http/Resources/Moderation/{UserModerationResource,ModerationActionResource,UserLookupResource}.php`
- `database/migrations/XXXX_create_account_moderation_actions_table.php`
- `database/factories/AccountModerationActionFactory.php`
- `scripts/moderation-e2e.php`
- tests: `tests/Unit/Services/Moderation/...`, `tests/Unit/Policies/ModerationPolicyTest.php`, `tests/Feature/Admin/Moderation/...`

**Modified:**
- `app/Services/Staff/StaffService.php` (delegate to moderation)
- `app/Http/Requests/.../Staff suspend/deactivate requests` (optional reason fields)
- `app/Models/User.php` (`hasOutstandingFees()`, shared active-admin scope if extracted)
- `routes/api.php` (5 routes + throttle)
- `bootstrap/app.php` (render `ModerationException`; register `throttle:moderation`)
- `app/Policies` registration (AuthServiceProvider) if not auto-discovered

---

## 13. Done criteria

- [ ] 5 endpoints live, admin-only, `public_id`-bound (suspend/ban/reinstate/history + phone lookup)
- [ ] `AccountModerationService` sole `AccountStatus` authority; `StaffService` delegates
- [ ] `account_moderation_actions` migration + model + factory
- [ ] Reinstate-with-debt → `SuspendedUnpaidFees`; first writer of that state
- [ ] Driver cascade (offline + out of pool); staff assignments preserved
- [ ] All Pest green incl. existing Staff CRUD; `staff-e2e.php` + new `moderation-e2e.php` pass; `orders-e2e.php` 32/32 no regression
- [ ] Pint clean
- [ ] Security review (admin-only authZ, self/last-admin guards, no internal-id leaks in resources)
- [ ] SYSTEM_SPECIFICATION §17.x + CLAUDE.md "Current Project State" updated
