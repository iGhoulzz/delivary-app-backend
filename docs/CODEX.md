# CODEX.md - Order Lifecycle Review & Handoff

**Date:** 2026-05-12  
**Reviewer:** Codex  
**Scope:** Read the system specification and order lifecycle docs, review the current Laravel implementation left by Claude, and create a practical handoff for continuing the project.

---

## What I Reviewed

- `docs/SYSTEM_SPECIFICATION.md`
- `docs/CLAUDE.md`
- `docs/superpowers/specs/2026-05-12-order-lifecycle-design.md`
- `docs/superpowers/plans/2026-05-12-order-lifecycle.md`
- Current Laravel API routes, order models, enums, resources, quote code, migrations, seeders, and bootstrap wiring.

I also ran:

- `php artisan route:list --path=api`
- `php artisan test`
- `php artisan migrate:status`

`php artisan test` passes the existing 2 tests. `php artisan migrate:status` could not run because local PostgreSQL on `127.0.0.1:5432` is not running.

---

## Project Understanding

This is a Libya-focused delivery and logistics platform using Laravel, PostgreSQL/PostGIS, Sanctum, Spatie Permission, Spatie Media, Redis, and Bavix Wallet for user wallets.

The order lifecycle milestone currently targeted by the docs is "Order Lifecycle A+B": quote, create, driver discovery, atomic claim, pickup/delivery verification, delivery completion, driver financial bucket updates, sender retry/cancel from `no_driver_available`, public guest tracking, admin assign/unassign, driver presence, and scheduled escalation/offline jobs.

The architecture requirement is service-layer driven:

- Controllers validate and delegate.
- Services own domain behavior.
- `StateTransitionService` should be the only writer of `orders.status`.
- All state and financial mutations should run in `DB::transaction`.
- Every real status change should create an `order_status_logs` row.
- Financial values must be snapshotted and not recalculated after order creation, except the explicitly allowed broadcast surcharge changes.

---

## Current Implementation State

The order lifecycle milestone is only partially started.

Implemented pieces:

- `POST /api/orders/quote` route exists.
- `QuoteController`, `QuoteOrderRequest`, `QuoteService`, `PricingService`, `QuoteResource`, and `QuoteToken` exist.
- Order lifecycle settings seeder exists and is called by `DatabaseSeeder`.
- `OrderErrorCode`, order domain exceptions, `OrderPolicy`, `OrderDisplayStatus`, `OrderStatusChanged`, and order resources exist.
- Migrations exist for:
  - `regions.base_fee`
  - `orders.pickup_geofence_confirmed_at`
  - `driver_presence_logs`
- `Order` now casts pickup/delivery codes as encrypted and has `scopeBroadcasting`.
- `DriverPresenceLog` model exists.

Missing from the 21-endpoint milestone:

- Order creation endpoint and `CreationService`.
- Sender order list/detail/retry/cancel/geofence-confirm endpoints.
- Public guest tracking route/controller.
- Driver go-online/go-offline/location endpoints and `PresenceService`.
- Driver broadcast/current/claim endpoints.
- Pickup, arrived-dropoff, delivery confirmation endpoints.
- `StateTransitionService`.
- `CodeVerificationService`.
- `BroadcastService`.
- `ClaimService`.
- `EscalationService`.
- `RetryService`.
- `CancellationService`.
- `AdminAssignmentService`.
- Driver auto-offline service.
- Scheduled jobs and `routes/console.php` scheduling.
- `scripts/orders-e2e.php` smoke script.
- Docs milestone closeout updates.

Route count confirms only one order lifecycle route currently exists: `POST api/orders/quote`.

---

## Review Findings

### 1. Order lifecycle is not ready beyond quote

The docs describe a complete order flow, but the actual code only exposes the quote endpoint. The rest of the system still cannot create or progress orders through the lifecycle.

Impact: frontend or mobile teams can price an order, but cannot create, assign, pick up, deliver, retry, cancel, or track it.

### 2. Database verification is blocked locally

`php artisan migrate:status` failed because PostgreSQL refused the connection on `127.0.0.1:5432`.

Impact: I could not verify whether Claude's latest migrations were applied or whether PostGIS schema changes round-trip correctly in this local environment.

### 3. Quote pricing does not filter inactive regions

`PricingService::resolveRegion()` selects any containing region and does not check `regions.is_active` or the parent service area's active state.

Impact: a quote can be issued for a disabled region if its polygon still contains the pickup point.

Recommended fix when continuing Task 3/4: enforce active service area and active region in the resolver query.

### 4. Quote endpoint has no order creation pairing yet

`QuoteToken` signs the payload and `QuoteService` includes enough data to compare later, but no `CreationService` exists to verify the token, re-run pricing, detect price changes, create guest recipients, generate codes, snapshot money, or move `created -> awaiting_driver`.

Impact: quote correctness is not yet meaningful because there is no consuming endpoint.

### 5. Scheduled lifecycle jobs are not wired

`routes/console.php` only contains Laravel's default `inspire` command. No escalation or auto-offline jobs exist.

Impact: `awaiting_driver` orders will never escalate radius tiers or move to `no_driver_available`; idle drivers will not be auto-offlined.

---

## Verification Results

- `php artisan route:list --path=api`: succeeded and showed 34 API routes total, with only `api/orders/quote` for order lifecycle.
- `php artisan test`: passed, 2 tests / 2 assertions.
- `php artisan migrate:status`: failed due PostgreSQL not running locally.

---

## Recommended Next Build Sequence

1. Bring PostgreSQL/PostGIS up locally and run `php artisan migrate`.
2. Implement `StateTransitionService` first. It is the core dependency for almost every remaining lifecycle action.
3. Implement `CreationService` and `POST /api/orders`.
4. Implement sender list/show plus public guest tracking resources/controllers.
5. Implement driver presence endpoints and `PresenceService`.
6. Implement `BroadcastService` and driver broadcast polling.
7. Implement atomic `ClaimService`.
8. Implement pickup/delivery verification through `CodeVerificationService`.
9. Implement transition post-hooks for delivery-fee status, driver buckets, and driver activity changes.
10. Implement retry/cancel from `no_driver_available`.
11. Implement admin assign/unassign.
12. Implement escalation and auto-offline jobs, then wire scheduling.
13. Add `scripts/orders-e2e.php` and run the 20-scenario smoke test from the plan.
14. Run Pint, update `docs/CLAUDE.md`, update `docs/SYSTEM_SPECIFICATION.md`, and mark the order lifecycle spec implemented only after smoke passes.

---

## Immediate Priority

Do not start with admin endpoints or docs closeout. The next senior-backend move should be to build the state transition core and order creation path, because every other endpoint depends on those contracts.

---

## Codex Implementation Log

### 2026-05-12 Slice 1 - Transition Core + Order Creation

Implemented the first missing order lifecycle slice:

