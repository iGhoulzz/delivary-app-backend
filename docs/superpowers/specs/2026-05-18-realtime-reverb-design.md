# Real-Time (Reverb) Milestone — Design Spec

**Date:** 2026-05-18
**Status:** Approved (awaiting user review of this written spec)
**Owners:** Claude (Phase 1 + Phase 3), Codex (Phase 2)
**Reviewers:** Claude reviews Codex; Codex reviews Claude; both review style on each PR

---

## 0. Context

The Libya delivery + P2P marketplace platform (Laravel 13 + PostgreSQL/PostGIS + Sanctum) currently has all core flows working end-to-end (auth → driver onboarding → order lifecycle → failed delivery + return → settlement + seller payouts, last milestone 2026-05-17). The cash loop is closed; 31 smoke scenarios pass.

What's missing: **real-time push to clients.** Today, clients poll. The system spec (§13.5, §14.4) names Laravel Reverb as the WebSocket layer. This milestone installs Reverb and wires the events the spec describes.

This is a backend-only milestone. Mobile/web clients are not in scope — only the API surface and event contracts they will eventually subscribe to.

---

## 1. Locked decisions (from brainstorming, do not relitigate)

| # | Decision | Rationale |
|---|---|---|
| 1 | **All four spec channels in scope:** `private:user.{id}`, `private:order.{id}`, `private:driver.{id}`, `public:track.{token}` | Full spec coverage in one milestone |
| 2 | **Driver location:** server-side fan-out on existing `POST /api/driver/location`. Driver app stays HTTP-only — does NOT maintain a WebSocket connection | Cellular networks drop persistent sockets constantly; HTTP-up + WS-down is the industry standard for ride-hail/delivery (Uber, DoorDash, Talabat, Careem). Server stays authoritative for dispute audit. |
| 3 | **Driver new-order push:** Reverb push to eligible drivers + keep `GET /api/driver/orders/broadcast` polling endpoint as fallback | Push is the spec; polling stays as resync path after socket reconnect |
| 4 | **Channel auth:** Sanctum bearer token on `/broadcasting/auth`. Reverb itself never sees the token | Sanctum token mode is correct for mobile-first; this is the default Laravel real-time stack |
| 5 | **Event payloads:** self-contained via existing actor-aware Resources. Driver location is the only exception (tiny custom array) | One source of truth (Resource); no double network roundtrip per push; no admin-field leaks because actor-specific Resources already exist (`OrderResource` for sender, `DriverOrderResource` for driver, etc.) |
| 6 | **Queues:** `default` + `broadcasts` only. Location uses `ShouldBroadcastNow` — bypasses queues entirely | Location is ephemeral; next ping supersedes lost ones. Business events go through Redis queue (~100ms is fine). |
| 7 | **Observability:** Supervisor logs + Laravel broadcast failure logs + Reverb `/metrics` endpoint exposed | Lightweight for MVP. No Prometheus/Grafana, no hourly connection logging. |
| 8 | **No admin/office dashboard channel in this milestone** | Their dashboards keep polling or open a specific `private:order.{id}` on detail view. Dashboard-wide admin channel = future milestone. |
| 9 | **No presence channels** | Not needed yet. |

---

## 2. Architecture

```
                  ┌──────────────────────┐
   driver phone ──▶  POST /api/driver/   │
   (HTTP only)      location, etc.       │
                  │                      │      ┌──────────────┐
                  │   Laravel API        ├─────▶│ PostgreSQL + │
                  │   (existing)         │      │ PostGIS      │
                  │                      │      └──────────────┘
                  │   event() dispatch   │
                  │   ────────┐          │
                  └───────────┼──────────┘
                              ▼
                  ┌──────────────────────┐
                  │   Redis (pub/sub)    │
                  └──────────┬───────────┘
                             ▼
                  ┌──────────────────────┐         ┌────────────────────┐
                  │   Laravel Reverb     │◀───WS───│ sender/receiver    │
                  │   (WebSocket server) │         │ apps + guest web   │
                  └──────────────────────┘         │ + driver app for   │
                                                   │ receiving pushes   │
                                                   └────────────────────┘
                             ▲
                             │ /broadcasting/auth (HTTP, Sanctum-protected)
                             │   client → API → "yes/no, here's channel data"
```

