# Order Lifecycle (Sub-Projects A + B) — Design Spec

**Date:** 2026-05-12
**Status:** ✅ Implemented (2026-05-12) — A+B shipped via Claude Tasks 1–6 + Codex Slices 1–9; Slice 10 also delivered pre-pickup pieces of sub-project C (sender pre-pickup cancel + admin pre-pickup cancel + driver-fault unassign with strike).
**Scope:** End-to-end happy path of an order — from sender creating it (with a server-validated price quote), through driver discovery and atomic claim, through all in-transit transitions, to a delivered terminal state. Includes pricing computation, broadcast & atomic claim, the 6-digit pickup/delivery code system with a kill-switch and geofence fallback, driver presence (going online/offline + location updates), sender retry from `no_driver_available`, and admin manual assign/unassign for early-launch reality where drivers are sparse.

**Out of scope (deliberately deferred to subsequent milestones):**
- General user cancellation from any state other than `no_driver_available` → sub-project **C**
- Cancellation fees, driver strikes for accept-then-cancel → **C**
- Admin-cancel of orders at arbitrary states → **C**
- Failed delivery & return flow (`delivery_failed → returning_to_office → at_office → retrieved_by_seller / abandoned`) → sub-project **D**
- Storage fee accrual → **D**
- Settlement processing endpoints (office staff cash handover) → Next Steps item **#2**
- Seller payout endpoints → Next Steps item **#2**
- Reverb / FCM push notifications (drivers, senders, receivers) → Next Steps item **#3**
- Live SMS provider (Plutu or other) → future production-prep
- Merchant order type (`merchant_delivery`) → sub-project **E** (blocked on merchant onboarding)
- Scheduled / future-dated orders → architecture-ready in schema, UX deferred
- Tips, discounts → architecture-ready, UX deferred
- Admin order endpoints beyond the four here (settings UI, analytics dashboards) → future admin-panel milestone
- Server-side driver "decline" of individual broadcasts → future, if real usage demands

**Predecessors:**
- Auth milestone — `docs/superpowers/specs/2026-05-05-auth-design.md`
- Driver onboarding milestone — `docs/superpowers/specs/2026-05-07-driver-onboarding-design.md`
- Schema groups 1–9 (all `orders`, `order_status_logs`, `driver_*`, `settlements`, `office_*`, `regions`, `service_areas`, `platform_settings` tables already in place)

---

## 1. Goals

1. **End-to-end testable as one connected flow.** A single E2E smoke can drive an order from creation, through driver discovery and claim, through every status transition, to `delivered` with correct financial bucket updates.
2. **Snapshot, never recalculate.** Every fee, rate, and amount captured at creation and frozen — except `delivery_fee_surcharge_percent` and `delivery_fee`, which are explicitly allowed to grow via radius-tier escalation per spec §10.3.
3. **Runtime-tunable pricing & matching.** Every knob (per-region base fee, item-size modifiers, distance pricing, commission, driver cut, broadcast radii, surcharges, escalation thresholds, code enforcement) lives in `platform_settings` or `regions.base_fee`. No deploys needed to retune.
4. **Atomic claim, race-safe.** The driver-claim path uses a conditional UPDATE; only one driver wins; losers receive `409 ORDER_ALREADY_CLAIMED` cleanly.
5. **Code-based handoffs with a global kill-switch.** Pickup and delivery codes are 6-digit numerics, encrypted at rest, visible to sender/receiver but not to driver. Codes can be globally disabled at runtime via two `platform_settings` flags if the system has issues.
6. **Admin can manually assign and unassign** for early-launch reality where drivers are sparse and admin will phone-coordinate.
7. **All financial mutations and status changes audit-trailed.** Every status transition writes one row to `order_status_logs` recording actor, location, reason, and metadata. Driver online/offline events go to a new `driver_presence_logs` table.
8. **API namespaces clean** (`/me/*`, `/driver/*`, `/admin/*`, `/track/*`) so a future split into separate mobile apps costs nothing on the backend.

## 2. Non-Goals

- No "marketplace listing" / product catalog. P2P sale = sender creates a one-off order for a specific buyer they already know (out-of-band).
- No driver "skip broadcast" server state — driver app handles this client-side.
- No real-time push to any actor; clients poll. Real-time lives in Next Steps #3.
- No live SMS — `LogSmsDriver` (dev) sends the guest receiver's notification; production wiring deferred.
- No reservation / scheduled order surfacing — `scheduled_for` column stays NULL.
- No bank transfers or external disbursements. Cash and Bavix wallet only (matches existing payout policy).
- No background fraud detection, no rating system.

## 3. Locked Decisions (from brainstorm 2026-05-12)

