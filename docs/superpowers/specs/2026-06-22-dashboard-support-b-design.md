# Dashboard Support B (Admin-only) — Overview, Finance, Staff Activity, Notification-Pref Editing

**Date:** 2026-06-22
**Status:** 📝 DRAFT — design under review. (Successor to Dashboard Support A, §17.18.)
**Cut:** Admin-only (office-staff dashboard remains a later cut).
**Design source:** `docs/design/dashboard/` — the built bilingual "Tawseel Ops Console"
(`overview.jsx`, `finance.jsx`, `staffDetail.jsx`, `userDetail.jsx`).
**Process:** `docs/WORKFLOW.md` (brainstorm → spec → sliced plan → execute → cross-review → merge → closeout).

---

## 1. Why this milestone exists

Dashboard Support A stabilised the admin API for every **CRUD / roster / detail** screen. Four
designed screens still have **no backend** and the frontend would otherwise bind to fake data:

- **Overview** (`overview.jsx`) — KPI stat cards + recent-activity feed (the landing page).
- **Finance** (`finance.jsx`) — platform revenue visibility (accrued vs cash, breakdowns, trend).
- **Staff modal → Activity tab** (`staffDetail.jsx`) — a per-staff/admin action timeline.
- **Notification-preference editing** — Support A exposes prefs read-only; the toggle has no write.

This milestone adds exactly those four backends. Admin-created orders (the design's "New order"
button) remains **deferred** at the user's direction.

Every shape below was derived by reading the design screens and mapping each data point to **real
backend columns/services** — not to the design's mock data. Where the design's own comments stated a
backend semantic (e.g. finance.jsx: *"Platform revenue = commission_amount + driver_fee_cut_amount"*),
it was checked against `PricingService` and the schema before being locked here.

## 2. Hard guardrails

- **Additive only. No refactor.** Every item is a *new* file (controller / route / resource /
  read-service) or an *additive touch* (a new response field). **No existing endpoint changes
  behaviour, signature, status code, or logic. No new tables. No migrations.** (The reporting
  timezone is a new **config file**, not a column.)
- **Revenue is derived, never written, never recalculated onto records.** All financial figures are
  read-time aggregates of **existing snapshots** (`orders.commission_amount`,
  `orders.driver_fee_cut_amount`, settlement/payout cash columns). No financial write of any kind in
  this milestone (Critical Rules 1, 20). The only write is the notification-pref toggle (§6.4).
- **`public_id` only** in URLs and payloads (Critical Rule 11). Reference tables `regions` /
  `service_areas` keep numeric ids (established exception). Activity/actor references render as
  `{ public_id, name }`, **never** an internal id.
- **Dashboard-safe payloads** (Critical Rules 11–13). Activity items and report rows expose only
  public_ids + non-sensitive descriptive fields (amounts, statuses, reason codes, timestamps),
  reusing the **exact exposure level of the corresponding existing admin resource**. No pickup/
  delivery codes, no internal ids, no raw phone numbers unless an existing admin resource already
  surfaces them.