- Reverb runs as a separate process: `php artisan reverb:start --host=0.0.0.0 --port=8080`
- Dev: launched by `composer dev` alongside server + queue + vite
- Prod: managed by Supervisor on its own subdomain (`ws.delivary.ly`) behind nginx with TLS
- Laravel app dispatches via `event()`. With `BROADCAST_CONNECTION=reverb` + `QUEUE_CONNECTION=redis`, broadcasts queue → worker picks up → pushed to Reverb via Redis pub/sub → Reverb fans to subscribed sockets

---

## 3. Channels & authorization

| Channel | Subscribers | Auth rule | Payload via |
|---|---|---|---|
| `private:user.{user_id}` | The user themselves | `auth()->id() === (int) $user_id` | inline notification payloads |
| `private:order.{order_public_id}` | Sender + registered receiver of the order | `$user->id === $order->sender_id` OR `$user->id === $order->receiver_user_id` | `OrderResource` (sender-safe) |
| `private:driver.{driver_id}` | Only that driver | `$user->id === (int) $driver_id && $user->hasRole('driver')` | `DriverOrderResource`, `DriverAccountResource`, etc. |
| `public:track.{tracking_token}` | Anyone with the token | Public; token is a 26-char ULID, unguessable | `GuestTrackingResource` + tiny location payloads |

**Important:** `private:order.{...}` uses `public_id` (ULID), NOT internal id. Per Critical Rule 11 (never expose internal id in URLs/responses) — this applies to channel names too. The channel auth callback resolves `Order::where('public_id', $publicId)->firstOrFail()` to get the order.

**Why no admin/office field leaks:** admins and office staff don't subscribe to `private:order.{...}`. They have their own paths (dashboard polling, or future admin channel). So broadcasting `OrderResource` (sender-safe) on the order channel is correct.

---

## 4. Events catalog

### 4.1 Order lifecycle — `private:order.{public_id}` + `public:track.{token}`

| Event | Trigger | Channel | Payload |
|---|---|---|---|
| `OrderStatusChanged` (existing) — add `ShouldBroadcast` | Any status transition logged via `StateTransitionService`, `ClaimService`, `AdminAssignmentService` | `private:order.{public_id}` | `{type, order: OrderResource, transition: {from, to, changed_at}}` |
| `OrderStatusChangedPublic` (new) — paired sibling for guest tracking | Dispatched right after `OrderStatusChanged` in the same three sites | `public:track.{tracking_token}` | `{type, order: GuestTrackingResource, transition: {from, to, changed_at}}` |
| `OrderDriverAssigned` (new) | Driver claims OR admin assigns | `private:order.{public_id}` + `public:track.{tracking_token}` | `{type, driver: DriverProfileResource}` — sender-safe fields only |
| `OrderDriverLocationUpdated` (new, **`ShouldBroadcastNow`**) | Every `POST /api/driver/location` while driver has an active order in `assigned`..`delivery_in_progress` | `private:order.{active_public_id}` + `public:track.{tracking_token}` | `{order_public_id, lat, lng, heading, accuracy, recorded_at}` — tiny array, NOT a Resource |

### 4.2 Driver-side — `private:driver.{driver_id}`

| Event | Trigger | Payload |
|---|---|---|
| `OrderBroadcastToDriver` (new) | New order broadcast OR each tier escalation | `{type, order: BroadcastOrderResource, tier, expires_at}` |
| `OrderBroadcastWithdrawn` (new) | Order claimed by another driver, admin cancelled, or escalated past this driver's eligibility | `{type, order_public_id, reason}` |
| `DriverAccountUpdated` (new) | Bucket balance change (settlement, strike fee, earnings credit) | `{type, account: DriverAccountResource}` |

