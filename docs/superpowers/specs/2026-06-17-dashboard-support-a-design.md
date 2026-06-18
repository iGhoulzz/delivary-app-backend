# Dashboard Support A (Admin-only) — Backend Support for the Internal Dashboard

**Date:** 2026-06-17
**Status:** Design — revised after Codex review
**Cut:** Admin-only (office-staff dashboard + office-context is a later cut)
**Design source:** `docs/design/dashboard/` (the built bilingual EN/AR "Tawseel Ops Console")

---

## 1. Why this milestone exists

The dashboard UI is already designed (`docs/design/dashboard`). If we start the frontend
now it will immediately hit missing backend pieces — `me` context, reference data, a users
directory, the map, settings, driver financials/strikes — and we'd build UI around fake data
whose shapes then churn. This milestone **stabilises the API contract first**, so the frontend
binds to real endpoints.

Scope was derived by reading every admin screen in the design and mapping each data point +
action to an existing endpoint. The gaps below are the result.

## 2. Hard guardrails

- **Additive only. No refactor of existing backend.** Every item is either a *new* file
  (controller / route / resource / service or service *method*) or an *additive touch* —
  a new response field, a new **optional** query param, or a new **nullable** column.
  **No existing endpoint changes behaviour, signature, status code, or logic.**
- **Critical Rules hold.** Any money write (manual adjustment, strike fee) goes through the
  existing locked + audited + ledgered path (`DB::transaction` + `lockForUpdate`, a
  `driver_account_transactions` row, `created_by_admin_id`) — we reuse / extend
  `DriverAccountLedgerService`, never inline bucket math.
- **`public_id` only** in URLs and payloads (Critical Rule 11). Reference tables `regions` /
  `service_areas` keep numeric ids (established exception).
- **Bilingual is a frontend concern.** The backend returns enum *values* + raw strings; the
  frontend dictionary owns EN/AR display, keyed by value. The reference endpoint therefore serves
  enum options as `{value, label}` — `label` is an **English convenience label only** (the enum's
  `label()`), present for debugging/admin tooling; the authoritative contract is the **value set**,
  which keeps frontend dropdowns in sync with backend enums. The backend does **not** emit Arabic
  labels (no `label_ar`) — that would duplicate the frontend dictionary.

## 3. Out of scope (deferred)

- Office-staff dashboard + the multi-office "office context" fix (Codex A3) — next cut.
- **Settlement / payout *writes* from admin** — physical cash-counter events owned by office
  staff (§2.4, §11.2). Admin sees them **read-only**; admin keeps only review + reverse
  (already built). No admin settle/payout endpoints.
- **Force-offline** — *dropped.* Suspending a driver already cascades to offline (§9.4 +
  moderation cascade). The design's "Force offline" button maps to suspend/reinstate.
- **Driver "pay out earnings"** — not a real concept; driver earnings net against cash at
  settlement (§11.3). Button dropped.
- **Notification-preference *editing*** — storage **already exists** on `users`
  (`push_/sms_/email_notifications_enabled`); only the admin *read* exposure is missing. Surface
  them **read-only** in the user-detail payload (additive field). No editing in this cut, no new
  storage.
- **Merchant moderation history** — *dropped as a backend target.* `merchant_profiles` has **no
  audit table** by design; do not add one (see 4.6).
- **Admin-created orders** ("New order" button) — defer.
- **Finance reporting, dashboard KPI summary, activity feed** (Codex B1–B3) — defer to
  "Dashboard Support B"; may ship stubbed if the frontend needs placeholders.

## 4. Endpoint inventory

Legend: **NEW** = new file/method · **ADDITIVE** = new field / optional param / nullable column,
no behaviour change · **EXISTS** = already shipped · **DROP** = explicitly not a target.

### 4.1 Foundations (shell-wide)

| Method · Path | Kind | Notes |
|---|---|---|
| `GET /auth/me` | **ADDITIVE** | Enrich response: `roles[]`, `must_change_password`, `office_assignments[]` (id+name), `is_driver`/`is_merchant` summary, badge **counts** (`pending_orders`, `unread_notifications`). Existing `user` block unchanged. |
| `GET /admin/reference` | **NEW** | Read-only catalogs: `offices[]`, `regions[]`, enum option lists (driver status, account status, merchant status, order status/type, strike reason, moderation reason, vehicle type, document type), each `{value, label}` (English convenience label; frontend owns AR/EN display keyed by value). |
| `GET /admin/map/overview` | **NEW** | Office pins + active drivers (`driver_profiles.current_location`, `activity_status`, live load count). Reads existing columns; **no new tables**. |

### 4.2 Drivers

