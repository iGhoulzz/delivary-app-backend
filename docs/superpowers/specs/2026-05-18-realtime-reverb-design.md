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
| 5 | **Event payloads:** self-contained via **dedicated broadcast-safe Resources**. Existing actor-aware Resources (`OrderResource`, `DriverProfileResource`) are NOT safe in queued broadcasts because they branch on `$request->user()` which is null off the HTTP request. See §4.0 for the broadcast-resource policy. Driver location is the one exception (tiny custom array) |
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

### 4.0 Broadcast-safe Resource policy (read first)

**Why this section exists:** `OrderResource` and `DriverProfileResource` cannot be used directly in queued broadcasts. `OrderResource` branches on `$request->user()` (lines 24–25) to decide whether to expose pickup notes, receiver phone, codes, commission. In a queued broadcast there's no HTTP request — `$request->user()` is null, so `$isSender` and `$isReceiver` are both false and the payload is wrong. `DriverProfileResource` exposes internal fields (`id`, `user_id`, `office_id`, `vehicle_plate`, `lifetime_deliveries`, etc.) — not safe for sender/receiver consumption.

**Policy:** broadcast events use dedicated, audience-neutral, request-independent Resources. These are created in Phase 1 (foundation work):

- **`App\Http\Resources\Broadcast\OrderForPartiesResource`** — for `private:order.{public_id}`. Audience-neutral (no `$isSender`/`$isReceiver` branching). Includes: `id` (public_id), `order_type`, `status`, `display_status`, pickup address+location, receiver address+location (no phone/name unless explicitly sender-shared), item description+size, pricing summary (delivery_fee, delivery_fee_payer, cash_collected_at_delivery), driver block (first_name, vehicle_type/color, current_location, last_seen_at), timestamps. **Excludes:** pickup notes, receiver phone/name, pickup_code, delivery_code, commission_amount.

- **`App\Http\Resources\Broadcast\DriverForOrderResource`** — for `OrderDriverAssigned`. Includes: first_name, vehicle_type, vehicle_color, current_location, rating_average, lifetime_deliveries. **Excludes:** internal id/user_id/office_id, vehicle_plate, statuses.

- **Existing `GuestTrackingResource`** is already request-independent and safe — reused for `public:track.{token}` order events.