### 4.3 User-level — `private:user.{user_id}`

| Event | Trigger | Payload |
|---|---|---|
| `NotificationReceived` (new) | Laravel `NotificationSent` event when channel is `database` | `{type, notification: {id, type, data, created_at}}` |
| `SellerEarningCleared` (new) | `ClearSellerEarningsJob` flips `pending_clearance → available` | `{type, earning: SellerEarningResource, new_available_total}` |

---

## 5. Operational concerns

**Queues (MVP shape):**
- `default` — normal jobs
- `broadcasts` — all `ShouldBroadcast` business events
- Location: `ShouldBroadcastNow`, bypasses queues

**Workers in prod (Supervisor):**
- `php artisan queue:work redis --queue=default`
- `php artisan queue:work redis --queue=broadcasts`
- `php artisan reverb:start --host=0.0.0.0 --port=8080`

**Dev script:** update `composer dev` to launch Reverb alongside server + queue + vite.

**Backpressure:** Echo reconnect + Reverb defaults. Location events are ephemeral — dropped pings don't matter; the next ping supersedes.

**Observability:** Supervisor logs + Laravel broadcast failure logs + `/metrics` endpoint exposed. No Prometheus/Grafana, no hourly connection-count logging in this milestone.

---

## 6. Testing strategy

- **Unit (Pest):** each new event's `broadcastOn()` returns expected channels; `broadcastWith()` matches expected payload shape (snapshot-style against Resources). Channel auth callbacks tested directly with mock users (positive + negative).
- **Feature:** use `Event::fake([OrderStatusChanged::class, ...])` to assert dispatch on state transitions (no socket needed).
- **Integration smoke:** `scripts/realtime-smoke.php` boots Reverb, connects a test client, runs a full order lifecycle, asserts received event sequence. Manual gate before merge.
- **No production socket monitoring in this milestone.**

---

## 7. Out of scope (deliberate)

- Push notifications (FCM/APNs) — separate milestone
- Admin/office dashboard channel
- Presence channels (online-driver counts, "who's viewing this order")
- Phone masking via VoIP (deferred per spec §13.7)
- TLS cert provisioning for `ws.delivary.ly` (deploy-time, not code milestone)

---

## 8. Work division: Claude × Codex

> **READ THIS BEFORE STARTING.** This section is the source of truth for who does what. Each phase has a hard gate before the next can start.

### Phase 1 — Foundation (Claude only)

Codex must NOT start Phase 2 until Phase 1 is merged.

**Claude's deliverables:**