| Method · Path | Kind | Notes |
|---|---|---|
| `GET /admin/drivers` | **ADDITIVE** | Resource gains `account_status`; `IndexDriverRequest` gains optional `activity_status` filter. Existing fields/sort unchanged. |
| `GET /admin/drivers/{id}` | **ADDITIVE** | `DriverProfileFullResource` gains `regions`, `last_active_at`, `deliveries_today` (computed), `roles`, `orders_as_customer_count`, and read-only notification prefs. |
| `GET /admin/drivers/{id}/account` | **NEW** | Buckets + `max_cash_liability` + lifetime stats + last ~30 ledger rows. Reuses `DriverAccountResource` + `DriverAccountTransactionResource`. |
| `GET /admin/drivers/{id}/strikes` | **NEW** | Strike list. New `DriverStrikeResource` (reason, issuer, linked order public_id, fee, voided flags, dates). |
| `POST /admin/drivers/{id}/strikes` | **NEW** | Add a manual strike (`reason`, optional `fee`). New `DriverStrikeService`. If a fee is set, it posts through the existing `DriverAccountLedgerService::applyFee(...)`. Spec §9.6. |
| `POST /admin/drivers/{id}/strikes/{strike:public_id}/void` | **NEW** | Void an invalid strike (`void_reason`) — a **pure status flip** (`is_voided` / `voided_at` / `voided_by_admin_id`). **Does NOT reverse any fee.** A fee refund, if wanted, is a separate explicit manual adjustment (below). Spec §9.6. |
| `POST /admin/drivers/{id}/account/adjust` | **NEW** | Audited manual adjustment (`bucket`, signed `amount`, `note`). Writes via a **new** `DriverAccountLedgerService::applyManualAdjustment()` method (locked txn + `driver_account_transactions` row, `reason=manual_adjustment`, `created_by_admin_id`) — **no ad-hoc bucket math**. Additive method; existing ledger methods untouched. Spec §2.5. |

**Additive schema:** `driver_strikes` gains a `public_id` (ULID, unique) so the void route binds
by public id per Critical Rule 11. New column → backfill existing rows → add unique index;
forward-only, no behaviour change.

### 4.3 Admin driver onboarding (reuses the locked office lifecycle)

The driver onboarding lifecycle is locked around **office/staff document capture + phone
verification** (§9.7): `pre_registered → (docs captured + phone verified) → submit →
pending_approval → admin approve`. Admin "Add driver" / "Promote to driver" **must drive that
same lifecycle** — it does **not** shortcut to `pending_approval`, and this milestone does
**not** change §9.7.

Implementation = expose admin-gated entry points that reuse the existing office services
(`DriverOnboardingService`, `DriverDocumentService`, OTP/phone-verify, `DriverApprovalService`),
since the admin-only cut has no office-staff screens yet.

| Method · Path | Kind | Notes |
|---|---|---|
| `POST /admin/drivers` (onboard) + `lookup` | **NEW** | Resolve/create the user (existing user by `user_public_id`, or new person) → `pre_registered` profile + vehicle + office. Same resolver as office onboard. |
| `POST /admin/drivers/{id}/verify-phone` | **NEW** | In-office OTP phone verification — mandatory, reuses the office flow. |
| `POST /admin/drivers/{id}/documents` (+ delete) | **NEW** | Document capture/replace via `DriverDocumentService`. |
| `POST /admin/drivers/{id}/submit` | **NEW** | Moves a fully-documented, phone-verified driver to `pending_approval`. Reuses office `submit`. |
| `POST /admin/drivers/{id}/approve` · `reject` | **EXISTS** | Already shipped. |

> If we later want a desk-only fast path that skips face-to-face capture, that is a **separate
> explicit decision** to change §9.7 — not part of this milestone.

### 4.4 Users

| Method · Path | Kind | Notes |
|---|---|---|
| `GET /admin/users` | **NEW** | Paginated directory: search (name/phone/email/public_id), filters `account_status` + `role`. New `UserDirectoryResource` (name, public_id, account_status, `roles[]`, phone/email-verified, `orders_count`, joined, `driver_public_id`/`merchant_public_id` links). The thin `lookup?phone=` stays for the moderation single-lookup. |
| `GET /admin/users/{user}` | **NEW** | Detail: verification flags, role badges, links, orders-as-customer, read-only notification prefs, + moderation history (below). |
| `POST /admin/users/{user}/{suspend,ban,reinstate}` | **EXISTS** | Account moderation (reason_code + detail), audited. |
| `GET /admin/users/{user}/moderation-history` | **EXISTS** | Paginated `account_moderation_actions` audit. |

### 4.5 Orders (additive only — actions already exist)

| Method · Path | Kind | Notes |
|---|---|---|
| `GET /admin/orders` | **ADDITIVE** | `AdminListOrdersRequest` gains optional `search`, `driver_public_id`, `merchant_public_id`. Powers the driver/merchant "orders" panels + the orders search box. Existing status/type filters unchanged. |
| `GET /admin/orders/{id}` | **EXISTS** | `AdminOrderResource` already returns the full `pricing` snapshot **and** the status-log `timeline` (`status_logs` via `RELATIONS = [...statusLogs.actor]`). Verified — no change. |
| assign / unassign / cancel / mark-delivery-failed / redirect-return / waive-retrieval-fees | **EXISTS** | All present. Driver picker source = `GET /admin/drivers?activity_status=online`. |

### 4.6 Merchants (mostly verify — CRUD already complete)