- Added `StateTransitionService` as the transaction-enforced writer for `orders.status`.
- Added one-row-per-transition `order_status_logs` creation.
- Added `OrderStatusChanged` dispatch from the transition service.
- Added `CodeVerificationService::generatePair()` for distinct 6-digit pickup/delivery codes.
- Added `CreationService` to verify quote tokens, re-run pricing, classify registered vs guest receivers, create orders, snapshot financials, create/touch guest recipients, and transition `created -> awaiting_driver`.
- Added `CreateOrderRequest`.
- Added `OrderController` with:
  - `POST /api/orders`
  - `GET /api/me/orders`
  - `GET /api/me/orders/{public_id}`
- Added `orders_create` and `me_orders_read` rate limiters.
- Fixed the state machine to allow `no_driver_available -> cancelled_by_user`, matching the design spec.
- Tightened `PricingService::resolveRegion()` so quotes only resolve active regions inside active service areas.

Files changed:

- `app/Services/Order/StateTransitionService.php`
- `app/Services/Order/CodeVerificationService.php`
- `app/Services/Order/CreationService.php`
- `app/Http/Requests/Order/CreateOrderRequest.php`
- `app/Http/Controllers/Api/Order/OrderController.php`
- `app/Enums/OrderStatus.php`
- `app/Services/Order/PricingService.php`
- `app/Providers/AppServiceProvider.php`
- `routes/api.php`

Verification:

- `php -l` passed for all newly added PHP files.
- `php artisan route:list --path=api/orders` shows `POST api/orders` and `POST api/orders/quote`.
- `php artisan route:list --path=api/me/orders` shows list/show routes.
- `php artisan test` passes: 2 tests / 2 assertions.

Not verified yet:

- Database-backed order creation, because local PostgreSQL is still not running on `127.0.0.1:5432`.

### 2026-05-12 Slice 2 - Public Guest Tracking

Implemented public tracking by tracking token:

- Added `GET /api/track/{trackingToken}`.
- Added `GuestTrackingController`.
- Added `guest_tracking` rate limiter at 120 requests/minute/IP.
- Uses existing `GuestTrackingResource`.

Files changed:

- `app/Http/Controllers/Api/Tracking/GuestTrackingController.php`
- `app/Providers/AppServiceProvider.php`
- `routes/api.php`

Verification:

- `php -l app/Http/Controllers/Api/Tracking/GuestTrackingController.php` passed.
- `php artisan route:list --path=api/track` shows the public route.
- `php artisan test` passes.

### 2026-05-12 Slice 3 - Sender Recovery Actions

Implemented sender-side actions scoped to the A+B milestone:

- `POST /api/me/orders/{public_id}/retry`
- `POST /api/me/orders/{public_id}/cancel`
- `POST /api/me/orders/{public_id}/confirm-pickup-geofence`

Behavior:

- Retry only works from `no_driver_available`, resets tier/surcharge, and transitions back to `awaiting_driver`.
- Cancel only works from `no_driver_available`, is free, and transitions to `cancelled_by_user`.
- Geofence confirmation records `pickup_geofence_confirmed_at` for the driver geofence fallback path.

Files changed:

- `app/Services/Order/RetryService.php`
- `app/Services/Order/CancellationService.php`
- `app/Http/Requests/Order/RetryOrderRequest.php`
- `app/Http/Requests/Order/CancelOrderRequest.php`
- `app/Http/Requests/Order/ConfirmPickupGeofenceRequest.php`
- `app/Http/Controllers/Api/Me/Order/RetryController.php`
- `app/Http/Controllers/Api/Me/Order/CancelController.php`
- `app/Http/Controllers/Api/Me/Order/GeofenceConfirmController.php`
- `app/Providers/AppServiceProvider.php`
- `routes/api.php`

Verification:

- Syntax checks passed for new services/controllers.
- `php artisan route-list --path=api/me/orders` equivalent route check shows the 5 sender order routes.
- `php artisan test` passes.

### 2026-05-12 Slice 4 - Driver Presence

Implemented driver runtime presence:

- `POST /api/driver/go-online`
- `POST /api/driver/go-offline`
- `POST /api/driver/location`

Behavior:

- Going online requires an active driver profile, valid GPS in active service area, no active order, liability headroom, and no driver debt.
- Location updates overwrite `driver_profiles.current_location` and insert history rows using the 50m/60s filter.
- Online/offline events append to `driver_presence_logs`.

Files changed:

- `app/Services/Driver/PresenceService.php`
- `app/Http/Requests/Order/DriverGoOnlineRequest.php`
- `app/Http/Requests/Order/DriverLocationUpdateRequest.php`
- `app/Http/Controllers/Api/Driver/PresenceController.php`
- `app/Providers/AppServiceProvider.php`
- `routes/api.php`

Verification:

- Syntax checks passed.
- `php artisan route:list --path=api/driver` shows the presence routes.
- `php artisan test` passes.

### 2026-05-12 Slice 5 - Driver Broadcast, Current Order, Claim

Implemented driver order polling and claim:

- `GET /api/driver/orders/broadcast`
- `GET /api/driver/orders/current`
- `POST /api/driver/orders/{public_id}/claim`

Behavior:

- Broadcast filters awaiting orders by driver online/stale GPS state, vehicle capability, liability headroom, and distance to pickup for the order's current radius tier.
- Claim uses a conditional update against `awaiting_driver` + `driver_id IS NULL`, then moves the driver to `on_order`.
- Claim writes status-log rows for `awaiting_driver -> assigned` and `assigned -> driver_en_route_pickup`.

Files changed:

- `app/Http/Resources/Order/BroadcastOrderResource.php`
- `app/Services/Order/BroadcastService.php`
- `app/Services/Order/ClaimService.php`
- `app/Http/Controllers/Api/Driver/Order/BroadcastController.php`
- `app/Http/Controllers/Api/Driver/Order/ClaimController.php`
- `routes/api.php`

Note for Claude review:

- `ClaimService` performs the atomic conditional status update directly so it can include `driver_id IS NULL` in the race guard. This is the one intentional exception so far to the "StateTransitionService sole writer" rule and should be reviewed. A future cleanup could add a transition-service method that supports extra guarded predicates.

Verification:

- Syntax checks passed.
- `php artisan route:list --path=api/driver/orders` shows broadcast/current/claim.
- `php artisan test` passes.

### 2026-05-12 Slice 6 - Pickup, Dropoff, Delivery Confirmation

Implemented the driver happy-path transitions:

- `POST /api/driver/orders/{public_id}/confirm-pickup`
- `POST /api/driver/orders/{public_id}/arrived-dropoff`
- `POST /api/driver/orders/{public_id}/confirm-delivery`

Behavior:

- Pickup supports code, geofence fallback, and `codes.enforce_pickup = false` bypass.
- Delivery supports code and `codes.enforce_delivery = false` bypass.
- Code attempts increment on failed code verification and lock after `codes.max_attempts`.
- Pickup auto-chains `picked_up -> driver_en_route_dropoff`.
- Delivery marks the order delivered, sets receiver-paid cash delivery fee status, applies driver cash/earnings/debt-offset bucket changes, and moves the driver back online.
- Sender-paid cash delivery fees are added to `cash_to_deposit` at pickup.

