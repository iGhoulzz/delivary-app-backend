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