| Item | Kind | Notes |
|---|---|---|
| `admin/merchants` index/show/store/update/suspend/reactivate/ban | **EXISTS** | Full lifecycle shipped. |
| `MerchantResource` | **VERIFY** | Confirm it exposes `commission_rate_override`, `driver_fee_cut_override`, default pickup, owner embed (name/phone/account_status/roles). Add missing as **ADDITIVE**. |
| Merchant moderation history | **DROP** | `merchant_profiles` has **no audit table** by design (minimal model: `status`, `created_by_admin_id`, `approved_at/by`, `notes`). The design's merchant-moderation-history panel has no backend and is **not** a target — do not add an audit table. The owner's *account*-axis history (4.4) still applies to the owner user. |
| Merchant orders panel | — | Covered by `merchant_public_id` filter in 4.5. |

### 4.7 Settings

| Method · Path | Kind | Notes |
|---|---|---|
| `GET /admin/settings` | **NEW** | Curated editable allowlist grouped pricing/payouts/settlement/risk; reads via `PlatformSetting::get`. |
| `PATCH /admin/settings` | **NEW** | Validate per-key (`type` + range, `0 ≤ rate ≤ 1`); write via `PlatformSetting::set($key,$value,$adminId)` — already audits `updated_by_admin_id` + busts cache. Edits affect **new quotes only**; historical snapshots untouched. |

**Confirmed real keys:** `pricing.item_commission_rate`, `pricing.driver_fee_cut_rate`,
`pricing.free_km`, `pricing.per_km_rate`, `pricing.item_size_modifiers`,
`payouts.clearance_hours`, `payouts.min_amount`, `payouts.allow_partial`,
`settlement.reverse_window_hours`, `new_driver_max_liability`.

**Base fee is NOT global.** There is no `pricing.delivery_fee_base` key — the delivery **base
fee is per-region** (`regions.base_fee`, confirmed). The global Settings PATCH does **not** edit
base fee; a per-region rate editor is a separate concern (its own screen / later). Do not invent
a global base-fee key the pricing code doesn't read.

### 4.8 Settlements / Staff / Finance

| Item | Kind | Notes |
|---|---|---|
| `admin/settlements` index/show/reverse · `admin/seller-payouts` index | **EXISTS** | Admin review/reverse shipped (read-only on driver pages). |
| `admin/staff` full CRUD + lifecycle | **EXISTS** | Complete. |
| Finance reporting / KPI summary / activity feed | **DEFER** | Dashboard Support B. |

## 5. Verification status (post-Codex review)

1. **Base fee** — RESOLVED: per-region `regions.base_fee` exists; no global key. (4.7)
2. **`AdminOrderResource` timeline + pricing** — RESOLVED: both already present. (4.5)
3. **Merchant audit** — RESOLVED: no audit table by design; merchant-history panel dropped. (4.6)
4. **Notification prefs** — RESOLVED: stored on `users`; expose read-only. (§3)
5. **`MerchantResource` field coverage** — open verify (4.6).
6. **`me` office line** — reuse `office_staff_assignments` (name) for the TopBar office label. Minor.

## 6. Decisions (locked)

- Office cash writes stay office-only; admin pages show them read-only. *(§2.4 / §11)*
- Strike void/add + manual adjustment = admin powers, built audited. *(§9.6 / §2.5)*
- **Voiding a strike is a status flip only** — never an implicit fee reversal; fee refunds go
  through the audited manual-adjustment path. *(Critical Rules — no implicit money writes)*
- **Manual adjustment** = a new `DriverAccountLedgerService::applyManualAdjustment()` method
  (locked + ledgered), not inline bucket math.
- **Admin onboarding reuses the full office lifecycle** (docs + phone verification → submit →
  pending_approval); it does **not** shortcut to `pending_approval` and does **not** change §9.7.
- Force-offline dropped; driver-payout button dropped. *(redundant / not a real concept)*
- Merchant moderation-history panel dropped (no audit table by design).
- Notification-pref *editing*, admin-created orders, finance/summary/activity → deferred.

## 7. Proposed slicing (Claude / Codex parallel)

- **Slice A (Claude):** Foundations (`me`, reference, map overview) + Settings.
- **Slice B (Codex):** Users directory/detail + Orders additive filters + Merchants verify.
- **Slice C (Claude):** Drivers admin reads (account/strikes) + strike void/add + manual
  adjustment + `driver_strikes.public_id` migration.
- **Slice D (Codex):** Admin driver onboarding (reuses the full office lifecycle).

## 8. Verification

- Pest feature test per new endpoint (admin-gated happy path + authz + validation).
- **Non-refactor proof:** the existing suite stays green with **zero changes to existing test
  expectations** — additive fields/params/columns don't break current assertions. Pint clean.
- Money-write endpoints (manual adjust, strike fee) get ledger + lock assertions; strike-void
  tests assert balances are **unchanged**.
- The only schema/service additions are `driver_strikes.public_id` (nullable→backfill→unique)
  and `DriverAccountLedgerService::applyManualAdjustment()` — both additive.