Files changed:

- `app/Services/Order/CodeVerificationService.php`
- `app/Http/Requests/Order/ConfirmPickupRequest.php`
- `app/Http/Requests/Order/ConfirmDeliveryRequest.php`
- `app/Http/Controllers/Api/Driver/Order/ConfirmPickupController.php`
- `app/Http/Controllers/Api/Driver/Order/ArrivedDropoffController.php`
- `app/Http/Controllers/Api/Driver/Order/ConfirmDeliveryController.php`
- `routes/api.php`

Verification:

- Syntax checks passed.
- `php artisan route:list --path=api/driver/orders` shows all 6 driver order routes.
- `php artisan test` passes.

### 2026-05-12 Slice 7 - Admin Order Triage

Implemented admin order operations:

- `GET /api/admin/orders`
- `GET /api/admin/orders/{public_id}`
- `POST /api/admin/orders/{public_id}/assign`
- `POST /api/admin/orders/{public_id}/unassign`

Behavior:

- Admin assign accepts an active driver, optionally bypasses vehicle mismatch with `force=true`, hard-blocks liability overflow, and moves the order to `driver_en_route_pickup`.
- Admin unassign moves `assigned` / `driver_en_route_pickup` orders back to `awaiting_driver`, resets tier/surcharge by default, and frees the driver back to `online`.

Files changed:

- `app/Services/Order/AdminAssignmentService.php`
- `app/Http/Requests/Order/AdminAssignOrderRequest.php`
- `app/Http/Requests/Order/AdminUnassignOrderRequest.php`
- `app/Http/Controllers/Api/Admin/OrderController.php`
- `routes/api.php`

Verification:

- Syntax checks passed.
- `php artisan route:list --path=api/admin/orders` shows all 4 admin order routes.
- `php artisan test` passes.

### Final Checkpoint - 2026-05-12

Final command results after the above slices:

- `vendor/bin/pint`: passed and fixed formatting. It also reformatted several pre-existing project files outside this implementation slice, mostly model/config/migration style fixes.
- `php artisan route:list --path=api`: passed and now shows 54 API routes.
- `php artisan test`: passed, 2 tests / 2 assertions.
- `php artisan migrate:status`: still blocked because local PostgreSQL on `127.0.0.1:5432` is not running.

Remaining major A+B work:

- `EscalationService` and `EscalateBroadcastingOrdersJob`.
- Driver auto-offline service/job.
- Full `scripts/orders-e2e.php` smoke script.
- Database-backed smoke testing once PostgreSQL/PostGIS is available.
- Docs milestone closeout in `docs/CLAUDE.md` and `docs/SYSTEM_SPECIFICATION.md` after DB smoke passes.

### 2026-05-12 Validation Cleanup - FormRequest Consistency

Follow-up after review question: several newly added controllers still used raw `Illuminate\Http\Request` for query-only or no-body endpoints. That technically worked, but it did not match the stricter project convention in `CLAUDE.md` that every endpoint should have a FormRequest.

Changed the new order lifecycle controllers to use FormRequests consistently:

- `ListOrdersRequest` validates `/api/me/orders` query params: `role`, `status`, `per_page`.
- `ShowOrderRequest` is the empty request object for `/api/me/orders/{public_id}`.
- `DriverGoOfflineRequest` validates optional offline `reason`.
- `DriverBroadcastRequest`, `DriverCurrentOrderRequest`, `ClaimOrderRequest`, and `ArrivedDropoffRequest` cover no-body driver actions.
- `AdminListOrdersRequest` validates admin order list filters: `status`, `type`, `per_page`.

Files added:

- `app/Http/Requests/Order/ListOrdersRequest.php`
- `app/Http/Requests/Order/ShowOrderRequest.php`
- `app/Http/Requests/Order/DriverGoOfflineRequest.php`
- `app/Http/Requests/Order/DriverBroadcastRequest.php`
- `app/Http/Requests/Order/DriverCurrentOrderRequest.php`
- `app/Http/Requests/Order/ClaimOrderRequest.php`
- `app/Http/Requests/Order/ArrivedDropoffRequest.php`
- `app/Http/Requests/Order/AdminListOrdersRequest.php`

Files updated:

- `app/Http/Controllers/Api/Order/OrderController.php`
- `app/Http/Controllers/Api/Driver/PresenceController.php`
- `app/Http/Controllers/Api/Driver/Order/BroadcastController.php`
- `app/Http/Controllers/Api/Driver/Order/ClaimController.php`
- `app/Http/Controllers/Api/Driver/Order/ArrivedDropoffController.php`
- `app/Http/Controllers/Api/Admin/OrderController.php`

Verification:

- Syntax checks passed for every `app/Http/Requests/Order/*.php` file.
- Route checks passed for `/api/orders`, `/api/me/orders`, `/api/driver/orders`, and `/api/admin/orders`.
- `vendor/bin/pint` passed on the touched controllers/requests.
- `php artisan test` passes: 2 tests / 2 assertions.

### 2026-05-12 DB Smoke Verification

Docker/PostGIS came back up and DB-backed verification was run.

Migration status:

- `delivery-postgis` Docker container is running and exposes `5432`.
- `php artisan migrate:status` succeeds.
- No pending migrations were reported; all migrations through `2026_05_12_000300_create_driver_presence_logs_table` are marked `Ran`.

Smoke path executed inside a rollback-wrapped Tinker script:

1. Ensured the first region/service area is active and set `regions.base_fee = 10.00`.
2. Created temporary verified sender and driver users.
3. Created temporary active `driver_profile` and `driver_account`.
4. Generated quote with `QuoteService`.
5. Created order with `CreationService`.
6. Driver went online with `PresenceService`.
7. Driver broadcast found the order with `BroadcastService`.
8. Driver claimed the order with `ClaimService`.
9. Driver confirmed pickup by pickup code.
10. Driver location updated to dropoff.
11. Driver marked arrived at dropoff.
12. Driver confirmed delivery by delivery code.
13. Verified final order/driver money state, then rolled back all smoke fixtures.

Smoke result:

```json
{
  "status": "delivered",
  "status_logs": 7,
  "cash_to_deposit": "10.00",
  "earnings_balance": "9.80",
  "driver_activity": "online"
}
```

Other verification rerun:

- `php artisan test`: passed, 2 tests / 2 assertions.
- Route checks for order, sender-order, driver-order, admin-order, and public tracking routes passed.

### 2026-05-12 Slice 8 - Broadcast Escalation + Driver Auto-Offline

Implemented the remaining A+B scheduled background behavior:

- Broadcast tier escalation service/job.
- Driver auto-offline service/job.
- Minute schedule wiring in `routes/console.php`.

Files added:

- `app/Services/Order/EscalationService.php`
- `app/Jobs/EscalateBroadcastingOrdersJob.php`
- `app/Services/Driver/AutoOfflineService.php`
- `app/Jobs/AutoOfflineIdleDriversJob.php`