| # | Question | Decision |
|---|---|---|
| 1 | Pricing formula | `region.base_fee + item_size_modifier + max(0, distance_km − free_km) × per_km_rate`. **Day-1 defaults reduce to flat per-region fee** (modifiers = 0, free_km = 999, per_km_rate = 0). Distance uses straight-line `ST_Distance` rounded to 1 decimal. |
| 2 | Driver-finds-order mechanism | **Polling endpoint** `GET /api/driver/orders/broadcast` is the source of truth. Push (milestone #3) is purely additive — a doorbell that wakes the app to call the same endpoint. No long-polling. |
| 3 | Radius tier escalation | **Single scheduled task every minute** (`EscalateBroadcastingOrdersJob`) bumps `search_radius_tier` + `delivery_fee_surcharge_percent` + recomputes `delivery_fee`, and flips to `no_driver_available` at 10 min. Tier bumps are **not** logged to `order_status_logs` — the order row holds the final state. The `no_driver_available` flip **is** logged. |
| 4 | Code length, storage, uniqueness | **6 digits**, Laravel `'encrypted'` cast (plaintext on read through model, ciphertext at rest, admin panel sees decrypted), regenerated on the rare same-order pickup/delivery collision. |
| 5 | Order creation API shape | **Quote-then-create**. `POST /orders/quote` returns a signed `quote_token` (HMAC, 5-min TTL via `quote.ttl_seconds`); `POST /orders` requires the token, re-runs pricing server-side, returns `409 QUOTE_PRICE_CHANGED` if base fee changed in the gap. |
| 6 | In-transit transitions | **Hybrid auto + explicit.** `assigned → en_route_pickup` auto-chains in the claim transaction. `en_route_pickup → picked_up` is explicit (driver confirm-pickup with code or geofence). `picked_up → en_route_dropoff` auto-chains. `en_route_dropoff → delivery_in_progress` is explicit (`arrived-dropoff`). `delivery_in_progress → delivered` is explicit (confirm-delivery with code). |
| 7 | Display status (clients) | Internal state machine unchanged (16 states for audit). API resources derive a coarser **`display_status`** for sender + receiver: `awaiting_driver → assigned → picked_up → delivery_in_progress → delivered`. Driver + admin see raw `status`. |
| 8 | Order types in scope | Both **`standard_delivery` and `p2p_sale`**. Merchant deliveries deferred to E. |
| 9 | Driver presence | Three endpoints in this milestone: `go-online`, `go-offline`, `location`. Auto-offline (GPS lost 5 min, idle 30 min) by the same minute cron. |
| 10 | `no_driver_available` recovery | **Sender-side retry** (`POST /me/orders/{id}/retry`, resets to tier 1) + **sender-side free cancel** from this state. Admin retains list/detail/assign/unassign — used when drivers are sparse at launch. |
| 11 | Admin in scope | **List, detail, manual-assign, unassign** (4 endpoints). Manual assign accepts an offline driver; `force=true` softens vehicle and region mismatches; **liability headroom is hard-blocked** even for admin. |
| 12 | Driver decline | **No server-side decline action.** Drivers ignore broadcasts; client-side hides if desired. |
| 13 | Code enforcement kill-switch | **Two `platform_settings` flags** (`codes.enforce_pickup`, `codes.enforce_delivery`, default true). When false, codes are still generated and stored, but the corresponding transition accepts an empty body and records `*_method = bypassed`. **⚠️ Acknowledged conflict with `docs/CLAUDE.md` Critical Rule 9** ("Never mark delivered without code verification"): the kill-switch is an explicit user-requested operational override for early-launch incident response. Default-on preserves Rule 9 in normal operation; off is a documented platform-level exception, fully audited via `delivered_method = bypassed` in `orders` and metadata in `order_status_logs`. CLAUDE.md may be updated to record this exception when the milestone closes. |
| 14 | Failed-attempt logging | Counter (`pickup_code_attempts`, `delivery_code_attempts`) only. **No separate audit row per failed attempt.** Lockout at `codes.max_attempts` (default 5). |
| 15 | Driver presence audit | **New `driver_presence_logs` table** records online/offline events with reason + location. Cleaner than mixing into `driver_locations`. |
| 16 | Tier escalation logging | **Not logged to `order_status_logs`.** Order row's final `search_radius_tier`/`delivery_fee_surcharge_percent`/`delivery_fee` is sufficient. |

## 4. Internal State Machine (Recap)

States touched in this milestone:

```
                  ┌─────────┐
   (POST /orders) │ created │
                  └────┬────┘
                       │ (auto in same txn)
                       ▼
        ┌─────────────────────────────┐
        │      awaiting_driver        │ ◀──── (sender retry, admin unassign)
        └─────────────┬───────────────┘
                      │
       ┌──────────────┼───────────────────────────┐
       │              │                           │
       │   (driver    │ (admin manual assign,     │ (scheduler 10-min timeout)
       │    claims)   │  bypasses claim race)     │
       ▼              ▼                           ▼
  ┌──────────┐  ┌──────────┐            ┌───────────────────────┐
  │ assigned │  │ assigned │            │ no_driver_available   │
  └────┬─────┘  └────┬─────┘            └────────┬──────────────┘
       │ (auto)      │ (auto)                    │
       ▼             ▼                           │
  ┌──────────────────────┐                       │ (sender retry → awaiting_driver)
  │  en_route_pickup     │ ◀── (admin unassign) ─┤
  └────────┬─────────────┘                       │ (sender cancel free)
           │ (explicit: confirm-pickup           ▼
           │  with code OR geofence)        ┌───────────────────┐
           ▼                                │ cancelled_by_user │ (terminal)
  ┌──────────────────────┐                  └───────────────────┘
  │     picked_up        │
  └────────┬─────────────┘
           │ (auto)
           ▼
  ┌──────────────────────┐
  │ en_route_dropoff     │
  └────────┬─────────────┘
           │ (explicit: arrived-dropoff)
           ▼
  ┌──────────────────────┐
  │ delivery_in_progress │
  └────────┬─────────────┘
           │ (explicit: confirm-delivery with code)
           ▼
  ┌──────────────────────┐
  │      delivered       │ (terminal)
  └──────────────────────┘
```

States referenced by `OrderStatus::allowedTransitions()` but *not driven* in this milestone (left for C/D): `cancelled_by_admin`, `delivery_failed`, `returning_to_office`, `at_office`, `retrieved_by_seller`, `abandoned`.

## 5. Pricing Formula & Settings

```
delivery_fee_base = region.base_fee
                  + item_size_modifiers[item_size]
                  + max(0, distance_km − free_km) × per_km_rate

delivery_fee = delivery_fee_base × (1 + delivery_fee_surcharge_percent / 100)
```

For P2P:
```
commission_rate   = pricing.item_commission_rate
commission_amount = round(item_price × commission_rate, 2)
```

Driver cut:
```
driver_fee_cut_rate   = pricing.driver_fee_cut_rate
driver_fee_cut_amount = round(delivery_fee_base × driver_fee_cut_rate, 2)
```

`cashCollectedAtDelivery` (already on the model) = `item_price + (delivery_fee if delivery_fee_payer=receiver AND payment_method=cash else 0)`.

### Settings keys added by this milestone

| Key | Default | Notes |
|---|---|---|
| `pricing.item_size_modifiers` | `{small:0, medium:0, large:0, xlarge:0}` | JSON map; LYD additions |
| `pricing.free_km` | `999` | Distance pricing effectively off at launch |
| `pricing.per_km_rate` | `0` | LYD per km beyond `free_km` |
| `pricing.item_commission_rate` | `0.00` | 0% at launch |
| `pricing.driver_fee_cut_rate` | `0.02` | 2% per spec |
| `broadcast.tier_1_radius_km` | `3` | |
| `broadcast.tier_2_radius_km` | `5` | |
| `broadcast.tier_3_radius_km` | `10` | |
| `broadcast.tier_2_after_minutes` | `3` | |
| `broadcast.tier_3_after_minutes` | `6` | |
| `broadcast.tier_2_surcharge_percent` | `20` | |
| `broadcast.tier_3_surcharge_percent` | `50` | |
| `broadcast.no_driver_after_minutes` | `10` | |
| `pickup.geofence_meters` | `500` | |
| `pickup.dropoff_sanity_meters` | `1000` | Used by `arrived-dropoff` GPS check |
| `codes.max_attempts` | `5` | Shared by pickup + delivery |
| `codes.enforce_pickup` | `true` | Kill-switch |
| `codes.enforce_delivery` | `true` | Kill-switch |
| `driver.location_stale_after_seconds` | `120` | Eligibility freshness filter |
| `driver.idle_offline_after_minutes` | `30` | Auto-offline trigger |
| `driver.gps_lost_offline_after_minutes` | `5` | Auto-offline trigger |
| `quote.ttl_seconds` | `300` | Quote token expiry |

### Schema additions (minimal — two columns + one table)

- **`regions.base_fee`** — `decimal(12,2) NOT NULL DEFAULT 0`. Per-region flat fee. Existing-row backfill via the migration's `down()`/`up()` pair (default 0 is safe; admin seeds real values via Tinker post-deploy).
- **`orders.pickup_geofence_confirmed_at`** — `TIMESTAMP NULL`. Set by sender's confirm-pickup-geofence; consumed by driver's confirm-pickup geofence path within 5 minutes.
- **`driver_presence_logs`** — new table: `id`, `driver_id` (FK users), `event` (`went_online`|`went_offline`|`auto_offline`), `reason` (nullable string), `location` (PostGIS Point nullable), `created_at`. Indexed `(driver_id, created_at)`.

## 6. Endpoint Catalog

**Total: 21 endpoints** across 5 namespaces.

### Sender / Receiver (`/api/orders/*`, `/api/me/*`)

| Method | Path | Auth | Throttle | Purpose |
|---|---|---|---|---|
| POST | `/api/orders/quote` | sanctum | 30/min/user | Get price preview + signed `quote_token` |
| POST | `/api/orders` | sanctum, phone-verified | 10/min/user | Create order from quote |
| GET | `/api/me/orders` | sanctum | 60/min/user | List user's sent + received orders |
| GET | `/api/me/orders/{public_id}` | sanctum, OrderPolicy::view | 60/min/user | Order detail (sender or receiver view) |
| POST | `/api/me/orders/{public_id}/retry` | sanctum, OrderPolicy::retryByUser | 10/min/user | Resume from `no_driver_available` |
| POST | `/api/me/orders/{public_id}/cancel` | sanctum, OrderPolicy::cancelByUser | 10/min/user | Free-cancel from `no_driver_available` only |
| POST | `/api/me/orders/{public_id}/confirm-pickup-geofence` | sanctum, sender, status=en_route_pickup | 5/min/user | Sender confirms driver is at pickup (geofence path) |

### Public Tracking (`/api/track/*`)

| Method | Path | Auth | Throttle | Purpose |
|---|---|---|---|---|
| GET | `/api/track/{tracking_token}` | public | 120/min/IP | Guest receiver tracking page |

### Driver Presence (`/api/driver/*`)

| Method | Path | Auth | Throttle | Purpose |
|---|---|---|---|---|
| POST | `/api/driver/go-online` | sanctum + role:driver | 10/min/driver | Become available for broadcasts |
| POST | `/api/driver/go-offline` | sanctum + role:driver | 10/min/driver | Stop receiving broadcasts |
| POST | `/api/driver/location` | sanctum + role:driver | 120/min/driver | Heartbeat GPS update |

### Driver Order Flow (`/api/driver/orders/*`)

| Method | Path | Auth | Throttle | Purpose |
|---|---|---|---|---|
| GET | `/api/driver/orders/broadcast` | sanctum + role:driver | 30/min/driver | Poll currently-broadcasting candidates |
| POST | `/api/driver/orders/{public_id}/claim` | sanctum + role:driver | 30/min/driver | Atomic claim |
| GET | `/api/driver/orders/current` | sanctum + role:driver | 60/min/driver | Driver's single active order (204 if none) |
| POST | `/api/driver/orders/{public_id}/confirm-pickup` | sanctum + role:driver, OrderPolicy::act | 10/min/driver | Pickup confirmation (code/geofence/bypassed) |
| POST | `/api/driver/orders/{public_id}/arrived-dropoff` | sanctum + role:driver, OrderPolicy::act | 10/min/driver | Driver announces at receiver |
| POST | `/api/driver/orders/{public_id}/confirm-delivery` | sanctum + role:driver, OrderPolicy::act | 10/min/driver | Delivery confirmation (code/bypassed) |

### Admin (`/api/admin/orders/*`)

| Method | Path | Auth | Throttle | Purpose |
|---|---|---|---|---|
| GET | `/api/admin/orders` | sanctum + role:admin | none | List/filter orders for ops triage |
| GET | `/api/admin/orders/{public_id}` | sanctum + role:admin | none | Detail + full status-log trail |
| POST | `/api/admin/orders/{public_id}/assign` | sanctum + role:admin | none | Manual assign to a specific driver |
| POST | `/api/admin/orders/{public_id}/unassign` | sanctum + role:admin | none | Pull driver off order, return to broadcast |

## 7. Service Layer

Following the per-action convention used in the driver onboarding milestone. All financial / state-mutating logic runs inside `DB::transaction(...)`. Controllers stay thin (validate via FormRequest → call service → return resource).

| Service | Responsibility |
|---|---|
| `App\Services\Order\PricingService` | Pure computation. Resolves region, distance, settings; returns the breakdown. No DB writes. |
| `App\Services\Order\QuoteService` | Calls `PricingService`, signs `quote_token` (HMAC-SHA256 of normalised inputs + expiry, secret derived from `APP_KEY`), returns response payload. |
| `App\Services\Order\CreationService` | Validates `quote_token`, re-runs `PricingService`, classifies receiver (registered vs guest), generates codes, snapshots all financial fields, writes initial status log, transitions `created → awaiting_driver`. |
| `App\Services\Order\BroadcastService` | Eligibility query for the polling endpoint. PostGIS `ST_DWithin` + vehicle + liability + region filter, ordered by distance. Slim `BroadcastOrderResource` payload. |
| `App\Services\Order\ClaimService` | Driver-initiated atomic conditional UPDATE. Resolves auto-chain to `en_route_pickup`. Writes two status logs. Flips driver activity to `on_order`. |
| `App\Services\Order\StateTransitionService` | **The sole writer** of `orders.status`. Validates via `OrderStatus::canTransitionTo()`. Sets the status, `status_changed_at`, and the corresponding `*_at` timestamp column. Writes one `order_status_logs` row. Asserts it is running inside a transaction (throws otherwise). Runs registered post-transition hooks (Section 9). **Dispatches `App\Events\OrderStatusChanged($order, $fromStatus, $toStatus, $actor)`** so future listeners (milestone #3 Reverb push, audit consumers) can subscribe without touching transition code. |
| `App\Services\Order\CodeVerificationService` | Pickup and delivery code verification (with attempt counter + lockout). Geofence path validation. Honours the two kill-switch settings. |
| `App\Services\Order\EscalationService` | Used by `EscalateBroadcastingOrdersJob`. Tier bumps (silent column updates) + the `awaiting_driver → no_driver_available` flip (via `StateTransitionService`). |
| `App\Services\Order\RetryService` | Sender's `no_driver_available → awaiting_driver` retry (resets tier 1, surcharge 0, delivery_fee = base, fresh `awaiting_driver_at`). |
| `App\Services\Order\CancellationService` | **Scope-limited to the free `no_driver_available → cancelled_by_user` path.** Sub-project C extends this same class with the fee/strike logic. |
| `App\Services\Order\AdminAssignmentService` | Admin `assign` and `unassign`. Same conditional UPDATE pattern as claim; bypasses the broadcast race; honours `force` flag for soft mismatches; hard-blocks on liability. |
| `App\Services\Driver\PresenceService` | `goOnline()` (with §9.3 checks), `goOffline()` (with active-order guard), `updateLocation()` (overwrite + conditional history insert per 50m/60s rule). Writes `driver_presence_logs` rows. |
| `App\Services\Driver\AutoOfflineService` | Called by the minute cron. Flips drivers offline on GPS-lost or idle thresholds. Writes `driver_presence_logs`. |

### Resources

| Resource | Audience | Sensitive fields |
|---|---|---|
| `OrderResource` | Sender + receiver | Conditionally exposes `pickup_code` (sender), `delivery_code` (receiver). Hides commission for receiver. Hides receiver's PII to receiver themselves. Driver block once status ≥ assigned (sender) or picked_up (receiver). `display_status` collapsed. |
| `DriverOrderResource` | Driver only | Full sender + receiver phone/address. NO codes ever — driver enters what is handed to them. Full raw `status`. |
| `AdminOrderResource` | Admin only | Everything, including raw `status`, full status-log array, driver block, all timestamps, all financial snapshots. |
| `BroadcastOrderResource` | Driver, listing slim view | Pickup + receiver address, distance, fee breakdown, earnings estimate, cash-to-collect. No sender PII beyond first-pass. |
| `GuestTrackingResource` | Public via token | First-name only for sender. Receiver address. `delivery_code` (the receiver needs it). Driver first-name + phone + current_location once status ≥ picked_up. No internal status, no codes from sender side, no commission. |

### Cross-cutting

- **`App\Enums\OrderErrorCode`** — case-list + `httpStatus(): int`. Mirrors `AuthErrorCode`, `DriverErrorCode`.
- **`App\Policies\OrderPolicy`** — `view`, `viewAsSender`, `viewAsReceiver`, `act`, `retryByUser`, `cancelByUser`, `confirmGeofenceBySender`. Registered in `AuthServiceProvider`.
- **`OrderDisplayStatus`** value class — `static fromInternal(OrderStatus $s): string`. Used inside `OrderResource` and `GuestTrackingResource`.
- **Localization scaffolds** — `lang/en/order_messages.php`, `lang/ar/order_messages.php` (controllers emit hardcoded strings; `__()` wiring is a future pass — same pattern as auth).
- **Enums updated:** `PickupMethod` adds `Bypassed = 'bypassed'`; `DeliveryMethod` adds `Bypassed = 'bypassed'`.

## 8. Transaction & Atomicity Discipline

Every mutating endpoint wraps its core logic in `DB::transaction(...)`. Specifically:

- **Order creation** — codes generation + insert + initial status log: one transaction.
- **Driver claim** — atomic UPDATE + driver activity flip + two status logs (assigned, en_route_pickup): one transaction.
- **Admin assign / unassign** — same as claim, plus driver activity changes.
- **Code verifications + state transitions** — transition + post-transition hooks + log: one transaction.
- **Confirm-delivery** — transition + financial side-effects (cash_to_deposit, earnings_balance, debt offset) + activity flip + log: one transaction.
- **Sender retry / cancel** — single transition + log: one transaction.
- **`EscalateBroadcastingOrdersJob`** — each order in its own transaction; poison-pill orders don't poison the tick.

`StateTransitionService::transition()` **does not open its own transaction** — it asserts it is inside one and throws if not. Callers always wrap. This forces explicit composition.

The driver-claim conditional UPDATE is the spec §10.2 pattern:
```sql
UPDATE orders
   SET driver_id = :driver_id,
       status = 'en_route_pickup',
       status_changed_at = NOW(),
       assigned_at = NOW(),
       driver_en_route_pickup_at = NOW(),
       driver_assignment_attempts = driver_assignment_attempts + 1
 WHERE id = :order_id
   AND status = 'awaiting_driver'
   AND driver_id IS NULL
 RETURNING id;
```
Affected-rows = 1 → winner. Affected-rows = 0 → `409 ORDER_ALREADY_CLAIMED`. Admin assign uses the same pattern minus the `driver_id IS NULL` guard, but with `WHERE status IN ('awaiting_driver','no_driver_available')`.

## 9. Post-Transition Hooks

Run inside the same transaction as the status flip. Registered as a `(from, to) → closure` map inside `StateTransitionService`.

| Transition | Hook |
|---|---|
| `created → awaiting_driver` | None (initial broadcast does not need a side-effect) |
| `awaiting_driver → assigned` | None inline — driver activity already set by `ClaimService` / `AdminAssignmentService` before this transition fires |
| `assigned → en_route_pickup` | None (auto-chain, no side-effect) |
| `* → awaiting_driver` (retry / unassign) | None inline — tier reset done by callers; driver activity handled by `AdminAssignmentService` for unassign |
| `awaiting_driver → no_driver_available` | None (sender app polls and sees the new state) |
| `no_driver_available → cancelled_by_user` | None (no driver to debit, no fee) |
| `en_route_pickup → picked_up` | If `delivery_fee_payer = sender AND payment_method = cash` → set `delivery_fee_status = paid`, `delivery_fee_paid_at = NOW()` |
| `picked_up → en_route_dropoff` | None (auto-chain) |
| `en_route_dropoff → delivery_in_progress` | None |
| `delivery_in_progress → delivered` | (a) If `delivery_fee_payer = receiver AND cash` → flip `delivery_fee_status = paid`, `delivery_fee_paid_at = NOW()`<br>(b) Credit driver: `driver_account.cash_to_deposit += cashCollectedAtDelivery()`, `driver_account.earnings_balance += (delivery_fee − driver_fee_cut_amount)`, applying auto-debt-offset (spec §4.7)<br>(c) Flip `driver_profile.activity_status` from `on_order` to `online` |

## 10. Background Workers

### `EscalateBroadcastingOrdersJob` (every minute, via `php artisan schedule:run`)

For each `orders` row where `status = awaiting_driver` (process each in its own transaction):

```
elapsed = now() − awaiting_driver_at

if elapsed >= broadcast.no_driver_after_minutes (10):
    StateTransitionService::transition(order, NoDriverAvailable, actor=system, metadata={reason:"scheduler_timeout"})
elif elapsed >= broadcast.tier_3_after_minutes (6) AND search_radius_tier < 3:
    silent column update: tier=3, surcharge=tier_3_surcharge_percent (50), delivery_fee = base × 1.50
elif elapsed >= broadcast.tier_2_after_minutes (3) AND search_radius_tier < 2:
    silent column update: tier=2, surcharge=tier_2_surcharge_percent (20), delivery_fee = base × 1.20
```

Job wrapped in `Cache::lock('escalate-orders', 90s)->block(...)` to prevent overlap.

### `DriverAutoOfflineJob` (every minute, can share the same cron tick)

For each `driver_profiles` row where `activity_status = online`:

```
gps_age = now() − last_seen_at
idle_age = now() − last_seen_at  (same field, but interpreted distinctly per the spec)

if gps_age >= driver.gps_lost_offline_after_minutes (5):
    flip activity_status to offline, write driver_presence_logs(event=auto_offline, reason="gps_lost")
elif idle_age >= driver.idle_offline_after_minutes (30):
    flip activity_status to offline, write driver_presence_logs(event=auto_offline, reason="idle")
```

If the driver has an `assigned`/`en_route_*`/`picked_up`/`delivery_in_progress` order, **skip** — never auto-offline a driver mid-trip (matches the go-offline guard).

## 11. Authorization (Policies)

`OrderPolicy` abilities:

| Ability | Rule |
|---|---|
| `viewAsSender` | `$user->id === $order->sender_user_id` |
| `viewAsReceiver` | `$user->id === $order->receiver_user_id` |
| `view` | `viewAsSender || viewAsReceiver` |
| `act` | `$user->id === $order->driver_id` AND `$user->hasRole('driver')` |
| `retryByUser` | `viewAsSender` AND `$order->status === OrderStatus::NoDriverAvailable` |
| `cancelByUser` | `viewAsSender` AND `$order->status === OrderStatus::NoDriverAvailable` |
| `confirmGeofenceBySender` | `viewAsSender` AND `$order->status === OrderStatus::EnRoutePickup` |

Admin endpoints rely on the existing `role:admin` middleware alias. Public tracking has no policy — `tracking_token` is the capability.

## 12. Error Contract

Response shape (unchanged from auth/driver milestones):
```json
{
  "error": {
    "code": "ORDER_ALREADY_CLAIMED",
    "message": "This order was claimed by another driver.",
    "details": { "attempts_remaining": 4 }
  }
}
```

`OrderErrorCode` enum cases and HTTP statuses:

| Case | HTTP |
|---|---|
| `PICKUP_OUT_OF_SERVICE_AREA` | 400 |
| `INVALID_QUOTE_TOKEN` | 400 |
| `QUOTE_EXPIRED` | 410 |
| `QUOTE_PRICE_CHANGED` | 409 |
| `SENDER_IS_RECEIVER` | 422 |
| `MERCHANT_USE_MERCHANT_FLOW` | 422 |
| `IDEMPOTENCY_CONFLICT` | 409 |
| `ORDER_ALREADY_CLAIMED` | 409 |
| `ORDER_NOT_RETRYABLE` | 409 |
| `ORDER_NOT_CANCELLABLE_FROM_STATE` | 409 |
| `ORDER_NOT_ASSIGNABLE` | 409 |
| `ORDER_NOT_UNASSIGNABLE` | 409 |
| `ORDER_HAS_NO_DRIVER` | 409 |
| `INVALID_STATE_TRANSITION` | 409 |
| `NOT_YOUR_ORDER` | 403 |
| `INVALID_PICKUP_CODE` | 422 |
| `INVALID_DELIVERY_CODE` | 422 |
| `CODE_LOCKED` | 429 |
| `METHOD_REQUIRED` | 422 |
| `CODE_REQUIRED` | 422 |
| `GEOFENCE_NOT_CONFIRMED` | 409 |
| `DRIVER_NOT_AT_PICKUP` | 409 |
| `DRIVER_NOT_NEAR_DROPOFF` | 409 |
| `DRIVER_NOT_ACTIVE` | 422 |
| `DRIVER_NO_REGIONS` | 422 |
| `DRIVER_GPS_REQUIRED` | 422 |
| `DRIVER_OUT_OF_SERVICE_AREA` | 422 |
| `DRIVER_LIABILITY_MAX` | 409 |
| `DRIVER_LIABILITY_INSUFFICIENT` | 422 |
| `DRIVER_HAS_ACTIVE_ORDER` | 409 |
| `DRIVER_LOCATION_STALE` | 409 |
| `DRIVER_BLOCKED_BY_DEBT` | 409 |
| `VEHICLE_MISMATCH` | 422 |
| `DRIVER_REGION_MISMATCH` | 422 |

## 13. Idempotency (Order Creation)

`POST /orders` honours an optional `Idempotency-Key` header (ULID). On first hit, the response is cached in Redis for 24 h keyed by `{user_id}:{idempotency_key}`. Subsequent POSTs with the same key return the cached response. If the body hash differs from the original (same key, different body) → `409 IDEMPOTENCY_CONFLICT`. No other endpoint needs idempotency in this milestone.

## 14. Audit Logging Discipline

**`order_status_logs`** — written by `StateTransitionService` only. One row per real status change.

Columns always set: `order_id`, `from_status`, `to_status`, `actor_type`, `actor_id` (NULL for system), `created_at`.

Columns set when meaningful: `actor_location` (driver GPS for driver-initiated transitions; sender GPS not stored for sender retries — only timestamp matters), `reason` (free text), `notes` (admin-only future), `metadata` (transition-specific JSON).

**NOT written to this table:** tier escalations (silent column updates), failed code attempts (counter only), location heartbeats (separate table), driver online/offline events (`driver_presence_logs`).

**`driver_presence_logs`** — written by `PresenceService::goOnline/goOffline` and `AutoOfflineService`. One row per online/offline event.

## 15. Testing Plan

Smoke-script-driven verification, same approach as the driver-onboarding milestone. **Conversion to Pest feature tests is part of the separate "test infrastructure" Next Steps item.**

Script: `scripts/orders-e2e.php` (or Tinker macro). Verified scenarios:

1. Sender + driver fixtures set up via existing seeders.
2. Driver goes online with valid GPS; verify `driver_presence_logs` row + `activity_status = online`.
3. Sender requests quote for a `standard_delivery`; verify `delivery_fee_base` math against settings.
4. Sender creates order; verify all snapshots, codes generated, transition to `awaiting_driver` logged.
5. Driver polls `/broadcast`; verify the order appears with correct distance/fee.
6. Driver claims atomically; verify two `order_status_logs` rows (assigned + en_route_pickup), driver `on_order`, order's `driver_id` set.
7. Driver `confirm-pickup` with code; verify `picked_up_method = code`, transitions to `picked_up → en_route_dropoff` (chain), `delivery_fee_status` flipped if sender/cash.
8. Driver `arrived-dropoff`; verify transition to `delivery_in_progress`.
9. Driver `confirm-delivery` with code; verify `delivered`, `driver_account.cash_to_deposit + earnings_balance` updated per formula, driver back to `online`.
10. Repeat with `p2p_sale` (item_price > 0, commission computed, cash_collected_at_delivery includes item_price).
11. **Geofence path**: order created → driver claims → sender confirms via `confirm-pickup-geofence` → driver `confirm-pickup` with `method=geofence` succeeds.
12. **Code lockout**: 5 wrong pickup codes → 6th attempt returns `429 CODE_LOCKED`.
13. **Kill-switch**: `codes.enforce_pickup = false` → driver confirm-pickup with empty body → `picked_up_method = bypassed` audit visible.
14. **No driver**: order created, no driver online → wait simulated 10 min → escalation flips to `no_driver_available` → sender retries → still no driver → sender cancels (free) → terminal.
15. **Tier escalation**: order created, no driver online → wait simulated 3 min → tier 2 (5km, +20% surcharge) → 6 min → tier 3 (10km, +50%). Verify `delivery_fee` recomputed each step.
16. **Admin manual assign**: driver offline → admin assigns → order → en_route_pickup directly → driver opens app → `/orders/current` returns the order → completes happy path.
17. **Admin unassign**: order at `en_route_pickup` → admin unassigns with `reset_tier=true` → back to `awaiting_driver`, tier 1, driver free.
18. **Guest receiver**: order with phone not matching any user → `guest_recipients` row created → `LogSmsDriver` records the SMS → `GET /track/{token}` returns sanitized payload.
19. **Concurrent claim**: two drivers race the same order; only one wins; the other gets `409 ORDER_ALREADY_CLAIMED`. (Simulated via two sequential calls with a forced race window, or two `pcntl_fork`'d processes.)
20. **Quote tampering**: quote returned, server-side admin bumps `pricing.per_km_rate`, POST /orders → `409 QUOTE_PRICE_CHANGED` + fresh quote in response.

Each scenario asserts (a) the resulting `orders` row state, (b) the `order_status_logs` rows, (c) driver state if relevant, (d) financial buckets if applicable.

## 16. Docs Update at Milestone Close

Per `feedback_doc_progress_updates.md`:

- **`docs/SYSTEM_SPECIFICATION.md` §17** — add subsection `17.8 Order lifecycle (A+B) milestone (YYYY-MM-DD) ✅` with endpoint table + locked decisions.
- **`docs/CLAUDE.md`** — `Current Project State` row update + endpoints table for the milestone.

## 17. Open Questions Resolved by This Spec (Recap)

From the `docs/CLAUDE.md` "Open Questions" list:

- ✅ **Order creation UX / form field specifics** — Section 4 of the brainstorm (request body) + Section 6 of this spec (endpoint catalog) lock this down.
- ⏸️ **Cancellation fee amounts** — deferred to sub-project C (this milestone implements only the free `no_driver_available` cancel path).
- ⏸️ **SMS provider** — unchanged from prior milestone status; `LogSmsDriver` covers guest-receiver SMS for now.
- ⏸️ **Rating & review system** — out of scope.
- ⏸️ **Admin panel scope** — only the four order endpoints land here; rest deferred to future admin-panel milestone.

---

**End of design spec.**