- **Bilingual is a frontend concern.** The backend returns enum **values** + raw data + an English
  convenience label only; the frontend dictionary owns EN/AR display, keyed by value (same contract
  rule as Support A's reference endpoint).

## 3. Out of scope (deferred)

- **Admin-created orders** ("New order" button) — deferred at user direction.
- **Office-staff dashboard / office-context cut** — later milestone.
- **A persisted `orders.pickup_region_id` + `orders.pickup_office_id` (production-hardening follow-up).**
  Office attribution is currently done by spatial resolution at *report* time (§5.2). This has a known
  edge case flagged in Codex review: `byOffice()` runs `ST_Contains` with no `LIMIT 1`, so if two
  **overlapping** active regions both contain a pickup, that delivered order is counted under **both**
  offices — inflating `by_office` past `accrued.total`. `PricingService::resolveRegion()` avoids this
  because it uses `LIMIT 1` (the order is priced once). The risk is therefore confined to finance
  *reporting* attribution — never customer charge, settlement, payout, or order creation. **Non-blocking
  for this milestone** (active regions are operationally non-overlapping today). The proper fix —
  matching the existing snapshot philosophy (commission/fees are snapshotted at creation, Critical
  Rule 1) — is to **snapshot the resolved pickup region + office on the order at pricing time**:
  add nullable `orders.pickup_region_id` + `orders.pickup_office_id`, persist them when
  `PricingService` resolves the region, switch finance `by_office` + the `office_id` filter to read
  the snapshot instead of re-running `ST_Contains`, and backfill older orders (resolve pickup once, or
  leave `unassigned`). Benefits: no double-count from overlapping regions, and historical finance
  reports stay stable even if region boundaries change later. Needs a migration → a separate, explicit
  future decision, not part of this additive milestone.
- **A DB audit trail for notification-pref edits** — there is no generic admin-action audit table and
  the `*_notifications_enabled` columns carry no `updated_by_admin_id`; adding one breaks the additive
  guardrail. The write is transactional + `Log::info` only this milestone (§6.4).
- **Cron/materialised summaries / caching** beyond an optional short-TTL cache on Overview KPIs.

## 4. Verified backend facts (the design rests on these)

| Fact | Source | Consequence |
|---|---|---|
| `driver_fee_cut_amount = base × rate` is computed for **every** order type; `commission_amount` is **sale-only** (`p2p_sale`/`merchant_delivery`). | `PricingService::compute()` (no `$isSale` guard on the cut; `commissionRate='0'` otherwise) | "Revenue-bearing" = `driver_fee_cut_amount > 0`, **not** sale-only. |
| `orders` has **no** `region_id` / `service_area_id` / origin `office_id` (only `return_office_id`). Region is resolved spatially at pricing time and **not persisted**. | `create_orders_table` + `Order::$fillable` | Revenue-by-office needs a PostGIS spatial join, not an FK join. |
| `regions.office_id` exists (nullable FK). | `add_office_id_to_regions_table` | Region → office is a real link. |
| `settlements.office_id`, `seller_payouts.office_id` are stored. | settlement/payout migrations | Cash-by-office uses the stored column. |
| Driver buckets: `cash_to_deposit`, `earnings_balance`, `debt_balance`. | `create_driver_accounts_table` | "Pending settlement" = any non-zero of the three. |
| `OrderActorType` = `user\|driver\|admin\|system\|office_staff`; staff/admin order actions are written as `admin`/`office_staff`, **never** `user`. | `OrderActorType` enum + `AdminAssignmentService`/`FailedDeliveryService`/`StateTransitionService` | Staff order-activity must filter `actor_type IN ('admin','office_staff')`. |
| Authorship columns span ~11 tables (settlements, seller_payouts, account_moderation_actions, driver_account_transactions, driver_strikes ×2, order_status_logs, driver_profiles, driver_documents, merchant_profiles ×2, platform_settings, office_inventory ×3). Some append-only, several latest-pointer. | migration scan | Staff-activity merges them all, flagged by class (§6.3). |
| `OrderStatus::isTerminal()` = {delivered, retrieved_by_seller, abandoned, cancelled_by_user, cancelled_by_admin}; commission/fee-cut are snapshotted at creation but earned at `delivered`. | `OrderStatus` enum + `CodeVerificationService` | `active_orders` = non-terminal; accrued revenue = `delivered` only (§5.1). |
| `users.{push,sms,email}_notifications_enabled` booleans (default true); **no** `updated_by`. | `create_users_table` | Pref editing is a plain transactional write, un-audited in DB. |
| `config/app.php` timezone = **UTC**. | config | Day-bucketing needs an explicit reporting timezone. |

## 5. Cross-cutting decisions (locked)

### 5.1 Platform revenue formula
`platform_revenue = commission_amount + driver_fee_cut_amount` per order, summed over the range.
- `by_source`: `commission` = Σ`commission_amount` (sale orders only — non-sale rows contribute 0);
  `fee_cut` = Σ`driver_fee_cut_amount` (all priced orders).
- **Revenue-recognition status filter (required).** The cut/commission are *snapshotted at creation*,
  so `driver_fee_cut_amount > 0` alone would wrongly count cancelled **and** still-in-flight orders.
  Revenue is **earned at successful delivery** (seller_earnings booked + driver cut credited in
  `CodeVerificationService`); nothing is earned before that and orders can still cancel/fail. So
  **accrued platform revenue = orders with `status = delivered`** only. Pre-pickup, in-flight,
  cancellation, and return-flow (`delivery_failed`/`returning_to_office`/`at_office`/
  `retrieved_by_seller`/`abandoned`) orders are excluded — they earned no commission/fee-cut. This
  also keeps the Finance screen's reconciliation-gap label honest: "accrued not yet realized in cash —
  earnings pending clearance / drivers not yet settled" are all **post-delivery** states, so accrued
  must be delivered-only for `gap = accrued − cash` to mean what the label says.
- "Revenue-bearing orders" filter = `status = 'delivered' AND driver_fee_cut_amount > 0`.
- **Time basis = delivery time.** Because revenue is earned at delivery, every revenue query (range
  filter, `daily_trend`, `recent_orders`) buckets on **`orders.delivered_at`**, never `created_at` —
  an order created last week and delivered today belongs to *today*. Reporting-tz day grouping applies
  to `delivered_at`.
- **Pipeline / projected revenue is out of scope and must NOT be folded into `accrued`.** If a
  forward-looking number is wanted later, it ships as a *separate* `projected_revenue` metric (derived
  from in-flight orders' snapshots), never mixed into accrued. *(User decision, 2026-06-22 — option A.)*
- All sums in SQL using decimal types; PHP-side combination via `bcmath`. **No floats.**

### 5.2 Office attribution
- **Accrued revenue → office:** spatial resolution of the order's `pickup_location` against active
  region boundaries → `regions.office_id`:
  `ST_Contains(regions.boundary::geometry, orders.pickup_location::geometry)`, joined through
  `service_areas` with **`regions.is_active = true AND service_areas.is_active = true`** — mirroring
  `PricingService::resolveRegion()` **exactly** (it checks both active flags). Orders whose pickup
  resolves to no active region, or a region with null `office_id`, fall into an `unassigned` bucket.
- **Cash → office:** stored `settlements.office_id` / `seller_payouts.office_id` (the physical
  counter where cash moved). Never the driver's current office.
- The optional `office_id` request filter resolves **per side** with the same rule (revenue side
  spatial; cash side stored column).
- **Never** `driver_profiles.office_id` for order attribution — it is mutable and does not represent
  where the order belongs.

### 5.3 Reporting timezone
New `config/reporting.php` → `'timezone' => env('REPORTING_TIMEZONE', 'Africa/Tripoli')`.
`config/app.php` stays UTC. All **day-based** metrics (`delivered_today`, `daily_trend`, and the
`today/7d/30d` range boundaries) bucket in the reporting timezone via
`(created_at AT TIME ZONE 'UTC' AT TIME ZONE :tz)::date`. Point-in-time gauges (`active_orders`,
`online_drivers`, `pending_settlements`) are timezone-free. A small `App\Support\ReportingTime`
helper centralises "start/end of range" and the SQL timezone expression so Overview and Finance share
one definition.

### 5.4 Honest trend rule
A KPI card emits trend fields (`delta_pct`, `direction`, `sparkline`) **only where a real comparison
series exists**. Point-in-time gauges emit `delta_pct: null`, `direction: null`, `sparkline: null`;
the frontend hides the badge/sparkline for nulls. No fabricated trends.

### 5.5 Actor / safety contract for activity feeds
Every activity item carries `actor: { public_id, name } | null` (ULID, never internal id) and exposes
per-kind descriptive fields at **the same exposure level the existing admin resource already uses**
for that entity. Enumerated, dashboard-safe — see §6.3.

## 6. Endpoint inventory

All endpoints: `auth:sanctum` + `role:admin` + `staff.password_change_required`, `public_id`-bound.

### 6.1 `GET /admin/overview`  *(NEW — Slice B)*

Backs `overview.jsx` stat cards + recent-activity feed. (The map and Active-Drivers panels are
already fed by the shipped `GET /admin/map/overview`; this endpoint does **not** duplicate the driver
list.)

```jsonc
{
  "stats": [
    { "id": "delivered_today", "value": 128, "money": false,
      "delta_pct": 12.4, "direction": "up", "sparkline": [/* last 7 reporting-tz days */] },
    { "id": "active_orders",        "value": 37,  "money": false, "delta_pct": null, "direction": null, "sparkline": null },
    { "id": "online_drivers",       "value": 14,  "money": false, "delta_pct": null, "direction": null, "sparkline": null },
    { "id": "pending_settlements",  "value": 6,   "money": false, "delta_pct": null, "direction": null, "sparkline": null }
  ],
  "activity": [
    { "kind": "delivered|assigned|failed|pending|driver",
      "order_public_id": "01J...", "actor": { "public_id": "01J...", "name": "..." },
      "to_status": "delivered", "created_at": "2026-06-22T08:11:00Z" }
  ]
}
```

- `delivered_today`: count of orders whose delivered transition landed in the current reporting-tz
  day; `delta_pct`/`direction` vs the previous reporting-tz day; `sparkline` = last 7 daily counts.
- `active_orders`: current count of **open operational orders** = any **non-terminal** status
  (`OrderStatus::isTerminal()` false → excludes `delivered`, `retrieved_by_seller`, `abandoned`, and
  both cancellations; **includes** return-flow states still in custody, e.g. `at_office`). Point-in-
  time gauge. (If a stricter "driver in-flight only" number is wanted later it is a *separate*
  `in_flight_orders` card, not a redefinition of this one.)
- `online_drivers`: current count `driver_profiles.activity_status <> 'offline'`.
- `pending_settlements`: count of `driver_accounts` with
  `cash_to_deposit <> 0 OR earnings_balance <> 0 OR debt_balance <> 0`.
- `activity`: latest ~15 `order_status_logs` (eager-load `order`, resolve `actor` to `{public_id,
  name}`), `kind` derived from `to_status`. Order-activity only (staff activity is §6.3).
- Optional short-TTL cache (~30–60s) keyed on the curated stat set is allowed; the feed is live.

### 6.2 `GET /admin/finance/report`  *(NEW — Slice A)*

Backs `finance.jsx`. Params: `range ∈ {today,7d,30d,all}` (default `30d`), optional `office_id`
(public_id of an office). Validated by FormRequest (422 on bad enum / unknown office).

```jsonc
{
  "range": "30d",
  "office": null,                       // or { "public_id": "...", "name": "..." }
  "accrued": { "total": "...", "commission": "...", "fee_cut": "..." },
  "cash":    { "total": "...", "settlement_cash_net": "...", "payouts": "..." },
  "gap": "...",                         // accrued.total - cash.total
  "by_source":   [ { "key": "commission", "amount": "..." }, { "key": "fee_cut", "amount": "..." } ],
  "by_merchant": [ { "merchant": { "public_id": "...", "name": "..." }, "amount": "..." } ], // top 6
  "by_office":   [ { "office": { "public_id": "...", "name": "..." } | "unassigned", "amount": "..." } ],
  "daily_trend": [ { "date": "2026-06-21", "amount": "..." } ],   // reporting-tz days
  "recent_orders": [
    { "order_public_id": "...", "type": "standard_delivery|p2p_sale|merchant_delivery",
      "merchant": { "public_id": "...", "name": "..." } | null,   // present for merchant_delivery
      "item_value": "...", "commission_amount": "...", "driver_fee_cut_amount": "...",
      "platform_revenue": "..." }                                 // latest ~12
  ]
}
```

- `cash.settlement_cash_net` = Σ(`cash_received_from_driver` − `cash_paid_to_driver`) over
  **`settlements.status = 'completed'`** in range (+ office); `cash.payouts` = Σ`seller_payouts.amount`
  over **`seller_payouts.status = 'paid'`** bucketed by **`paid_at`** (+ office);
  `cash.total = settlement_cash_net − payouts`. Disputed/cancelled settlements and unpaid payouts are
  excluded.
- `accrued` from order snapshots over revenue-bearing orders **bucketed on `delivered_at`** (§5.1),
  office via §5.2 spatial rule.
- All amounts are decimal strings (no float JSON).

### 6.3 `GET /admin/staff/{staff}/activity`  *(NEW — Slice C)*

Backs the staff-modal Activity tab. **This is an accountability / audit surface** — staff and admins
hold internal access, so the timeline must be *comprehensive*, not a sample of a few action types.
`{staff}` is a user public_id. Paginated, newest first, dashboard-safe (no internal ids, no order/
pickup/delivery codes, no raw phones; every entity reference is `{ public_id, name }`). The timeline
merges **two classes** of source:

**(a) Append-only event sources — the staff's full action history:**

| `kind` | Source (actor column = this staff) | Safe fields |
|---|---|---|
| `order_action` | `order_status_logs` where `actor_id = staff.id AND actor_type IN ('admin','office_staff')` | `order: {public_id}`, `from_status`, `to_status`, `occurred_at` |
| `settlement_processed` | `settlements.processed_by_staff_id` | `settlement: {public_id}`, `driver: {public_id,name}`, `cash_received_from_driver`, `cash_paid_to_driver` |
| `seller_payout_paid` | `seller_payouts.paid_by_staff_id` | `payout: {public_id}`, `seller: {public_id,name}`, `amount` |
| `account_moderation` | `account_moderation_actions.actor_id` | `target: {public_id,name}`, `action`, `reason_code` |
| `driver_account_adjustment` | `driver_account_transactions.created_by_admin_id` | `driver: {public_id,name}`, `bucket`, `amount`, `reason` |
| `driver_strike_issued` | `driver_strikes.issued_by_admin_id` | `driver: {public_id,name}`, `reason`, `fee_amount` |
| `driver_strike_voided` | `driver_strikes.voided_by_admin_id` | `strike: {public_id}`, `driver: {public_id,name}`, `void_reason` |
| `office_return_received` | `office_inventory.received_by_staff_id` | `order: {public_id}` |
| `office_order_retrieved` | `office_inventory.retrieved_by_staff_id` | `order: {public_id}` |

**(b) Latest-pointer attribution columns — current attribution only, NOT a full edit history.**
These tables store only the *most recent* actor, so they surface this staff's attribution where it is
still the latest value, with the relevant timestamp. A complete per-change history would need an audit
table (deferred, §3) — this limitation is stated explicitly, not a silent gap.

| `kind` | Source (column = this staff) | Safe fields |
|---|---|---|
| `driver_approved` | `driver_profiles.approved_by_admin_id` | `driver: {public_id,name}`, `approved_at` |
| `driver_document_verified` | `driver_documents.verified_by_admin_id` | `driver: {public_id,name}`, `document_type` |
| `merchant_onboarded` | `merchant_profiles.created_by_admin_id` | `merchant: {public_id,name}` |
| `merchant_approved` | `merchant_profiles.approved_by_admin_id` | `merchant: {public_id,name}`, `approved_at` |
| `setting_updated` | `platform_settings.updated_by_admin_id` | `key` (the **value is omitted** — may be sensitive) |
| `order_abandoned` | `office_inventory.abandoned_by_admin_id` | `order: {public_id}` |

Every row carries `actor: { public_id, name }` (the viewed staff), `kind`, and one sortable
`occurred_at`. Each source contributes only fields at or below the exposure level of the existing
admin resource for that entity. `account_moderation_actions` is the **canonical** moderation source —
`users.suspended_by_admin_id` and `seller_earnings.paid_by_staff_id` are denormalised pointers and are
deliberately **not** queried (they would double-count the audit row / the payout event).

**Merge strategy:** per-source scoped queries (each indexed on its actor column + a date column),
ordered/limited, union-merged and sorted by `occurred_at` in the read service, then paginated. Chosen
over one SQL `UNION ALL` because the shapes differ and clarity matters most for an audit surface;
revisit only if volume demands. Optional `kinds[]` param filters which sources contribute.

### 6.4 `PATCH /admin/users/{user}/notification-preferences`  *(NEW — Slice D)*

FormRequest validates an object of 1–3 booleans (`push`, `sms`, `email`); partial updates allowed,
empty body → 422. Gated by a `UserNotificationPolicy` (admin). Writes the three
`*_notifications_enabled` columns inside `DB::transaction`, emits `Log::info` (actor public_id, target
public_id, changed keys) as a soft trail — **no DB audit** this milestone (§3). Returns the updated
prefs block:

```jsonc
{ "notification_preferences": { "push": true, "sms": false, "email": true } }
```

## 7. Architecture

- `App\Services\Reporting\OverviewMetricsService` (Slice B) and
  `App\Services\Reporting\FinanceReportService` (Slice A) — focused **read** services running
  parameterised aggregate SQL (`SUM`/`COUNT`/`GROUP BY`/`AT TIME ZONE`), returning typed arrays/DTOs.
  No writes, no facades-in-services (constructor DI), no `Model::all()`.
- `App\Services\Reporting\StaffActivityService` (Slice C) — merges the five scoped queries into a
  sorted, paginated timeline.
- `App\Services\User\NotificationPreferenceService` (Slice D) — the single transactional write.
- `App\Support\ReportingTime` (Slice A, merges first) — range boundaries + the `AT TIME ZONE` SQL
  expression, reading `config/reporting.php`.
- JsonResource per payload: `OverviewResource`, `FinanceReportResource`, `StaffActivityResource`
  (+ item resource), `NotificationPreferencesResource`. Query scopes for the reusable range/office
  filters. Policies for the two `{user}`/`{staff}`-bound endpoints.
- Controllers: validate (FormRequest) → call service → return Resource. Nothing else.

## 8. Error handling

- Invalid `range` / unknown `office_id` / empty pref body → **422** via FormRequest.
- Non-admin → **403** (role middleware + policy). Unknown `{user}`/`{staff}` public_id → **404**.
- Empty ranges return zeros / empty arrays, never nulls that crash the frontend.
- Decimal strings throughout for money; never float JSON.

## 9. Slicing & ownership (per WORKFLOW.md §1, §5)

| Slice | Owner | Content | Merge note |
|---|---|---|---|
| **A** | **Claude** | Finance report + `config/reporting.php` + `ReportingTime` helper | Merges **first** (B depends on the timezone helper). |
| **B** | **Codex** | Overview (KPIs + order-activity feed) | Phase-1 stub for `ReportingTime`; **rebase onto A**, swap stub. |
| **C** | **Claude** | Staff-activity timeline — now the **largest** slice (15 source kinds across ~11 tables, money-table actor semantics + the audit-safety contract) | Fully independent (no timezone bucketing); any merge order. |
| **D** | **Codex** | Notification-pref PATCH + policy | Fully isolated; merge any order. |

Rationale: Claude owns the financially-sensitive reads (revenue math, cash reconciliation) and the
staff **audit** surface (correct actor semantics across money tables, dashboard-safe exposure — an
accountability surface, so correctness-critical); Codex owns the mechanical aggregation + the isolated
toggle. Files are disjoint except `ReportingTime`/`config/reporting.php`, which A owns and B rebases
onto. Slice C is the heaviest — if we want finer review granularity, it can split into C1 (append-only
event sources) + C2 (latest-pointer attribution sources) sharing one resource; decided in the plan.

## 10. Verification

- **Pest feature test per endpoint:** admin happy path, authz (non-admin 403), validation (422),
  `public_id`-binding (404).
- **Timezone boundary test:** an order delivered at 23:00 UTC counts in the **next** reporting-tz day
  for `delivered_today` / `daily_trend`.
- **Revenue math test:** assert `accrued`/`by_source`/`by_office` against a hand-built world with
  known snapshots, including a `standard_delivery` order to prove the fee-cut (non-sale) is counted.
- **Revenue status-filter test:** a `delivered` order counts; a `cancelled_by_user` order **and** an
  in-flight (`assigned`/`picked_up`) order with identical non-zero snapshots are **excluded** from
  accrued. Proves the snapshot-at-creation trap is closed.
- **Office attribution test:** revenue attributed via pickup→region→office spatial join through
  **active** regions+service_areas; cash via stored `office_id`; an out-of-region (or inactive-area)
  pickup → `unassigned`.
- **`active_orders` test:** a non-terminal `at_office` order counts; `delivered`/`cancelled_*` do not.
- **Pending-settlements test:** a driver with only `earnings_balance <> 0` (zero cash) is counted.
- **Staff-activity coverage test:** order action by `actor_type='admin'` appears (and one by
  `actor_type='user'` for the same id does **not**); a settlement, payout, moderation action, manual
  adjustment, strike issue/void, merchant onboarding/approval, driver approval, document verification,
  and a settings change all surface in the timeline for their actor.
- **Activity-safety test:** assert no internal ids / order codes / raw phones / setting *values* leak
  in any activity payload; actor is a public_id.
- **Non-refactor proof:** the existing suite stays green with **zero changes to existing test
  expectations**; Pint clean; `composer validate --strict`; `route:list` shows the new routes;
  `migrate:status` unchanged (no migrations).

## 11. Open questions

- None blocking. (Persisted order→office column and a real notification-pref audit are explicitly
  deferred, §3.)