Files updated:

- `routes/console.php`

Escalation behavior:

- Processes `awaiting_driver` orders.
- At `broadcast.tier_2_after_minutes`, silently sets tier 2, applies tier 2 surcharge, recomputes `delivery_fee`.
- At `broadcast.tier_3_after_minutes`, silently sets tier 3, applies tier 3 surcharge, recomputes `delivery_fee`.
- At `broadcast.no_driver_after_minutes`, transitions to `no_driver_available` through `StateTransitionService`, creating a status log.

Auto-offline behavior:

- Processes online drivers only.
- Skips drivers with active orders.
- If GPS is missing/stale, flips to `offline` and logs `driver_presence_logs.event = auto_offline`, `reason = gps_lost`.
- If idle threshold is hit, flips to `offline` and logs `reason = idle`.

Schedule:

```text
* * * * * orders.escalate-broadcasting
* * * * * drivers.auto-offline-idle
```

DB smoke result:

```json
{
  "tier2": [2, 20, "12.00"],
  "tier3": [3, 50, "15.00"],
  "timeout_status": "no_driver_available",
  "auto_offline_processed": 1,
  "driver_activity": "offline",
  "presence_reason": "gps_lost"
}
```

Verification:

- `php artisan schedule:list`: shows both minute schedules.
- `php artisan schedule:run`: both scheduled callbacks ran successfully.
- `php artisan migrate:status --pending`: no pending migrations.
- `php artisan test`: passed, 2 tests / 2 assertions.
- `vendor/bin/pint` passed/fixed touched files.

### 2026-05-12 Slice 9 - Reusable Order E2E Smoke Script

Added a reusable, rollback-wrapped smoke script for the completed A+B order lifecycle surface.

File added:

- `scripts/orders-e2e.php`

Run command:

```bash
php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"
```

Coverage:

1. Happy path delivery:
   - quote
   - create
   - driver online
   - broadcast
   - claim
   - pickup code
   - arrived dropoff
   - delivery code
   - driver bucket assertions
2. Broadcast escalation:
   - tier 2, +20 percent
   - tier 3, +50 percent
   - timeout to `no_driver_available`
3. Sender recovery:
   - retry from `no_driver_available`
   - free cancel from `no_driver_available`
4. Admin ops:
   - manual assign
   - unassign back to `awaiting_driver`
5. Driver auto-offline:
   - stale GPS flips driver offline
   - `driver_presence_logs` row written

The script creates temporary users, driver profile/account, and orders inside a single transaction and rolls back at the end, so it should not pollute local data.

Smoke result after Pint:

```text
ALL ORDER E2E SMOKE SCENARIOS PASSED
```

Other verification:

- `vendor/bin/pint scripts/orders-e2e.php`: passed/fixed.
- `php artisan test`: passed, 2 tests / 2 assertions.
- `php artisan migrate:status --pending`: no pending migrations.

### 2026-05-12 Slice 10 - Sub-project C Pre-Pickup Cancellation + Driver Fault Strikes

Implemented the pre-pickup cancellation and driver accept-then-cancel support flow. After-pickup sender cancellation stays rejected until the return-to-office flow exists.

Files added:

- `app/Http/Requests/Order/AdminCancelOrderRequest.php`
- `app/Services/Driver/DriverAccountLedgerService.php`

Files updated:

- `app/Services/Order/CancellationService.php`
- `app/Services/Order/AdminAssignmentService.php`
- `app/Http/Controllers/Api/Admin/OrderController.php`
- `app/Http/Controllers/Api/Me/Order/CancelController.php`
- `app/Http/Requests/Order/AdminUnassignOrderRequest.php`
- `app/Policies/OrderPolicy.php`
- `routes/api.php`
- `database/seeders/OrderLifecyclePlatformSettingsSeeder.php`
- `scripts/orders-e2e.php`

Behavior added:

- Sender can cancel from `awaiting_driver` and `no_driver_available` for `0.00`.
- Sender can cancel from `assigned` / `driver_en_route_pickup` with `cancellation.user_pre_pickup_fee`.
- Sender cancellation still rejects after pickup with `ORDER_NOT_CANCELLABLE_FROM_STATE`.
- Admin can cancel pre-pickup operational states through `POST /api/admin/orders/{public_id}/cancel`.
- User/admin cancellations transition through `StateTransitionService` to `cancelled_by_user` / `cancelled_by_admin`.
- Assigned driver is released back to `online` on normal pre-pickup cancellation.
- Admin unassign supports `driver_fault=true`, `notes`, and `fee_amount_override`.
- Driver-fault unassign creates a `driver_strikes` row with `accept_then_cancel`, `issued_by=system`.
- Driver-fault unassign applies the strike fee through `driver_account_transactions` using `strike_fee`, debiting `earnings_balance` first and putting any remainder into `debt_balance`.
- Driver-fault unassign sets the driver `offline` so they must intentionally go online again.
- New platform setting defaults:
  - `cancellation.user_pre_pickup_fee = 0.00`
  - `cancellation.driver_accept_then_cancel_fee = 0.00`

Smoke script coverage added:

1. Sender cancels `awaiting_driver` for free.
2. Sender cancels `driver_en_route_pickup` with configured fee.
3. Admin cancels assigned pre-pickup order and frees driver.
4. Admin unassign with `driver_fault=true` creates strike and ledger transaction.
5. After-pickup sender cancel is rejected.

Verification:

- `php -l` on touched PHP files: passed.
- `vendor/bin/pint ...`: passed/fixed.
- `php artisan route:list --path=api/admin/orders`: new admin cancel route present.
- `php artisan route:list --path=api/me/orders`: sender cancel route still present.
- `php artisan migrate:status --pending`: no pending migrations.
- `php artisan test`: passed, 2 tests / 2 assertions.
- `php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"`: passed all 9 rollback-wrapped scenarios.

### 2026-05-13 Slice 11 - Failed Delivery + Return-to-Office Flow

Implemented sub-project D per `docs/superpowers/specs/2026-05-13-failed-delivery-and-return-flow-design.md` and `docs/superpowers/plans/2026-05-13-failed-delivery-and-return-flow.md`.

Files added:

- `database/migrations/2026_05_13_000100_add_retrieval_columns_to_office_inventory_table.php`
- `app/Services/Order/FailedDeliveryService.php`
- `app/Services/Order/ReturnOfficeResolver.php`
- `app/Services/Order/StorageFeeCalculator.php`
- `app/Http/Requests/Order/*` D FormRequests
- `app/Http/Resources/Order/OfficeInventoryResource.php`
- `app/Http/Resources/Order/OfficeOrderResource.php`
- `app/Http/Controllers/Api/Driver/Order/MarkDeliveryFailedController.php`
- `app/Http/Controllers/Api/Office/Order/*`
- `app/Jobs/AbandonStaleOrdersJob.php`

Files updated:

- `app/Enums/OrderStatus.php`
- `app/Enums/OrderErrorCode.php`
- `app/Models/OfficeInventory.php`
- `app/Models/Order.php`
- `app/Services/Driver/DriverAccountLedgerService.php`
- `app/Services/Order/CodeVerificationService.php`
- `app/Policies/OrderPolicy.php`
- `app/Http/Resources/Order/OrderResource.php`
- `app/Http/Controllers/Api/Admin/OrderController.php`
- `app/Providers/AppServiceProvider.php`
- `routes/api.php`
- `routes/console.php`
- `database/seeders/OrderLifecyclePlatformSettingsSeeder.php`
- `lang/en/order_messages.php`
- `lang/ar/order_messages.php`
- `scripts/orders-e2e.php`
- `docs/CLAUDE.md`
- `docs/SYSTEM_SPECIFICATION.md`
- `docs/superpowers/specs/2026-05-13-failed-delivery-and-return-flow-design.md`

Behavior added:

- Driver/admin can mark post-pickup orders failed from `picked_up`, `driver_en_route_dropoff`, or `delivery_in_progress`.
- Failed orders auto-chain `delivery_failed -> returning_to_office`.
- Return office resolves from pickup region office, falling back to nearest active office.
- Office staff can list/show office-bound returns, receive returns, and retrieve orders with exact cash validation.
- Driver earnings credit moved into `DriverAccountLedgerService::applyDeliveryCompletionCredit()` and is reused by happy-path delivery and office return receipt.
- Driver/platform fault failures do not credit driver earnings at return receipt.
- Admin can redirect return office mid-return and waive retrieval fees while at office.
- Daily `AbandonStaleOrdersJob` flips stale `at_office` orders to `abandoned`.
- Added `Order::activeForDriver()` so returned-at-office orders do not block driver availability while still preserving `driver_id` for history.

Verification:

- `php artisan migrate`: migrated retrieval columns.
- `php artisan db:seed --class=OrderLifecyclePlatformSettingsSeeder`: inserted storage defaults.
- Schema/settings/error-code tinker checks: passed.
- `php artisan route:list --path=api`: all 8 D routes present.
- `php artisan schedule:list`: `orders.abandon-stale` daily schedule present.
- `php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"`: passed all 17 rollback-wrapped scenarios.
- `php artisan migrate:status --pending`: no pending migrations.
- `php artisan test`: passed, 2 tests / 2 assertions.
- `vendor/bin/pint`: passed/fixed touched files.

### 2026-05-17 Slice 12 - Settlement + Seller Payouts Codex Tasks

Implemented the Codex-owned mechanical slice from `docs/superpowers/specs/2026-05-17-settlement-and-seller-payouts-design.md` and `docs/superpowers/plans/2026-05-17-settlement-and-seller-payouts.md`. I intentionally did not touch Claude-owned settlement services, payout services, policies, office/admin mutation controllers, or e2e settlement scenarios.

Files added:

- `database/migrations/2026_05_17_000100_create_seller_earnings_table.php`
- `database/migrations/2026_05_17_000200_simplify_seller_payouts_table.php`
- `database/migrations/2026_05_17_000300_create_seller_payout_orders_table.php`
- `app/Enums/SellerEarningStatus.php`
- `app/Enums/SettlementErrorCode.php`
- `app/Models/SellerEarning.php`
- `app/Models/SellerPayoutOrder.php`
- `app/Jobs/ClearSellerEarningsJob.php`
- `app/Http/Requests/Settlement/*`
- `app/Http/Resources/Settlement/*`
- `app/Http/Controllers/Api/Driver/Settlement/*`
- `app/Http/Controllers/Api/Me/Settlement/*`
- `lang/en/settlement_messages.php`
- `lang/ar/settlement_messages.php`

Files updated:

- `app/Enums/SellerPayoutStatus.php`
- `app/Models/SellerPayout.php`
- `app/Models/Order.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `database/seeders/OrderLifecyclePlatformSettingsSeeder.php`
- `routes/api.php`
- `routes/console.php`

Behavior added:

- Added `seller_earnings` lifecycle table and `seller_payout_orders` pivot.
- Simplified `seller_payouts` away from request/approval fields into cash-handover receipt shape with `paid_by_staff_id`.
- Added seller earning/payout models and `Order::sellerEarning()`, `User::sellerEarnings()`.
- Added daily `ClearSellerEarningsJob` that advances eligible `pending_clearance` earnings to `available`.
- Added seller read endpoints:
  - `GET /api/me/earnings`
  - `GET /api/me/seller-payouts`
  - `GET /api/me/seller-payouts/{public_id}`
- Added driver settlement history endpoints:
  - `GET /api/driver/settlements`
  - `GET /api/driver/settlements/{public_id}`
- Added settlement/payout FormRequests, JsonResources, rate limiters, platform settings, and localization files.

Verification:

- `php -l` on touched PHP files: passed.
- `php artisan migrate`: applied all three 2026-05-17 migrations.
- `php artisan db:seed --class=OrderLifecyclePlatformSettingsSeeder`: passed.
- Schema tinker checks: `seller_earnings` and `seller_payout_orders` exist; `seller_payouts` now has `paid_by_staff_id`.
- Settings tinker checks: payout settings resolve.
- `php artisan schedule:list`: `seller-earnings.clearance` daily schedule present.
- `php artisan route:list --path=driver/settlements`: 2 routes present.
- `php artisan route:list --path=me/earnings`: 1 route present.
- `php artisan route:list --path=me/seller-payouts`: 2 routes present.
- `php artisan migrate:status --pending`: no pending migrations.
- `php artisan test`: passed, 2 tests / 2 assertions.
- `php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"`: passed all 17 existing rollback-wrapped order scenarios.
- `vendor/bin/pint`: passed/fixed touched files.


---

## Settlement & Seller Payouts milestone (2026-05-17) — Claude completed remaining tasks

After Codex shipped Tasks 1–7 + 12, 14–17, 21–22 of the settlement plan, Claude implemented the remaining tasks in the same session via direct edits + a smoke-driven verification loop. Summary of Claude's deliveries:

- **Task 8** (policies + helper): extracted `User::isAssignedToOffice(int): bool`; refactored `OrderPolicy::orderInUsersOffice` to delegate; added `SettlementPolicy`, `SellerPayoutPolicy`, `SellerEarningPolicy` with documented seller-side authorisation asymmetry (no Spatie seller role — FK ownership is the gate).
- **Task 9** (`SettlementService`): atomic preview + process; locks `driver_accounts`; rejects empty buckets + excess; pushes shortage to `debt_balance`; flips `pending_settlement` earnings to `pending_clearance` + writes `settlement_orders` pivot. New: `SettlementExcessException`, `EmptySettlementException`, `App\ValueObjects\SettlementPreview`.
- **Task 10** (`SellerPayoutService`): atomic lookup + process; locks selected earnings; validates ownership, status, payout-link absence, server-recomputed total, min amount; flips earnings → `paid_out` + writes `seller_payout_orders` pivot. New: `PayoutValidationException`.
- **Task 11** (`SettlementReversalService`): admin correcting-settlement pattern; locks original settlement + earnings + driver_account; allowed only while every contributing earning is still `pending_clearance`; writes opposite-direction settlement row; flips earnings back to `pending_settlement`; clamps debt_balance ≥ 0 per Critical Rule 5. New: `SettlementNotReversibleException`.
- **Task 13** (`CodeVerificationService` integration): added private `spawnSellerEarning(Order)` invoked in the existing `confirmDelivery` DB transaction after `applyDriverDeliveryFinancials`; gates on `OrderType::P2pSale|MerchantDelivery` + non-zero `item_price`; computes `item_price - commission_amount`.
- **Tasks 18–20** (write controllers): 10 controllers across office settlement, office seller-payout, admin settlement + payout (incl. reversal). Routes wired under `/api/office` and `/api/admin` groups with `throttle:office_settlement` / `throttle:office_payout` middleware. All 15 settlement endpoints visible in `php artisan route:list`.
- **Task 23** (smoke): 14 new rollback-wrapped scenarios in `scripts/orders-e2e.php` (total now 31). Covers happy match, empty/excess/shortage/zero-net settlements, sale-order earning flips, clearance cron (eligible + ineligible), payout happy + partial + mismatch + below-minimum, reversal happy + blocked-once-past-clearance.
- **Task 24** (docs): updated `docs/CLAUDE.md` Current Project State + endpoint table; added `docs/SYSTEM_SPECIFICATION.md` §17.11; this entry in `docs/CODEX.md`.

**Bug fixed during smoke:** `PayoutValidationException` + `SettlementNotReversibleException` initially had `public readonly SettlementErrorCode $code` constructor params, which shadowed `RuntimeException::$code` and crashed at construction. Renamed to `$errorCode` with matching accessor.

**Final verification:** `php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"` → all 31 rollback-wrapped scenarios pass.

---

## 2026-05-20 Slice 13 - Realtime Reverb Phase 2 Broadcast Events

Reviewed Claude's Phase 1 work from `docs/superpowers/specs/2026-05-18-realtime-reverb-design.md` before starting Codex Phase 2.

Phase 1 confirmation:

- `laravel/reverb` is installed.
- `config/broadcasting.php` and `config/reverb.php` exist.
- `/broadcasting/auth` is registered through `bootstrap/app.php -> withBroadcasting(..., ['middleware' => ['auth:sanctum']])`.
- `routes/channels.php` has `user.{id}`, `order.{public_id}`, and `driver.{id}` authorization callbacks.
- Broadcast-safe resources exist: `OrderForPartiesResource` and `DriverForOrderResource`.
- Claude-owned driver broadcast events exist: `OrderBroadcastToDriver` and `OrderBroadcastWithdrawn`.
- Phase 1 broadcast/channel tests pass.

Phase 1 review note for Claude:

- `OrderBroadcastToDriver::broadcastWith()` currently includes `type`, `tier`, and `order`, but does not include `expires_at`, even though the spec event catalog lists `{type, order, tier, expires_at}`. This did not block Codex Phase 2 because no Codex task depends on that field.
- `OrderForPartiesResource` currently includes `driver.vehicle_type` but not `driver.vehicle_color`, while the Phase 1 broadcast-safe resource policy lists vehicle type/color in the driver block. This also did not block Codex Phase 2.

Codex Phase 2 implementation:

- Converted existing `OrderStatusChanged` into a queued `ShouldBroadcast` event on `private:order.{public_id}` with `OrderForPartiesResource`, `transition`, `$afterCommit = true`, and `broadcastQueue() = broadcasts`.
- Added `OrderStatusChangedPublic` for `public:track.{tracking_token}` with `GuestTrackingResource`.
- Added paired public status dispatches in `StateTransitionService` and `ClaimService`.
- Closed the pre-existing `AdminAssignmentService` dispatch gap by dispatching private + public status events from `assign()` and `unassign()`.
- Added `OrderDriverAssigned` for driver claim/admin assign, broadcasting sender-safe driver details through `DriverForOrderResource`.
- Added `OrderDriverLocationUpdated` as `ShouldBroadcastNow`, dispatched after `PresenceService::updateLocation()` commits, not inside the DB transaction.
- Added `DriverAccountUpdated` and wired driver-account mutation sites: `DriverAccountLedgerService`, `CodeVerificationService`, `SettlementService::process()`, and `SettlementReversalService::reverse()`.
- Added `NotificationReceived` and `BroadcastDatabaseNotification` listener for Laravel database notifications.
- Added `SellerEarningCleared` from `ClearSellerEarningsJob`, one event per earning advanced to `available`.

Files added:

- `app/Events/OrderStatusChangedPublic.php`
- `app/Events/OrderDriverAssigned.php`
- `app/Events/OrderDriverLocationUpdated.php`
- `app/Events/DriverAccountUpdated.php`
- `app/Events/NotificationReceived.php`
- `app/Events/SellerEarningCleared.php`
- `app/Listeners/BroadcastDatabaseNotification.php`
- `tests/Unit/Events/RealtimePhase2EventsTest.php`
- `tests/Unit/Listeners/BroadcastDatabaseNotificationTest.php`
- `tests/Feature/Realtime/RealtimePhase2DispatchTest.php`

Files updated:

- `app/Events/OrderStatusChanged.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Order/StateTransitionService.php`
- `app/Services/Order/ClaimService.php`
- `app/Services/Order/AdminAssignmentService.php`
- `app/Services/Driver/PresenceService.php`
- `app/Services/Driver/DriverAccountLedgerService.php`
- `app/Services/Order/CodeVerificationService.php`
- `app/Services/Settlement/SettlementService.php`
- `app/Services/Settlement/SettlementReversalService.php`
- `app/Jobs/ClearSellerEarningsJob.php`

Verification:

- `php artisan test tests/Feature/Broadcasting tests/Unit/Broadcasting tests/Feature/Realtime tests/Unit/Events tests/Unit/Listeners tests/Unit/Resources/Broadcast`: passed, 37 tests / 151 assertions.
- `php artisan test`: passed, 42 tests / 159 assertions.
- `php artisan migrate:status --pending`: no pending migrations.
- `php artisan route:list --path=broadcasting`: `/broadcasting/auth` present.
- `vendor/bin/pint ...`: passed/fixed touched files.
- `php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"` initially failed because `BROADCAST_CONNECTION=reverb` tried to connect to local Reverb on port 8080 and Reverb was not running.
- `$env:BROADCAST_CONNECTION='null'; php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"` passed all 32 rollback-wrapped scenarios.

---

## 2026-05-29 Slice 14 - Staff CRUD Slice B Phase 1 Office Assignments

Worked in `C:\Users\User\Desktop\delivary-app-codex` on branch `codex/staff-crud-office-assignments`.

Scope implemented from `docs/superpowers/plans/2026-05-20-staff-crud-slice-b-codex.md`, Phase 1 only. Phase 2 is intentionally blocked until Claude's Slice A staff CRUD work merges to main.

