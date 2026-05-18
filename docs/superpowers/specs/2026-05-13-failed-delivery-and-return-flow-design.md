# Failed Delivery & Return-to-Office Flow (Sub-Project D) — Design Spec

**Date:** 2026-05-13
**Status:** ✅ Implemented by Codex on 2026-05-13 (Slice 11)
**Scope:** The unhappy-path tail of an order. Driver marks delivery failed (from any post-pickup state) → driver carries the item back to a pre-resolved office → office staff confirms physical receipt → optional retrieval by the seller (paying any accrued fees) → automatic abandonment after 30 days. Driver gets paid at office-receipt (not at fail-time) to disincentivise drive-away. Storage fees accrue on a flat-per-day basis past a 5-day grace, computed just-in-time at retrieval.

**Out of scope (deliberately deferred):**
- Sender post-pickup cancel — permanently rejected per Q1 (once an item is in a driver's hands, only the driver or admin can fire failure; senders cannot dematerialise the trip).
- Active notifications (SMS / push) — deferred to the Real-time milestone. Sender / receiver / guest-tracking UI surfaces state changes via existing polling endpoints.
- Wallet-based payment at retrieval — cash only at MVP; future feature flag.
- Admin "early abandonment" (mark `at_office → abandoned` before 30 days) — DB/Tinker for now.
- Admin override of `return_fault` after the fact — DB/Tinker for now.
- Office cash-drawer / register accounting — `office_inventory.cash_collected_at_retrieval` snapshots the event; formal office cash settlement is a separate later milestone.
- Office staff CRUD (admin creates new staff) — separate milestone.
- A driver "I've arrived at office" tap — office staff initiates `receive-return` when the driver walks in (Q6a).

**Predecessors:**
- Order lifecycle A+B + pre-pickup C → `docs/superpowers/specs/2026-05-12-order-lifecycle-design.md` (shipped 2026-05-12, see `docs/SYSTEM_SPECIFICATION.md` §17.8)
- `StateTransitionService`, `OrderStatusChanged` event, `OrderPolicy`, `OrderErrorCode`, `DriverAccountLedgerService` — all already live
- `office_inventory` table — already migrated (Group 8, see `database/migrations/2026_05_04_090400_create_office_inventory_table.php`)
- All `OrderStatus` failure-chain cases exist; only two `allowedTransitions()` entries need extending

---

## 1. Goals

1. **Driver-paid-only-on-completion.** The delivery-fee earnings credit happens at `at_office`, not at `delivery_failed`. Drivers can't drive off with goods after marking failed and still get paid. Drivers stay `on_order` (no new broadcasts) until physical return is confirmed by office staff.
2. **Pickup-region-resolves-return-office, with flexibility.** Default: the return office is the office linked to the pickup region (`regions.office_id`). Fallback: nearest active office to pickup_location. Admin can re-route mid-return.
3. **Storage fee is simple, just-in-time, flat-per-day, fully runtime-tunable.** Free for `storage.grace_days` days (default 5), then `storage.daily_fee` LYD/day (default 1.00) until abandonment at `storage.abandonment_days` (default 30). No cron writes during the wait — computed only at retrieval / abandonment.
4. **Snapshot-at-retrieval.** When the seller pays and retrieves, the just-in-time computed `accrued_storage_fee` becomes immutable. `office_inventory.accrued_storage_fee` is the working column; `orders.storage_fee_accrued` is the immutable snapshot of the final value.
5. **All state changes flow through `StateTransitionService`.** No `claim`-style atomic-UPDATE exceptions are needed for D — every transition is a single-actor event with no race semantics.
6. **`office_inventory` is the operational anchor for items at office.** One row per failed-and-returned order, created at `receive-return`. Holds shelf location, working storage-fee accrual state, retrieval / abandonment audit. The `orders` table holds the lifecycle timestamps + return classification (already present in schema).
7. **Driver-fault failures absorb the delivery fee.** Per spec §6.2: `return_fault = driver` or `platform` → seller pays only storage at retrieval. `return_fault = sender` or `receiver` → seller pays delivery_fee (if still unpaid) + storage.

## 2. Non-Goals

- No new tables. `office_inventory` already exists and is the schema-blessed home for return-flow state.
- No daily storage-fee-accrual cron. Storage fee is purely computed on demand.
- No new `OrderStatus` cases. All failure-chain enum values were defined in Group 7.
- No "rebroadcast a failed order to a different driver" flow. Once it fails, it goes to office. Period.
- No driver-side endpoint to confirm arrival at office. Office staff handles `receive-return` when the driver walks in.
- No partial retrieval. The seller either retrieves the whole item or it sits in inventory.
- No multi-leg pickups / drop-offs.

## 3. Locked Decisions (from brainstorm 2026-05-13)

| # | Question | Decision |
|---|---|---|
| 1 | Who can fire `delivery_failed` and from where | **Driver-initiated from any post-pickup state** (`picked_up`, `driver_en_route_dropoff`, `delivery_in_progress`) **+ admin override from the same states**. **No sender post-pickup cancel** — Slice 10's `ORDER_NOT_CANCELLABLE_FROM_STATE` rejection becomes permanent. |
| 2 | Driver pay timing on failure | **Credit at `at_office`** (not at `delivery_failed`). Driver stays `on_order` until physical return is confirmed — cannot accept new broadcasts mid-return. Removes the bad-actor "drive off and lie about the failure" path. |
| 3 | Return office selection | **Default: pickup region's office** (`Region::find(...)->office_id` resolved from pickup_location via PostGIS `ST_Contains`). **Fallback** if no region match: nearest active office to `pickup_location` by `ST_Distance`. **Admin override mid-return**: `POST /api/admin/orders/{id}/redirect-return` re-targets `orders.return_office_id`. |
| 4 | Storage fee model + accrual | **Flat daily fee + just-in-time accrual.** `storage_fee = max(0, days_since_received - grace_days) × daily_fee`. Computed at retrieval / abandonment; no cron writes during the wait. No fee cap at MVP (natural cap = `(abandonment_days − grace_days) × daily_fee` = 25 LYD at defaults). |
| 5 | Retrieval payment model | **Cash only at MVP**, snapshotted on `office_inventory.cash_collected_at_retrieval`. Wallet support is a future feature flag. **Owed formula:** `(delivery_fee if unpaid AND fault ∈ {sender, receiver}, else 0) + accrued_storage_fee − retrieval_fees_waived_amount`. Driver-fault / platform-fault → seller pays only storage. |
| 6 | Endpoint granularity + namespace | **8 endpoints total:** 1 driver, 4 office (2 read + 2 write), 3 admin. Office staff endpoints live under `/api/office/orders/*` (mirrors existing `/api/office/drivers/*` convention). No driver "arrived at office" tap. |
| 7a | `return_reason` → `return_fault` mapping | `receiver_refused`/`receiver_unreachable` → `receiver`. `address_invalid` → `sender`. `item_damaged`/`driver_fault` → `driver`. System infers `return_fault` from driver's selected `return_reason` at fail time. |
| 7b | Notifications | **None active at MVP.** Senders' apps poll `/api/me/orders/{id}` and `display_status` flips drive UI changes. The Real-time milestone's Reverb push will subscribe to the existing `OrderStatusChanged` event for zero-touch upgrade. |
| 7c | Admin escape hatches (early abandonment, fault override) | **Deferred.** DB/Tinker is acceptable at MVP. Cron handles the common case (30-day abandonment); fault classification is set once at fail time and trusted thereafter. |

## 4. State Machine Extension

`OrderStatus::allowedTransitions()` already supports the full failure → return chain:

```
delivery_in_progress → delivery_failed         ✅ already allowed
delivery_failed     → returning_to_office     ✅ already allowed
returning_to_office → at_office               ✅ already allowed
at_office           → retrieved_by_seller     ✅ already allowed
at_office           → abandoned               ✅ already allowed
```

Two additions are needed for Q1 (driver/admin can fail from any post-pickup state):

```
picked_up              → delivery_failed     ⬅ ADD
driver_en_route_dropoff → delivery_failed   ⬅ ADD
```

**Auto-chain (single transaction):** `mark-delivery-failed` transitions `<post-pickup state> → delivery_failed`, then immediately auto-chains `delivery_failed → returning_to_office`. Same pattern as A+B's `confirm-pickup` auto-chain to `en_route_dropoff`. Two `order_status_logs` rows are written.

`display_status` mapping (`OrderDisplayStatus::fromInternal`) — already exists:
- `delivery_failed`, `returning_to_office`, `at_office`, `retrieved_by_seller`, `abandoned` → all map to `"failed"` in the client-facing surface (sender + receiver + guest tracking)
- Driver + admin views expose raw `status` and operational detail
- **No change needed to `OrderDisplayStatus`** — D-state mappings were future-proofed in A+B

## 5. Endpoint Catalog

**Total: 8 new endpoints across 2 namespaces (no new namespace prefixes).**

### Driver (`/api/driver/orders/*`)

| Method | Path | Auth | Throttle | Purpose |
|---|---|---|---|---|
| POST | `/api/driver/orders/{public_id}/mark-delivery-failed` | sanctum + role:driver + OrderPolicy::act | `driver_action` (10/min/driver) | Driver marks delivery failed; body `{return_reason, notes?}`; auto-chains to `returning_to_office`; snapshots `return_office_id` (pickup-region resolution) + `return_reason` + `return_fault` |

### Office staff (`/api/office/orders/*`)

| Method | Path | Auth | Throttle | Purpose |
|---|---|---|---|---|
| GET | `/api/office/orders` | sanctum + role:office_staff | `office_orders_read` (60/min/user) | List/filter orders bound for this staff's office (inbound `returning_to_office` + on-shelf `at_office`) |
| GET | `/api/office/orders/{public_id}` | sanctum + role:office_staff | `office_orders_read` | Order + inventory detail |
| POST | `/api/office/orders/{public_id}/receive-return` | sanctum + role:office_staff | `office_action` (10/min/user) | Confirm physical receipt; transitions `returning_to_office → at_office`; creates `office_inventory` row; credits driver buckets per Q2 |
| POST | `/api/office/orders/{public_id}/retrieve` | sanctum + role:office_staff | `office_action` | Confirm seller pickup; body `{cash_collected, notes?}`; computes owed JIT; transitions `at_office → retrieved_by_seller`; snapshots financials |

### Admin (`/api/admin/orders/*`)

| Method | Path | Auth | Throttle | Purpose |
|---|---|---|---|---|
| POST | `/api/admin/orders/{public_id}/mark-delivery-failed` | sanctum + role:admin | none | Admin can fire from any post-pickup state; body `{return_reason, notes?}` — uses the order's existing `driver_id` |
| POST | `/api/admin/orders/{public_id}/redirect-return` | sanctum + role:admin | none | Re-target `orders.return_office_id` mid-return; body `{office_id, reason?}`; writes audit log with `metadata.event = "return_office_redirected"` |
| POST | `/api/admin/orders/{public_id}/waive-retrieval-fees` | sanctum + role:admin | none | Set `office_inventory.retrieval_fees_waived_amount`; body `{amount, reason?}`; doesn't transition state |

### Background

- **`AbandonStaleOrdersJob`** — scheduled `daily()->withoutOverlapping()`. Flips `at_office → abandoned` past `storage.abandonment_days`. Inside one transaction per order; copies `office_inventory.accrued_storage_fee` (JIT-computed at the moment) to `orders.storage_fee_accrued` as the final snapshot, writes `office_inventory.abandoned_at + abandoned_by_admin_id` (NULL admin since system-initiated). Uses `StateTransitionService::transition(actorType=System)`. Logs all batches via `Log::info`.

## 6. Service Layer

Single new service class plus reuse of existing infrastructure.

| Service | Responsibility |
|---|---|
| `App\Services\Order\FailedDeliveryService` (new) | All write-side D logic — `markDeliveryFailedByDriver`, `markDeliveryFailedByAdmin`, `receiveReturn`, `retrieve`, `redirectReturn`, `waiveRetrievalFees`, `abandonStale`. Constructor injects `StateTransitionService`, `DriverAccountLedgerService`. Each method opens its own `DB::transaction`. |
| `App\Services\Order\ReturnOfficeResolver` (new) | Pure-function resolver: given a pickup `Point`, returns the target `OfficeLocation`. Steps: (1) `ST_Contains` against `regions.boundary` → `regions.office_id` if matched; (2) nearest active `office_locations` by `ST_Distance` fallback. Throws `OrderDomainException(NoReturnOfficeAvailable)` if neither resolves. |
| `App\Services\Order\StorageFeeCalculator` (new) | Pure-function calculator: `compute(OfficeInventory $inv): string`. Returns the just-in-time `accrued_storage_fee` LYD-string given `received_at`, `now()`, and the three `storage.*` platform settings. Returns `"0.00"` if within grace period. |
| `App\Services\Order\StateTransitionService` (existing) | Already the sole writer of `orders.status` for non-atomic flows. D uses it for: `<post-pickup> → delivery_failed`, `delivery_failed → returning_to_office`, `returning_to_office → at_office`, `at_office → retrieved_by_seller`, `at_office → abandoned`. |
| `App\Services\Driver\DriverAccountLedgerService` (existing, Slice 10) | Already supports debiting earnings → debt for strike fees. For D, we need a small additive method `applyCredit(...)` that mirrors `applyFee(...)` but writes positive amounts (cash_to_deposit gain at at_office) and applies auto-debt-offset on earnings credit. **Or** reuse the existing inline pattern from `CodeVerificationService::applyDriverDeliveryFinancials` by extracting it. **Decision: extract.** Move the existing financial credit logic from `CodeVerificationService` into `DriverAccountLedgerService::applyDeliveryCompletionCredit(User $driver, Order $order)` — D and the happy-path both call it. Minor refactor; preserves behaviour. |

### Resources (additions / reuse)

| Resource | Source |
|---|---|
| `App\Http\Resources\Order\OrderResource` (existing) | Already exposes `display_status`. Add a small `return` block when status ∈ {`delivery_failed`, `returning_to_office`, `at_office`, `retrieved_by_seller`, `abandoned`} — exposes `return_office_id`, `return_reason`, `return_fault`, `storage_fee_accrued` (snapshot), `at_office_at`, plus a `retrieval_owed` JIT field for senders showing fees-still-due. |
| `App\Http\Resources\Order\OfficeOrderResource` (new) | Office-staff view: full order details + the joined `office_inventory` row (received_at, shelf_location, accrued_storage_fee JIT, retrieval owed JIT). |
| `App\Http\Resources\Order\OfficeInventoryResource` (new) | Slim inventory view, used inside `OfficeOrderResource`. |

### Policies (additions)

`OrderPolicy` already exists. Add abilities:

| Ability | Rule |
|---|---|
| `markDeliveryFailedByDriver` | `act($user, $order)` AND status ∈ {`picked_up`, `driver_en_route_dropoff`, `delivery_in_progress`} |
| `receiveReturnByOffice` | `$user->hasRole('office_staff')` AND order's `return_office_id` is in user's active `office_staff_assignments` AND status === `returning_to_office` |
| `retrieveByOffice` | `$user->hasRole('office_staff')` AND same office assignment AND status === `at_office` |
| `viewByOffice` | `$user->hasRole('office_staff')` AND same office assignment |

Note on the policy class comment block (already-asymmetric per Task 2 review): sender-side uses FK ownership; driver-side uses role + driver-id; office-side uses role + office-assignment join. Same design rationale — the role gates plus FK/assignment is the source of truth.

## 7. Financial Flows

### 7.1 At `mark-delivery-failed`

- No financial mutation. State transition only.
- `return_office_id` snapshotted via `ReturnOfficeResolver`.
- `return_reason` snapshotted from request body.
- `return_fault` inferred and snapshotted per Q7a mapping.
- `delivery_failed_at` set (via `StateTransitionService` timestamp map).
- Auto-chain `delivery_failed → returning_to_office` sets `returning_to_office_at` in same transaction.

### 7.2 At `receive-return` (driver gets paid here)

Inside one `DB::transaction`:

1. Validate request: status === `returning_to_office`, return_office_id matches staff's office assignment.
2. Create `office_inventory` row: `order_id`, `office_id` (= return_office_id), `received_by_staff_id` (= auth user), `received_at` (= now()), `shelf_location` (optional from body, nullable), `accrued_storage_fee` = 0, `last_fee_accrued_on` = today.
3. `StateTransitionService::transition($order, AtOffice, ActorType::OfficeStaff, $staff->id, metadata: ['received_at' => ..., 'shelf_location' => ...])` — sets `orders.at_office_at + status`.
4. **Driver financial credit** (via the extracted `DriverAccountLedgerService::applyDeliveryCompletionCredit`):
   - **Sender-cash at pickup** scenarios already added cash to driver's `cash_to_deposit` at pickup time (existing `CodeVerificationService::addPickupCashToDriverAccount`). No further cash movement here — the cash was always going to need to be settled by the driver regardless of outcome.
   - **Receiver-cash never collected** scenarios (`delivery_fee_payer = receiver` or `p2p_sale`): no cash movement to `cash_to_deposit`. Sender owes at retrieval per Q5.
   - **Earnings credit** (delivery_fee − driver_fee_cut_amount) is added to `earnings_balance`, with auto-debt-offset applied first (existing pattern). Regardless of cash settlement of the delivery_fee, the driver earned their work.
   - **`p2p_sale` and item_price was supposed to be collected at delivery**: never collected (no sale happened). No `cash_to_deposit` impact for item_price. Item physically sits at office until retrieval or abandonment.
5. Flip `driver_profile.activity_status` from `OnOrder` to `Online`.

### 7.3 At `retrieve`

Inside one `DB::transaction`:

1. Validate request: status === `at_office`, return_office_id matches staff's office assignment.
2. JIT-compute `accrued_storage_fee` via `StorageFeeCalculator::compute($inventory)`.
3. Compute owed:
   ```
   delivery_fee_owed = (delivery_fee_status === 'unpaid'
                       AND return_fault IN {sender, receiver})
                      ? delivery_fee : "0.00"
   total_owed = delivery_fee_owed
              + accrued_storage_fee
              − office_inventory.retrieval_fees_waived_amount
   ```
4. Validate `cash_collected >= total_owed - any_waived`. If `cash_collected < total_owed`, error `INSUFFICIENT_CASH_COLLECTED` (422). If `cash_collected > total_owed`, error `EXCESS_CASH_COLLECTED` (422) — staff must take the exact amount or use the waive endpoint.
5. Snapshot to `office_inventory`: `cash_collected_at_retrieval = cash_collected`, `accrued_storage_fee = $jit`, `retrieved_at = now()`, `retrieved_by_staff_id = $staff->id`.
6. Snapshot to `orders`: `storage_fee_accrued = $jit`. (`retrieved_by_seller_at` set by `StateTransitionService`.)
7. If `delivery_fee_owed > 0`: set `delivery_fee_status = paid`, `delivery_fee_paid_at = now()`. **Note:** this is a permitted exception to the "snapshot, don't recalculate" principle on orders — the `delivery_fee_status` is operational state, not a financial amount. The fee amount itself doesn't change.
8. `StateTransitionService::transition($order, RetrievedBySeller, ActorType::OfficeStaff, $staff->id, metadata: ['cash_collected' => ..., 'delivery_fee_owed' => ..., 'storage_fee' => ..., 'waived' => ...])`.

### 7.4 At `abandon` (cron only)

Inside one `DB::transaction` per order:

1. Order is in `at_office` and `office_inventory.received_at < now() - storage.abandonment_days`.
2. JIT-compute final `accrued_storage_fee` (snapshotted for the audit trail even though no one will pay it).
3. Snapshot to `office_inventory`: `accrued_storage_fee = $jit`, `abandoned_at = now()`, `abandoned_by_admin_id = NULL` (system actor), `disposal_notes = NULL`.
4. Snapshot to `orders`: `storage_fee_accrued = $jit`. (`abandoned_at` set by `StateTransitionService`.)
5. `StateTransitionService::transition($order, Abandoned, ActorType::System, null, metadata: ['accrued_storage_fee' => $jit, 'received_at' => $inv->received_at, 'days_held' => ...])`.

### 7.5 At `redirect-return` (admin)

Inside one `DB::transaction`:

1. Validate request: status === `returning_to_office`, target office is active.
2. Update `orders.return_office_id`.
3. **Do NOT** transition state. Write an `order_status_logs` row directly with `from_status = to_status = returning_to_office` and `metadata.event = "return_office_redirected"` + `metadata.previous_office_id` + `metadata.new_office_id` + `metadata.reason`. Same pattern as A+B's tier escalation precedent (silent column update with an explicit audit-only log row).
4. `OrderStatusChanged` event is **not** dispatched (no real status change).

### 7.6 At `waive-retrieval-fees` (admin)

Inside one `DB::transaction`:

1. Validate request: status === `at_office`. `office_inventory` row exists.
2. Update `office_inventory.retrieval_fees_waived_amount = body.amount`.
3. **Do NOT** transition state. Write an audit log row (same shape as redirect-return — silent metadata).

## 8. Office Staff Authorization

The existing `role:office_staff` middleware alias gates the namespace. Inside the policy, the **per-office scope** is enforced:

```
$user->officeStaffAssignments()
     ->active()              // removed_at IS NULL
     ->where('office_id', $order->return_office_id)
     ->exists()
```

This prevents a Misrata-office staff from receiving / retrieving a Tripoli-region return.

If `office_staff_assignments` doesn't currently expose a `User`-side relation (verify in implementation), add `User::officeStaffAssignments()` as a `HasMany` to `OfficeStaffAssignment`.

## 9. Schema Additions

**One migration: `2026_05_13_000100_add_retrieval_columns_to_office_inventory_table.php`**

```php
public function up(): void
{
    Schema::table('office_inventory', function (Blueprint $table): void {
        $table->decimal('cash_collected_at_retrieval', 12, 2)->default(0)
              ->after('retrieved_by_staff_id');
        $table->decimal('retrieval_fees_waived_amount', 12, 2)->default(0)
              ->after('cash_collected_at_retrieval');
    });
}
```

Plus three updates that aren't migrations:

- **`App\Enums\OrderStatus::allowedTransitions()`**: add `PickedUp → DeliveryFailed` and `DriverEnRouteDropoff → DeliveryFailed`.
- **`App\Models\OfficeInventory`**: add the two new columns to `$fillable` and `casts(): 'decimal:2'`.
- **`App\Models\User`**: verify / add `officeStaffAssignments(): HasMany` to `OfficeStaffAssignment`.

**No new tables, no new columns on `orders`.**

## 10. Platform Settings Additions

Add to `OrderLifecyclePlatformSettingsSeeder` (or a small new `FailedDeliverySettingsSeeder`):

| Key | Default | Type | Notes |
|---|---|---|---|
| `storage.grace_days` | `5` | integer | Days from `received_at` with no storage fee |
| `storage.daily_fee` | `1.00` | decimal | LYD per day past grace |
| `storage.abandonment_days` | `30` | integer | Days from `received_at` to flip to `abandoned` |

All runtime-tunable. Admin can change at any time; in-flight `office_inventory` rows pick up the new values at next JIT compute (storage_fee_accrued snapshot on orders is set only at retrieval/abandonment, after which it's immutable).

## 11. Error Contract Additions

Add to `App\Enums\OrderErrorCode`:

| Case | HTTP | Used by |
|---|---|---|
| `OrderNotFailable` | 409 | `mark-delivery-failed` (status not in {picked_up, en_route_dropoff, delivery_in_progress}) |
| `OrderNotReceivable` | 409 | `receive-return` (status not `returning_to_office`) |
| `OrderNotRetrievable` | 409 | `retrieve` (status not `at_office`) |
| `OrderNotWaivable` | 409 | `waive-retrieval-fees` (status not `at_office`) |
| `OrderNotRedirectable` | 409 | `redirect-return` (status not `returning_to_office`) |
| `WrongOfficeForOrder` | 403 | office_staff acting on an order outside their office assignment |
| `NoReturnOfficeAvailable` | 422 | `ReturnOfficeResolver` finds neither region nor any active office |
| `InsufficientCashCollected` | 422 | `retrieve` when `cash_collected < total_owed` |
| `ExcessCashCollected` | 422 | `retrieve` when `cash_collected > total_owed` (strict equality at MVP) |
| `OfficeInactive` | 422 | `redirect-return` when target office is not `is_active = true` |

`InvalidStateTransition`, `NotYourOrder` already exist.

## 12. Audit Logging

**`order_status_logs` discipline (unchanged):** every real status change writes one row via `StateTransitionService`. D adds these transition entries:
- `<post-pickup> → delivery_failed` (driver / admin actor)
- `delivery_failed → returning_to_office` (system actor, auto-chain)
- `returning_to_office → at_office` (office_staff actor)
- `at_office → retrieved_by_seller` (office_staff actor)
- `at_office → abandoned` (system actor, cron)

**Silent audit-only rows** (no state change, `from_status = to_status`):
- `returning_to_office` self-row for `redirect-return` admin event
- `at_office` self-row for `waive-retrieval-fees` admin event

**`driver_account_transactions`:** driver earnings credit at `receive-return` writes one (or two — earnings + debt-offset) rows via `DriverAccountLedgerService`. References the order; same pattern as Slice 10.

**`office_inventory`:** is itself an audit anchor. The row's columns track received_at, retrieved_at, abandoned_at, etc.

## 13. Background Worker

### `AbandonStaleOrdersJob`

```
Schedule::job(new AbandonStaleOrdersJob())
    ->daily()
    ->withoutOverlapping();
```

Behaviour:

```
Cache::lock('orders:abandon:sweep', 90)->block(5, function () {
    Order::query()
        ->where('status', OrderStatus::AtOffice->value)
        ->cursor()
        ->each(function (Order $order) {
            DB::transaction(function () use ($order) {
                $inv = $order->officeInventory;  // hasOne (verify)
                if ($inv === null) return;       // defensive

                $now = now();
                $abandonAfterDays = (int) PlatformSetting::get('storage.abandonment_days', 30);
                if ($inv->received_at->addDays($abandonAfterDays)->gt($now)) return;

                $accrued = app(StorageFeeCalculator::class)->compute($inv);
                $inv->forceFill([
                    'accrued_storage_fee' => $accrued,
                    'abandoned_at' => $now,
                    // abandoned_by_admin_id stays null (system actor)
                ])->save();

                $order->forceFill(['storage_fee_accrued' => $accrued])->save();

                app(StateTransitionService::class)->transition(
                    $order->refresh(),
                    OrderStatus::Abandoned,
                    OrderActorType::System,
                    null,
                    metadata: ['accrued_storage_fee' => $accrued, 'days_held' => ...]
                );
            });
        });
});
```

The existing `EscalateBroadcastingOrdersJob` (`everyMinute`) and `AutoOfflineIdleDriversJob` (`everyMinute`) provide the scheduling-skill template; `AbandonStaleOrdersJob` follows the same shape but at `daily`.

## 14. Testing Plan

Following the established pattern: smoke-script-driven verification, no Pest tests at MVP (deferred to next-steps test-infrastructure milestone).

Extend `scripts/orders-e2e.php` with scenarios:

1. **Driver-fault failure → office return → driver paid at at_office** — quote + create + claim + pickup + driver marks `item_damaged` failure from `en_route_dropoff` → driver auto-`on_order` flips back via `receive-return` → driver `earnings_balance` got the credit + `delivery_fee_status` stays unpaid (platform absorbs).
2. **Receiver-refused failure → seller retrieves at office paying delivery_fee + storage** — fail-then-return → 7 simulated days at office → JIT computes `7 - 5 = 2` days × 1 LYD = 2 LYD storage → seller pays delivery_fee + 2 LYD → `delivery_fee_status = paid`, `storage_fee_accrued = 2.00` snapshotted on order, `cash_collected_at_retrieval = total` on inventory.
3. **Address-invalid failure → sender retrieves** — same flow as #2 but `return_fault = sender`; storage + delivery_fee owed.
4. **P2P sale failure → seller retrieves** — receiver_unreachable fault=receiver → no item price ever collected → seller pays storage + delivery_fee (no item_price impact).
5. **Standard-delivery sender-pays-at-pickup failure → seller retrieves owing only storage** — sender already paid delivery_fee at pickup, `delivery_fee_status = paid` carried over → at retrieval only storage owed.
6. **Driver-fault failure + seller retrieves** — only storage owed; delivery_fee absorbed by platform.
7. **Admin redirects return mid-flight** — driver mid-return, admin re-targets `return_office_id`, audit log row written, no state change.
8. **Admin waives retrieval fees** — full waiver, seller pays 0; `retrieval_fees_waived_amount` snapshotted.
9. **Admin marks delivery failed when driver didn't** — admin force-fails from `picked_up`; driver gets paid at `receive-return` as normal.
10. **Abandonment cron** — order at office for 31+ days, run `AbandonStaleOrdersJob`; status flips to `abandoned`, final storage fee snapshotted (25.00 LYD at defaults), driver buckets unchanged.
11. **Office staff can't act on another office's order** — staff at Office A tries `receive-return` on an order with `return_office_id = B` → 403 `WrongOfficeForOrder`.
12. **`mark-delivery-failed` from `picked_up`** — earliest valid state; verify new `OrderStatus::allowedTransitions()` entry works.
13. **Excess cash error at retrieve** — staff submits more cash than owed → 422 `ExcessCashCollected`; no state change.

Each scenario asserts:
- (a) `orders.status` + lifecycle timestamps
- (b) `order_status_logs` rows
- (c) `office_inventory` row state
- (d) `driver_accounts` buckets + `driver_account_transactions` rows
- (e) `delivery_fee_status` correctly flipped where applicable

## 15. Docs Update at Milestone Close

Per `feedback_doc_progress_updates.md`:

- **`docs/SYSTEM_SPECIFICATION.md`** §17 — add subsection `17.10 Failed delivery + return-to-office milestone (YYYY-MM-DD) ✅` with endpoint table + locked decisions + extracted-financial-credit refactor note.
- **`docs/CLAUDE.md`** — `Current Project State` row update; add a new endpoint table under the order-lifecycle section; update "Next Steps" to remove sub-project D and promote next-in-line (settlement processing).
- **`docs/CODEX.md`** — append a "Slice 11" entry summarising D's implementation; same pattern as Slices 1–10.

## 16. Open Questions Resolved by This Spec

- ✅ **Storage fee policy specifics** — flat daily + JIT + 5-day grace + 30-day abandonment + no MVP cap (Q4)
- ✅ **Retrieval payment flow at office** — cash only at MVP, snapshotted on `office_inventory.cash_collected_at_retrieval` (Q5)
- ✅ **Abandonment disposition policy** — daily cron flips to `abandoned`, physical item handling is admin out-of-band (Q7c)

Open questions deferred to future milestones:
- ⏸️ SMS provider — affects sender notifications, deferred to production-prep
- ⏸️ Rating & review system — out of scope
- ⏸️ Admin panel UI scope — out of scope; admin endpoints exist as API-only

---

**End of design spec.**