1. **Install + configure Reverb**
   - `composer require laravel/reverb`
   - `php artisan reverb:install`
   - `.env` keys: `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `BROADCAST_CONNECTION=reverb`
   - Review + adjust `config/broadcasting.php` and `config/reverb.php`

2. **Broadcasting auth route**
   - Register `Broadcast::routes(['middleware' => ['auth:sanctum']])` in `app/Providers/AppServiceProvider.php` (or a new `BroadcastServiceProvider` if cleaner)
   - Verify `/broadcasting/auth` accepts Sanctum bearer tokens

3. **Channel authorization callbacks** in `routes/channels.php`:
   - `user.{userId}` — `$user->id === (int) $userId`
   - `order.{orderPublicId}` — sender OR receiver of the order resolved by `public_id`
   - `driver.{driverId}` — `$user->id === (int) $driverId && $user->hasRole('driver')`
   - `track.{trackingToken}` — public, no callback

4. **Pest unit tests for channel auth** at `tests/Unit/Broadcasting/ChannelAuthorizationTest.php` (positive + negative per callback).

5. **Two foundation broadcast events:**
   - `OrderBroadcastToDriver` — wired into `BroadcastService` + `EscalationService`
   - `OrderBroadcastWithdrawn` — wired into `ClaimService` (on claim), `AdminAssignmentService` (on cancel/reassign), `EscalationService` (when tier escalates past)

6. **Ops + dev scripts**
   - Update `composer dev` to launch Reverb alongside server/queue/vite
   - Write `docs/deployment/reverb-supervisor.conf.example`
   - Document the two queue workers (`default`, `broadcasts`)

7. **PR:** `feat(realtime): phase 1 — reverb foundation + driver broadcast events`
   - **Codex reviews this PR.** Phase 2 begins only after merge.

---

### Phase 2 — Per-event implementations (Codex only)

Once Phase 1 is merged, Codex executes the tasks below. **All Phase 2 tasks are independent** — no shared state, no sequential dependency. Codex can commit them in any order, single commit or multiple, Codex's call.

**Template for each task:**
- Create event class in `app/Events/`
- Implement `ShouldBroadcast` (or `ShouldBroadcastNow` where noted)
- `broadcastOn()` returns channel(s) per the events catalog
- `broadcastWith()` builds payload via existing Resource (no new Resources unless explicitly needed — see "Codex must NOT" below)
- Wire dispatch site as specified
- Pest unit test asserting `broadcastOn()` channels + `broadcastWith()` shape

#### Codex's task list

| # | Event | Dispatch site | Channel(s) | Payload |
|---|---|---|---|---|
| 2.1a | Modify existing `OrderStatusChanged` → add `ShouldBroadcast`. **Private channel only.** | Already dispatched by `StateTransitionService`, `ClaimService`, `AdminAssignmentService`. DO NOT add new dispatch sites for THIS event. | `private:order.{order_public_id}` only | `OrderResource` |
| 2.1b | New paired `OrderStatusChangedPublic` event for the guest tracking page | Same three sites as 2.1a — dispatch BOTH events side-by-side. This is the one exception to "no new dispatch sites" because it's a paired broadcast. Use `event(new OrderStatusChanged(...))` then `event(new OrderStatusChangedPublic(...))` in each site. | `public:track.{tracking_token}` only | `GuestTrackingResource` + same `transition` block |
| 2.2 | New `OrderDriverAssigned` (one event, payload safe for both audiences) | `app/Services/Order/ClaimService.php` (after atomic claim success) AND `app/Services/Order/AdminAssignmentService.php` (after assign success) | `private:order.{order_public_id}` + `public:track.{tracking_token}` — one event, two channels | `{driver: DriverProfileResource}` only — sender-safe driver fields (name, photo, vehicle, rating). NO order payload here; sender/receiver already received the status change via 2.1. |
| 2.3 | New `OrderDriverLocationUpdated` — **`ShouldBroadcastNow`** | `app/Http/Controllers/Api/Driver/LocationController.php` (after DB write). Find the driver's active order in `assigned`..`delivery_in_progress`; if none, do NOT dispatch. | `private:order.{active_order_public_id}` + `public:track.{tracking_token}` | Tiny custom array: `{lat, lng, heading, accuracy, recorded_at}` — NOT a Resource |
| 2.4 | New `DriverAccountUpdated` | Every site that mutates `driver_accounts` balances: `DriverAccountLedgerService`, `CodeVerificationService` (delivery credit), `SettlementService::process()`. `SellerPayoutService` does NOT touch driver_accounts — skip it. | `private:driver.{driver_id}` | `DriverAccountResource` |
| 2.5 | New `NotificationReceived` | Bind a listener to `Illuminate\Notifications\Events\NotificationSent` when channel is `database`. Single listener — no controller changes anywhere. | `private:user.{notifiable_id}` | Inline `{notification: {id, type, data, created_at}}` from the `DatabaseNotification` row |
| 2.6 | New `SellerEarningCleared` | `app/Jobs/ClearSellerEarningsJob.php` (per row flipped, OR batched per seller — Codex picks; document the choice in the event class docblock) | `private:user.{seller_user_id}` | `SellerEarningResource` + computed `new_available_total` |

#### Codex must NOT:

- Modify Phase 1 files (Reverb config, `routes/channels.php`, `/broadcasting/auth` registration, `OrderBroadcastToDriver`, `OrderBroadcastWithdrawn`) — those are locked
- Introduce new Resources. If you believe an existing Resource is missing a field, **stop and ask** before adding
- Add new dispatch sites for `OrderStatusChanged` — only modify the existing event class
- Persist location events — they are ephemeral by design

#### Codex must:

- Follow `docs/CLAUDE.md` § Code Style exactly: `declare(strict_types=1);`, `final` classes, full type hints, constructor property promotion, no logic in controllers
- Run `vendor/bin/pint` before each commit (PSR-12 + Laravel preset)
- Use Pest for every test: `it('...', fn () => ...)` style
- One service per concern; no static methods except pure utilities / enum factories
- Eager-load relations in `broadcastWith()` payloads; never lazy-load inside a Resource that's being broadcast

**PR:** `feat(realtime): phase 2 — broadcast events`
- **Claude reviews this PR** before Phase 3.

---

### Phase 3 — Integration + docs (Claude only)

After Phase 2 is merged.

1. `scripts/realtime-smoke.php` — boots Reverb, connects a test client, runs a full order lifecycle, asserts received event sequence
2. Update `docs/SYSTEM_SPECIFICATION.md` §17.x with Real-time milestone summary
3. Update `docs/CLAUDE.md` "Current Project State" table + "Next Steps" — bump status, mark milestone ✅
4. Final smoke run via existing `scripts/orders-e2e.php` to verify no regressions

---

### 8.1 Code review responsibilities — explicit

| Concern | Reviewer | When |
|---|---|---|
| Phase 1 architecture, security, channel auth correctness | **Codex** | Before Phase 2 starts |
| Phase 2 contract adherence to this spec (channel names, payload shapes, dispatch sites) | **Claude** | Before Phase 3 |
| **Code style + quality** (`strict_types`, `final`, type hints, Pint, naming, no logic-in-controllers, FormRequests, Resources not raw models, etc.) | **Both reviewers on each other's PR** — style is non-negotiable and either side can block on it | During each review pass |
| Security review (channel auth bypass attempts, payload field leaks, public-channel data exposure) | **Claude** runs the `security-review` skill on the combined branch | Before final merge |
| Final integration sign-off | **Claude** | After Phase 3 docs land |

**Rule:** if either reviewer flags a style violation, the author fixes it. No exceptions. Style ownership is shared.

---

## 9. Open items / questions Codex may raise

If Codex hits any of these while implementing, **stop and ask Claude** rather than guessing:

- Existing Resource is missing a field needed for a broadcast payload (e.g. `OrderResource` doesn't include `display_status` for receiver-side rendering)
- Driver has multiple "active" orders at once (shouldn't be possible by current rules — but if data says otherwise, raise it)
- `ClearSellerEarningsJob` should batch per-seller or per-row (document the choice you made if you decided)
- Any case where the dispatch site requires a transaction-aware dispatch (`afterCommit()`) — if the DB write is inside `DB::transaction()`, the broadcast must use `afterCommit()` or it will fire before subscribers can see the new state

---

## 10. Done criteria

- [ ] Phase 1 PR merged + reviewed by Codex
- [ ] Phase 2 PR merged + reviewed by Claude
- [ ] Phase 3 PR merged (docs + smoke)
- [ ] All Pest tests green
- [ ] `scripts/orders-e2e.php` 31 scenarios still pass (no regressions)
- [ ] `scripts/realtime-smoke.php` passes
- [ ] Pint clean on all touched files
- [ ] Security review pass complete (Claude)
- [ ] `docs/SYSTEM_SPECIFICATION.md` §17.x updated
- [ ] `docs/CLAUDE.md` "Current Project State" updated with milestone ✅