Files added or updated:

- `database/migrations/2026_05_20_000010_add_public_id_to_office_staff_assignments.php`
- `app/Models/OfficeStaffAssignment.php`
- `app/Services/Staff/OfficeAssignmentService.php`
- `app/Http/Requests/Staff/AttachOfficeAssignmentRequest.php`
- `app/Http/Resources/Staff/OfficeAssignmentResource.php`
- `app/Http/Controllers/Api/Admin/Staff/OfficeAssignmentController.php`
- `tests/Unit/Services/Staff/OfficeAssignmentServiceTest.php`
- `tests/Feature/Staff/OfficeAssignmentControllerTest.php`
- `scripts/staff-e2e.php`

Implementation details:

- Added `office_staff_assignments.public_id` migration with ULID backfill, unique public id, removal of the old `user_id + office_id` unique constraint, and a partial unique index on active assignments only.
- Updated `OfficeStaffAssignment` with `public_id` fillable support, ULID generation, and route binding by `public_id`.
- Added `OfficeAssignmentService` with Phase 1 `RuntimeException` stubs for:
  - `ROLE_MISMATCH_FOR_OFFICE_ASSIGN`
  - `OFFICE_ASSIGNMENT_DUPLICATE`
  - `OFFICE_ASSIGNMENT_LAST_REQUIRED`
- Added attach, detach, and attachMany behavior. Detach soft-removes rows and refuses removal of the last active assignment.
- Added duplicate-office validation for `attachMany()` before writing rows.
- Added FormRequest, Resource, and controller scaffolding, but did not wire routes because routes belong to Phase 2 after Slice A merges.
- Added controller feature tests and marked them skipped until Slice A route integration.
- Added `scripts/staff-e2e.php` scaffold for Phase 2 verification.

Test/verification notes:

- `php -l` passed for all new and modified Slice B files.
- `vendor/bin/pint.bat ...` passed after formatting.
- `php artisan test tests\Unit\Services\Staff\OfficeAssignmentServiceTest.php` passed: 9 tests / 22 assertions.
- `php artisan test tests\Feature\Staff\OfficeAssignmentControllerTest.php` passed with 6 skipped tests, expected until Phase 2 route wiring.
- `php artisan test` passed: 59 tests, 53 passed, 6 skipped, 186 assertions.
- `php artisan migrate:status --pending` shows `2026_05_20_000010_add_public_id_to_office_staff_assignments` pending in the local app database. The migration was exercised by the test database through `RefreshDatabase`.
- `$env:BROADCAST_CONNECTION='null'; php artisan tinker --execute="require base_path('scripts/staff-e2e.php');"` currently fails with `Target class [App\Services\Staff\StaffService] does not exist`, expected in Phase 1 because Slice A has not merged.

Next step after Claude Slice A merges:

- Rebase this branch on main.
- Add the three Slice B cases to `StaffErrorCode`.
- Swap `RuntimeException` stubs to `StaffDomainException`.
- Wire `OfficeAssignmentService::attachMany()` into `StaffService::create()`.
- Wire deactivate assignment soft-removal.
- Add the two routes and replace skipped route tests with active assertions.
- Re-run full Pest, staff e2e, and orders e2e.

---

## 2026-05-29 Slice 15 - Staff CRUD Slice B Phase 2 Integration

Claude's Slice A was merged to `main`, then Codex rebased `codex/staff-crud-office-assignments` onto `origin/main` cleanly.

Phase 2 implementation:

- Added Slice B cases to `StaffErrorCode`:
  - `ROLE_MISMATCH_FOR_OFFICE_ASSIGN`
  - `OFFICE_ASSIGNMENT_DUPLICATE`
  - `OFFICE_ASSIGNMENT_LAST_REQUIRED`
- Replaced `OfficeAssignmentService` Phase 1 `RuntimeException` stubs with `StaffDomainException`.
- Removed controller-level stub error rendering from `OfficeAssignmentController`; global `StaffDomainException` rendering now owns the response shape.
- Widened `CreateStaffRequest` to allow `role=office_staff` with required `office_assignments[]`, while keeping admin creation from sending assignments.
- Wired `OfficeAssignmentService::attachMany()` into `StaffService::create()` for office staff creation.
- Wired `StaffService::deactivate()` to soft-remove all active office assignments.
- Replaced `StaffResource` inline assignment mapping with `OfficeAssignmentResource::collection(...)`.
- Updated `StaffController` eager loading to `activeOfficeAssignments.office`.
- Added the two office-assignment routes inside Claude's `admin.staff.*` group.
- Activated the previously skipped office-assignment feature tests.
- Added a StaffService create test for office staff + assignment creation.
- Updated `scripts/staff-e2e.php` to assert deactivate removes assignments and to make the last-admin scenario independent from existing local DB admin rows.

Verification:

- `php artisan route:list --path=admin/staff`: 10 routes present, including `admin.staff.office-assignments.store` and `admin.staff.office-assignments.destroy`.
- `php artisan test tests\Unit\Services\Staff\OfficeAssignmentServiceTest.php tests\Unit\Services\Staff\StaffServiceCreateTest.php tests\Feature\Staff\OfficeAssignmentControllerTest.php`: passed, 17 tests / 49 assertions.
- `php artisan test tests\Feature\Staff tests\Unit\Services\Staff tests\Unit\Middleware\EnsurePasswordChangedMiddlewareTest.php tests\Unit\Policies\StaffPolicyTest.php tests\Feature\Auth\LoginSuspendedRejectionTest.php`: passed, 47 tests / 147 assertions.
- `php artisan migrate`: applied `2026_05_20_000010_add_public_id_to_office_staff_assignments`.
- `php artisan migrate:status --pending`: no pending migrations.
- `$env:BROADCAST_CONNECTION='null'; php artisan tinker --execute="require base_path('scripts/staff-e2e.php');"`: all 6 staff smoke scenarios passed.
- `$env:BROADCAST_CONNECTION='null'; php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"`: all 32 order smoke scenarios passed.
- `vendor/bin/pint.bat ...`: fixed formatting in `OfficeAssignmentService`.
- Final `php artisan test`: passed, 91 tests / 311 assertions.

Review note for Claude:

- Confirm the `StaffResource` assignment shape is acceptable now that it delegates to `OfficeAssignmentResource` and exposes assignment/office public ids.
- Confirm `StaffService::deactivate()` should remain a direct bulk update for active assignments rather than calling `OfficeAssignmentService::detach()` repeatedly. This intentionally bypasses the last-assignment guard because deactivate is the supported way to remove all assignments.

---

## 2026-05-31 Slice 16 - Staff CRUD Slice B Review Fix

Claude reviewed Slice B and found a blocking assignment-lifecycle inversion in `StaffService`: the office-assignment soft-removal query had been added to `suspend()` instead of `deactivate()`.

Fix applied:

- Removed office-assignment soft-removal from `StaffService::suspend()`.
- Added office-assignment soft-removal to `StaffService::deactivate()`.
- Updated `scripts/staff-e2e.php` Scenario 4 to assert suspend preserves assignments.
- Updated `scripts/staff-e2e.php` Scenario 5 to assert reinstate preserves assignments and deactivate removes them.
- Added a `StaffServiceLifecycleTest` regression case covering suspend preservation and deactivate cleanup.

Optional review note:

- Kept nested `CreateStaffRequest.office_assignments.*.is_manager` required while standalone `AttachOfficeAssignmentRequest.is_manager` remains optional with default `false`. This is intentional: bulk account creation requires an explicit assignment payload; the attach endpoint spec explicitly defines a default.

Verification:

- `php -l app/Services/Staff/StaffService.php scripts/staff-e2e.php tests/Unit/Services/Staff/StaffServiceLifecycleTest.php`: passed.
- `vendor/bin/pint.bat app/Services/Staff/StaffService.php scripts/staff-e2e.php tests/Unit/Services/Staff/StaffServiceLifecycleTest.php`: passed.
- `git diff --check`: passed.
- DB-backed Pest and smoke verification could not run because PostgreSQL on `127.0.0.1:5432` is unavailable and Docker Desktop is stopped. Starting `com.docker.service` from this session failed because Windows requires elevated access.

Pending after Docker Desktop is started:

- `php artisan test`
- `$env:BROADCAST_CONNECTION='null'; php artisan tinker --execute="require base_path('scripts/staff-e2e.php');"`
- `$env:BROADCAST_CONNECTION='null'; php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"`
- `php artisan migrate:status --pending`

---

## 2026-06-03 Slice 17 - Account Moderation Slice B HTTP + Staff Delegation

Worked in `C:\Users\User\Desktop\delivary-app-codex` on branch `account-moderation/http`, following `docs/superpowers/plans/2026-06-03-account-moderation-slice-b-codex.md`.

Scope implemented:

- Added `ModerationPolicy` and registered the `moderate` gate.
- Added moderation FormRequests for direct admin actions and phone lookup.
- Added `StaffModerationRequest` so staff suspend/reinstate/deactivate can accept optional `reason_code` and `detail`.
- Added moderation Resources:
  - `UserModerationResource`
  - `ModerationActionResource`
  - `UserLookupResource`
- Added admin HTTP controllers:
  - `UserModerationController`
  - `AdminUserLookupController`
- Added `/api/admin/users` routes for lookup, suspend, ban, reinstate, and moderation history.
- Added `throttle:moderation`.
- Refactored `StaffService::suspend()`, `reinstate()`, and `deactivate()` to delegate account-status writes to `AccountModerationService::apply()`, preserving existing staff self/last-admin guards.
- Added `scripts/moderation-e2e.php`.
- Added unit/feature tests for the policy, resources, admin endpoints, lookup, and staff audit delegation.

Important boundary:

- Slice A core is still absent on this branch: `ModerationAction`, `ModerationReason`, `AccountModerationAction`, `AccountModerationService`, `User::hasOutstandingFees()`, and `User::moderationActions()` are expected to arrive from Claude's Slice A. I did not edit those files.

Verification:

- `php -l` passed for all touched/new PHP files.
- `vendor\bin\pint ...` passed; it fixed one test import.
- `php artisan route:list --path=admin/users` passed and shows all 5 moderation routes.
- `git diff --check` passed.
- Main worktree at `C:\Users\User\Desktop\delivary-app` is clean.

Blocked verification:

- `vendor\bin\pest tests\Unit\Policies\ModerationPolicyTest.php tests\Unit\Resources\Moderation\ModerationResourcesTest.php` could not run because PostgreSQL on `127.0.0.1:5432` is unavailable and Docker Desktop is stopped.
- `docker ps` failed because Docker Desktop's Linux engine pipe is not running.
- Full feature tests and `scripts/moderation-e2e.php` also require Claude Slice A core to be merged/rebased into this branch.

Next after Claude Slice A merges:

- `git fetch origin && git rebase origin/main`
- `php artisan migrate`
- `vendor\bin\pest tests\Unit\Policies\ModerationPolicyTest.php tests\Unit\Resources\Moderation\ModerationResourcesTest.php tests\Feature\Admin\Moderation`
- `vendor\bin\pest --filter=Staff`
- `php artisan tinker --execute="require base_path('scripts/moderation-e2e.php');"`
- Run staff and orders smoke scripts for regression.

---

## 2026-06-18 Dashboard Support A — Admin backend for the internal dashboard

Backend-first, **additive-only** milestone preparing the admin API for the Vue dashboard (UI already designed in `docs/design/dashboard/`). Parallel-worktree: Claude = Slices A (foundations + settings) + C (driver finance/strikes); Codex = B (users/orders/merchants) + D (admin onboarding). Cross-reviewed both directions; PRs #19 (Codex B+D) / #20 (Claude A+C). Full detail in `SYSTEM_SPECIFICATION.md §17.18`.

**Claude — Slice A:** enriched `GET /auth/me` (roles, must_change_password, office assignments, is_driver/merchant, badge counts); `GET /admin/reference`; `GET /admin/map/overview` (one grouped query for active loads); `GET`/`PATCH /admin/settings` over a curated `SettingsCatalog` (validated/ranged/audited via `PlatformSetting::set($type)`).

**Claude — Slice C:** `driver_strikes.public_id` migration; `GET /admin/drivers/{id}/account`; `GET`/`POST /admin/drivers/{id}/strikes` (`DriverStrikeResource`, `active_count`, add-manual + optional ledger fee); `POST .../strikes/{strike}/void` (status-only, locked already-voided guard); `POST .../account/adjust` (`DriverAccountLedgerService::applyManualAdjustment()`, 422 on negative bucket); strikes restricted to real drivers (404); additive `DriverProfile*Resource` fields + `activity_status` filter; `User::strikes()`.

**Codex — Slice B:** `GET /admin/users` directory + `{user}` detail (`UserDirectoryResource`/`UserDetailResource`, orders_count = sent+received via `withCount`); `GET /admin/orders` driver/merchant/search filters (+ `PublicIdResolver::merchantProfileId()`); `MerchantResource` owner embed (phone/account_status/roles, eager-loaded `user.roles`).

**Codex — Slice D:** `OnboardingController` reusing the full office lifecycle under `role:admin` — `lookup`, `onboard` (existing-user or new person → `pre_registered`), `verify-phone` (in-office OTP), `documents` store/destroy, `submit` (→ `pending_approval`); dedicated admin FormRequests.

**Cross-review fixes:** (→Claude A) `me` driver/merchant flags added, map N+1 collapsed to a grouped query, reference labels reconciled to `{value,label}`. (→Claude C) strikes guarded to real drivers (404), void already-voided check moved under a row lock. (→Codex B/D) approved — onboarding confirmed to reuse the full lifecycle, order filters validate `exists`, merchant owner embed completed.

**Verified on merged `main`:** full Pest **310/310** (1036 assertions), Pint clean, `composer validate --strict` passed, 58 admin routes, `migrate:status` all ran.