- **Existing `BroadcastOrderResource`** (in `app/Http/Resources/Order/`, for the driver's "available orders" payload) — safe, request-independent — reused for `OrderBroadcastToDriver`.

- **Existing `DriverAccountResource`** — Codex must verify it is request-independent. If it branches on `$request->user()`, create `Broadcast\DriverAccountForBroadcastResource` and use that instead. If it's already safe, use it directly.

If Codex finds an existing Resource needed for a broadcast that turns out to be request-dependent, **stop and raise it with Claude** before adding a new broadcast variant.

### 4.1 Order lifecycle — `private:order.{public_id}` + `public:track.{token}`

| Event | Trigger | Channel | Payload |
|---|---|---|---|
| `OrderStatusChanged` (existing) — add `ShouldBroadcast` + `$afterCommit = true` | Currently dispatched by `StateTransitionService` (line 80) and `ClaimService` (lines 61–62). **NOT yet dispatched by `AdminAssignmentService`** — Codex must add the dispatch in `AdminAssignmentService::assign()` and `::unassign()` as part of task 2.2 (see Phase 2 notes). | `private:order.{public_id}` | `{type, order: OrderForPartiesResource, transition: {from, to, changed_at}}` |
| `OrderStatusChangedPublic` (new) — paired sibling for guest tracking | Dispatched right after `OrderStatusChanged` in the same dispatch sites | `public:track.{tracking_token}` | `{type, order: GuestTrackingResource, transition: {from, to, changed_at}}` |
| `OrderDriverAssigned` (new) | Driver claims OR admin assigns | `private:order.{public_id}` + `public:track.{tracking_token}` | `{type, driver: DriverForOrderResource}` — sender-safe fields only |
| `OrderDriverLocationUpdated` (new, **`ShouldBroadcastNow`**) | Every `POST /api/driver/location` (handled by `PresenceController::location()` → `PresenceService::updateLocation()`) while driver has an active order in `assigned`..`delivery_in_progress` | `private:order.{active_public_id}` + `public:track.{tracking_token}` | `{order_public_id, lat, lng, heading, accuracy, recorded_at}` — tiny array, NOT a Resource |

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
   - `OrderBroadcastToDriver` — wired into `BroadcastService` + `EscalationService`. Reuses existing `BroadcastOrderResource` (already broadcast-safe). `$afterCommit = true`.
   - `OrderBroadcastWithdrawn` — wired into `ClaimService` (on claim), `AdminAssignmentService` (on cancel/reassign), `EscalationService` (when tier escalates past). Simple `{order_public_id, reason}` payload. `$afterCommit = true`.

6. **Broadcast-safe Resources** (per §4.0):
   - `app/Http/Resources/Broadcast/OrderForPartiesResource.php`
   - `app/Http/Resources/Broadcast/DriverForOrderResource.php`
   - Verify `DriverAccountResource` is request-independent. If it branches on `$request->user()`, also create `app/Http/Resources/Broadcast/DriverAccountForBroadcastResource.php`. Otherwise document its safety in Phase 1 PR description.

7. **Ops + dev scripts**
   - Update `composer dev` to launch Reverb alongside server/queue/vite
   - Write `docs/deployment/reverb-supervisor.conf.example`
   - Document the two queue workers (`default`, `broadcasts`)

8. **PR:** `feat(realtime): phase 1 — reverb foundation + driver broadcast events + broadcast-safe resources`
   - **Codex reviews this PR.** Phase 2 begins only after merge.

---

### Phase 2 — Per-event implementations (Codex only)

Once Phase 1 is merged, Codex executes the tasks below. **All Phase 2 tasks are independent** — no shared state, no sequential dependency. Codex can commit them in any order, single commit or multiple, Codex's call.

**Template for each task:**
- Create event class in `app/Events/`
- Implement `ShouldBroadcast` (or `ShouldBroadcastNow` where noted)
- **Transaction-safe dispatch is mandatory.** Every existing dispatch site fires inside `DB::transaction()`. Without protection, clients can receive state that later rolls back. Rules:
  - **For `ShouldBroadcast` (queued) events:** set `public bool $afterCommit = true;` on the event class. Laravel wraps the broadcast in a queued job and defers it until commit. Sufficient.
  - **For `ShouldBroadcastNow` (synchronous) events:** `$afterCommit = true` is **NOT enough** because the broadcast is dispatched synchronously, not queued. Either (a) dispatch the event AFTER `DB::transaction()` returns (preferred), or (b) implement `Illuminate\Contracts\Events\ShouldDispatchAfterCommit` on the event class. See task 2.3 for the concrete pattern.
  - When in doubt, set `$afterCommit = true` AND dispatch outside the transaction.
- `broadcastOn()` returns channel(s) per the events catalog
- `broadcastWith()` builds payload via the broadcast-safe Resource from §4.0 (no Resource that depends on `$request->user()`)
- Wire dispatch site as specified
- Pest unit test asserting `broadcastOn()` channels + `broadcastWith()` shape, plus `$afterCommit === true` assertion

#### Codex's task list

| # | Event | Dispatch site | Channel(s) | Payload |
|---|---|---|---|---|
| 2.1a | Modify existing `OrderStatusChanged` → add `ShouldBroadcast` + `$afterCommit = true`. **Private channel only.** | Already dispatched by `StateTransitionService` (line 80) and `ClaimService` (lines 61–62). Codex does NOT add new dispatch sites for `OrderStatusChanged` — but see 2.2 for the `AdminAssignmentService` gap. | `private:order.{order_public_id}` only | `OrderForPartiesResource` (created in Phase 1) |
| 2.1b | New paired `OrderStatusChangedPublic` event for the guest tracking page. `$afterCommit = true`. | Same dispatch sites as 2.1a — dispatch BOTH events side-by-side. This is the one exception to "no new dispatch sites" because it's a paired broadcast. Use `event(new OrderStatusChanged(...))` then `event(new OrderStatusChangedPublic(...))` in each site. | `public:track.{tracking_token}` only | `GuestTrackingResource` + same `transition` block |
| 2.2 | **Fix pre-existing dispatch gap + new `OrderDriverAssigned`** (one event, two channels, `$afterCommit = true`) | (a) `app/Services/Order/AdminAssignmentService.php::assign()` does NOT currently dispatch `OrderStatusChanged` — Codex must add `event(new OrderStatusChanged(...))` + `event(new OrderStatusChangedPublic(...))` inside the transaction (Laravel's `$afterCommit` defers them). Same for `::unassign()` (transitions to `awaiting_driver`). (b) Then dispatch `OrderDriverAssigned` in `ClaimService::claim()` (after atomic claim success) AND `AdminAssignmentService::assign()` (after assign success). | `private:order.{order_public_id}` + `public:track.{tracking_token}` — one event, two channels | `{driver: DriverForOrderResource}` (created in Phase 1) — sender-safe fields only. NO order payload here; parties already received the status change via 2.1. |
| 2.3 | New `OrderDriverLocationUpdated` — **`ShouldBroadcastNow`**. **Transaction handling:** `PresenceService::updateLocation()` runs inside `DB::transaction()` (verified, line 76). `$afterCommit = true` on its own does NOT protect a `ShouldBroadcastNow` event because the broadcast is synchronous, not queued. **Required pattern:** the transaction returns the updated profile/order context; the `event(new OrderDriverLocationUpdated(...))` call is placed *after* `DB::transaction()` returns, not inside the closure. (Alternative — implementing `Illuminate\Contracts\Events\ShouldDispatchAfterCommit` — is acceptable if Codex prefers it, but Option A is the chosen pattern for this event.) | `app/Services/Driver/PresenceService::updateLocation()` — restructure so the closure returns `[$profile, $activeOrder]`; outside the closure, if `$activeOrder` is non-null and in `assigned`..`delivery_in_progress`, dispatch the event. (Endpoint reached via `app/Http/Controllers/Api/Driver/PresenceController::location()` — but dispatch lives in the service.) | `private:order.{active_order_public_id}` + `public:track.{tracking_token}` | Tiny custom array: `{lat, lng, heading, accuracy, recorded_at}` — NOT a Resource |
| 2.4 | New `DriverAccountUpdated` (`$afterCommit = true`) | Every site that mutates `driver_accounts` balances: `DriverAccountLedgerService`, `CodeVerificationService` (delivery credit), `SettlementService::process()`. `SellerPayoutService` does NOT touch driver_accounts — skip it. | `private:driver.{driver_id}` | `DriverAccountResource` if Phase 1 verified it's request-independent. Otherwise use `Broadcast\DriverAccountForBroadcastResource`. |
| 2.5 | New `NotificationReceived` | Bind a listener to `Illuminate\Notifications\Events\NotificationSent` when channel is `database`. Single listener — no controller changes anywhere. `$afterCommit = true` (safe default; notifications often fire inside transactions). | `private:user.{notifiable_id}` | Inline `{notification: {id, type, data, created_at}}` from the `DatabaseNotification` row |
| 2.6 | New `SellerEarningCleared` (`$afterCommit = true`) | `app/Jobs/ClearSellerEarningsJob.php` (per row flipped, OR batched per seller — Codex picks; document the choice in the event class docblock) | `private:user.{seller_user_id}` | `SellerEarningResource` if request-independent (Codex verifies); else create broadcast variant in Phase 1-style pattern and raise with Claude before merge |

#### Codex must NOT:

- Modify Phase 1 files (Reverb config, `routes/channels.php`, `/broadcasting/auth` registration, `OrderBroadcastToDriver`, `OrderBroadcastWithdrawn`) — those are locked
- Introduce new Resources. If you believe an existing Resource is missing a field, **stop and ask** before adding
- Add new dispatch sites for `OrderStatusChanged` — only modify the existing event class. **Exception:** task 2.2 intentionally adds the missing dispatches in `AdminAssignmentService::assign()` and `::unassign()` to close a pre-existing gap. That's the only place new dispatches are allowed.
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

- A broadcast-safe Resource defined in §4.0 turns out to be missing a field genuinely needed by client UIs (raise before adding — the goal is to keep these resources minimal and audience-neutral, not parity with `OrderResource`)
- Driver has multiple "active" orders at once when computing `OrderDriverLocationUpdated`'s target order (shouldn't be possible by current rules — if data says otherwise, raise it)
- `ClearSellerEarningsJob` per-row vs per-seller batching choice (Codex picks; document the choice you made — no need to raise unless ambiguous)

### 9.1 Already-resolved corrections (do not relitigate)

These were caught in Codex's pre-implementation review and are baked into the spec above:

1. **`OrderResource` and `DriverProfileResource` cannot be used in queued broadcasts** — they branch on `$request->user()` / leak internals. See §4.0 for the broadcast-safe Resource policy and Phase 1 deliverable #6.
2. **`AdminAssignmentService::assign()` and `::unassign()` do NOT currently dispatch `OrderStatusChanged`** — pre-existing gap. Codex fixes this in task 2.2.
3. **Location endpoint is `PresenceController::location()` → `PresenceService::updateLocation()`**, not `LocationController`. Dispatch happens in the service. See task 2.3.
4. **All broadcast events must set `$afterCommit = true`** because all known dispatch sites run inside `DB::transaction()`. See Phase 2 template.
5. **The existing e2e suite is 32 scenarios, not 31.** Spec corrected.

---

## 10. Done criteria

- [ ] Phase 1 PR merged + reviewed by Codex
- [ ] Phase 2 PR merged + reviewed by Claude
- [ ] Phase 3 PR merged (docs + smoke)
- [ ] All Pest tests green
- [ ] `scripts/orders-e2e.php` 32 scenarios still pass (no regressions)
- [ ] `scripts/realtime-smoke.php` passes
- [ ] Pint clean on all touched files
- [ ] Security review pass complete (Claude)
- [ ] `docs/SYSTEM_SPECIFICATION.md` §17.x updated
- [ ] `docs/CLAUDE.md` "Current Project State" updated with milestone ✅
