# Failed Delivery & Return-to-Office Flow (Sub-Project D) — Implementation Plan

> **For agentic workers:** Implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. The plan is comprehensive — every step contains the exact code to write or modify. Run the verification commands as you go; do NOT skip them.

**Goal:** Build the unhappy-path tail of the order lifecycle — driver/admin marks delivery failed, item is carried back to the pre-resolved return office, office staff confirms physical receipt (which credits the driver's earnings), seller comes to the office and pays accrued fees to retrieve the item, or the order auto-abandons after 30 days. 8 new endpoints + 1 daily cron + 1 small migration.

**Architecture:** Service-layer driven, following the same conventions established in A+B. New `FailedDeliveryService` orchestrates every D write; pure-function helpers `ReturnOfficeResolver` and `StorageFeeCalculator` handle isolated bits. All state changes route through the existing `StateTransitionService` (no atomic-UPDATE exceptions needed — every D transition is a single-actor event with no race semantics). Driver earnings credit (currently inlined in `CodeVerificationService`) gets extracted into `DriverAccountLedgerService::applyDeliveryCompletionCredit` so D and the existing happy-path share one code path.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL + PostGIS (clickbar/laravel-magellan), Sanctum 4, Spatie Permission 7, Redis cache, bcmath. No Pest tests yet — verification via Tinker smoke + the existing `scripts/orders-e2e.php` extended with D scenarios.

**Spec:** `docs/superpowers/specs/2026-05-13-failed-delivery-and-return-flow-design.md`

**Predecessor:** `docs/superpowers/specs/2026-05-12-order-lifecycle-design.md` + `docs/superpowers/plans/2026-05-12-order-lifecycle.md` (A+B + Slice 10 — shipped 2026-05-12)

---

## File Structure

```
NEW
├── database/migrations/
│   └── 2026_05_13_000100_add_retrieval_columns_to_office_inventory_table.php
│
├── app/Services/Order/
│   ├── FailedDeliveryService.php          (the orchestrator — 7 public methods)
│   ├── ReturnOfficeResolver.php           (pure: region → office, with nearest-office fallback)
│   └── StorageFeeCalculator.php           (pure: JIT computation against office_inventory)
│
├── app/Http/Requests/Order/
│   ├── MarkDeliveryFailedRequest.php      (driver — body: reason, notes?)
│   ├── AdminMarkDeliveryFailedRequest.php (admin — same)
│   ├── ReceiveReturnRequest.php           (office_staff — body: shelf_location?, notes?)
│   ├── RetrieveOrderRequest.php           (office_staff — body: cash_collected, notes?)
│   ├── RedirectReturnRequest.php          (admin — body: office_id, reason?)
│   ├── WaiveRetrievalFeesRequest.php      (admin — body: amount, reason?)
│   └── OfficeOrdersListRequest.php        (office_staff — query: status?, per_page?)
│
├── app/Http/Resources/Order/
│   ├── OfficeOrderResource.php            (office_staff view — full order + inventory join)
│   └── OfficeInventoryResource.php        (slim inventory projection)
│
├── app/Http/Controllers/Api/Driver/Order/
│   └── MarkDeliveryFailedController.php   (POST /api/driver/orders/{id}/mark-delivery-failed)
│
├── app/Http/Controllers/Api/Office/Order/
│   ├── OrderController.php                (GET /api/office/orders, GET /api/office/orders/{id})
│   ├── ReceiveReturnController.php        (POST /api/office/orders/{id}/receive-return)
│   └── RetrieveOrderController.php        (POST /api/office/orders/{id}/retrieve)
│
├── app/Jobs/
│   └── AbandonStaleOrdersJob.php          (daily cron)
│
└── (no new tests yet — extend scripts/orders-e2e.php)

MODIFIED
├── app/Enums/OrderStatus.php              (add 2 allowed transitions: PickedUp/DriverEnRouteDropoff → DeliveryFailed)
├── app/Enums/OrderErrorCode.php           (add 10 new cases + httpStatus mappings)
├── app/Models/OfficeInventory.php         (add 2 fillable + 2 decimal:2 casts)
├── app/Services/Driver/DriverAccountLedgerService.php   (add applyDeliveryCompletionCredit method)
├── app/Services/Order/CodeVerificationService.php       (delegate to the new ledger method; preserve behaviour)
├── app/Policies/OrderPolicy.php           (add markDeliveryFailedByDriver, receiveReturnByOffice, retrieveByOffice, viewByOffice)
├── app/Http/Resources/Order/OrderResource.php           (add `return` block when status ∈ failure chain)
├── app/Http/Controllers/Api/Admin/OrderController.php   (add markDeliveryFailed, redirectReturn, waiveRetrievalFees methods)
├── app/Providers/AppServiceProvider.php   (register office_orders_read, office_action limiters)
├── database/seeders/OrderLifecyclePlatformSettingsSeeder.php (append storage.* keys)
├── lang/en/order_messages.php             (10 new keys)
├── lang/ar/order_messages.php             (10 new keys, mirror structure)
├── routes/api.php                         (8 new routes across /api/driver, /api/office, /api/admin)
├── routes/console.php                     (schedule AbandonStaleOrdersJob daily)
├── scripts/orders-e2e.php                 (extend with 13 D scenarios)
└── docs/CLAUDE.md, docs/SYSTEM_SPECIFICATION.md, docs/CODEX.md  (closing update)
```

---

## Task 1: Schema additions — migration + enum + model

**Files:**
- Create: `database/migrations/2026_05_13_000100_add_retrieval_columns_to_office_inventory_table.php`
- Modify: `app/Enums/OrderStatus.php` (add 2 allowed transitions)
- Modify: `app/Models/OfficeInventory.php` (2 fillable + 2 casts)

- [ ] **Step 1.1: Create the migration.**

File: `database/migrations/2026_05_13_000100_add_retrieval_columns_to_office_inventory_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sub-project D additions: track cash paid at retrieval and any admin-waived
        // portion. The accrued storage fee + retrieval/abandonment audit columns
        // already exist on this table from Group 8.
        Schema::table('office_inventory', function (Blueprint $table): void {
            $table->decimal('cash_collected_at_retrieval', 12, 2)->default(0)
                ->after('retrieved_by_staff_id');
            $table->decimal('retrieval_fees_waived_amount', 12, 2)->default(0)
                ->after('cash_collected_at_retrieval');
        });
    }

    public function down(): void
    {
        Schema::table('office_inventory', function (Blueprint $table): void {
            $table->dropColumn(['cash_collected_at_retrieval', 'retrieval_fees_waived_amount']);
        });
    }
};
```

- [ ] **Step 1.2: Extend `OrderStatus::allowedTransitions()` with two new failure paths.**

File: `app/Enums/OrderStatus.php` — locate the `allowedTransitions()` method and replace the `PickedUp` and `DriverEnRouteDropoff` cases:

```php
self::PickedUp => [self::DriverEnRouteDropoff, self::DeliveryFailed, self::CancelledByUser, self::CancelledByAdmin],
self::DriverEnRouteDropoff => [self::DeliveryInProgress, self::DeliveryFailed, self::CancelledByAdmin],
```

(Only adding `self::DeliveryFailed` to each. Preserve all other targets.)

- [ ] **Step 1.3: Update `OfficeInventory` model — fillable + casts.**

File: `app/Models/OfficeInventory.php`

Add `'cash_collected_at_retrieval'` and `'retrieval_fees_waived_amount'` to `$fillable`:

```php
protected $fillable = [
    'public_id', 'order_id', 'office_id',
    'received_by_staff_id', 'received_at', 'shelf_location',
    'accrued_storage_fee', 'last_fee_accrued_on',
    'retrieved_at', 'retrieved_by_staff_id',
    'cash_collected_at_retrieval', 'retrieval_fees_waived_amount',
    'abandoned_at', 'abandoned_by_admin_id', 'disposal_notes',
    'notes',
];
```

In `casts()`, add the two decimal columns:

```php
'cash_collected_at_retrieval' => 'decimal:2',
'retrieval_fees_waived_amount' => 'decimal:2',
```

- [ ] **Step 1.4: Run the migration.**

```bash
php artisan migrate
```

Expected: one `Migrating ... Migrated` line, no errors.

- [ ] **Step 1.5: Tinker smoke — verify columns + transitions.**

```bash
php artisan tinker --execute="echo \Schema::hasColumn('office_inventory','cash_collected_at_retrieval')?'1':'0'; echo \Schema::hasColumn('office_inventory','retrieval_fees_waived_amount')?'1':'0'; echo \in_array(\App\Enums\OrderStatus::DeliveryFailed, \App\Enums\OrderStatus::PickedUp->allowedTransitions(), true)?'1':'0'; echo \in_array(\App\Enums\OrderStatus::DeliveryFailed, \App\Enums\OrderStatus::DriverEnRouteDropoff->allowedTransitions(), true)?'1':'0';"
```

Expected output: `1111` (all four checks pass).

---

## Task 2: Platform settings additions

**Files:**
- Modify: `database/seeders/OrderLifecyclePlatformSettingsSeeder.php`

- [ ] **Step 2.1: Append three storage keys to the seeder array.**

File: `database/seeders/OrderLifecyclePlatformSettingsSeeder.php`

Add inside the existing `$defaults` array, after the existing `cancellation.*` entries:

```php
// Storage / abandonment (sub-project D)
['key' => 'storage.grace_days', 'type' => 'integer', 'value' => 5, 'description' => 'Free storage days from at_office_at before fees start accruing'],
['key' => 'storage.daily_fee', 'type' => 'decimal', 'value' => 1.00, 'description' => 'LYD per day after the grace period, charged to seller at retrieval'],
['key' => 'storage.abandonment_days', 'type' => 'integer', 'value' => 30, 'description' => 'Days from at_office_at before the daily cron flips an order to abandoned'],
```

- [ ] **Step 2.2: Run the seeder.**

```bash
php artisan db:seed --class=OrderLifecyclePlatformSettingsSeeder
```

Expected: silent (the seeder is idempotent; existing keys are preserved, new keys inserted).

- [ ] **Step 2.3: Tinker smoke — verify the three new settings.**

```bash
php artisan tinker --execute="echo \App\Models\PlatformSetting::get('storage.grace_days'); echo PHP_EOL; echo \App\Models\PlatformSetting::get('storage.daily_fee'); echo PHP_EOL; echo \App\Models\PlatformSetting::get('storage.abandonment_days');"
```

Expected output:
```
5
1
30
```

---

## Task 3: Error code + localization additions

**Files:**
- Modify: `app/Enums/OrderErrorCode.php` (add 10 cases + httpStatus mappings)
- Modify: `lang/en/order_messages.php`
- Modify: `lang/ar/order_messages.php`

- [ ] **Step 3.1: Add 10 cases to `OrderErrorCode`.**

File: `app/Enums/OrderErrorCode.php`

Append after the existing cases (keep alphabetical-ish grouping):

```php
case OrderNotFailable = 'order_not_failable';
case OrderNotReceivable = 'order_not_receivable';
case OrderNotRetrievable = 'order_not_retrievable';
case OrderNotWaivable = 'order_not_waivable';
case OrderNotRedirectable = 'order_not_redirectable';
case WrongOfficeForOrder = 'wrong_office_for_order';
case NoReturnOfficeAvailable = 'no_return_office_available';
case InsufficientCashCollected = 'insufficient_cash_collected';
case ExcessCashCollected = 'excess_cash_collected';
case OfficeInactive = 'office_inactive';
```

Update the `httpStatus()` `match`:

```php
self::OrderNotFailable,
self::OrderNotReceivable,
self::OrderNotRetrievable,
self::OrderNotWaivable,
self::OrderNotRedirectable => 409,

self::WrongOfficeForOrder => 403,

self::NoReturnOfficeAvailable,
self::InsufficientCashCollected,
self::ExcessCashCollected,
self::OfficeInactive => 422,
```

Insert these arms alongside the existing arms in the existing match block; do not replace the whole method.

- [ ] **Step 3.2: Append translation keys to English.**

File: `lang/en/order_messages.php` — add at the bottom of the returned array (preserving order):

```php
// Sub-project D
'order_not_failable' => 'This order cannot be marked failed in its current state.',
'order_not_receivable' => 'This order cannot be received at office in its current state.',
'order_not_retrievable' => 'This order cannot be retrieved in its current state.',
'order_not_waivable' => 'Retrieval fees can only be waived while the order is at office.',
'order_not_redirectable' => 'This order cannot be redirected to another office in its current state.',
'wrong_office_for_order' => 'This order is bound for a different office.',
'no_return_office_available' => 'No active office could be resolved for this return.',
'insufficient_cash_collected' => 'Cash collected is less than the amount owed.',
'excess_cash_collected' => 'Cash collected is more than the amount owed.',
'office_inactive' => 'Target office is not active.',
```

- [ ] **Step 3.3: Mirror translations to Arabic.**

File: `lang/ar/order_messages.php` — same keys, Arabic values:

```php
'order_not_failable' => 'لا يمكن تحديد فشل التسليم في الحالة الحالية لهذا الطلب.',
'order_not_receivable' => 'لا يمكن استلام هذا الطلب في المكتب في حالته الحالية.',
'order_not_retrievable' => 'لا يمكن استرداد هذا الطلب في حالته الحالية.',
'order_not_waivable' => 'يمكن التنازل عن رسوم الاسترداد فقط بينما الطلب في المكتب.',
'order_not_redirectable' => 'لا يمكن إعادة توجيه هذا الطلب إلى مكتب آخر في حالته الحالية.',
'wrong_office_for_order' => 'هذا الطلب موجه إلى مكتب آخر.',
'no_return_office_available' => 'لم يتمكن النظام من تحديد مكتب نشط لاستلام هذا الطلب.',
'insufficient_cash_collected' => 'النقد المُستلم أقل من المبلغ المستحق.',
'excess_cash_collected' => 'النقد المُستلم أكثر من المبلغ المستحق.',
'office_inactive' => 'المكتب المستهدف غير نشط.',
```

- [ ] **Step 3.4: Tinker smoke — verify.**

```bash
php artisan tinker --execute="echo \App\Enums\OrderErrorCode::OrderNotFailable->httpStatus(); echo PHP_EOL; echo \App\Enums\OrderErrorCode::WrongOfficeForOrder->httpStatus(); echo PHP_EOL; echo \App\Enums\OrderErrorCode::NoReturnOfficeAvailable->httpStatus(); echo PHP_EOL; echo trans('order_messages.order_not_failable');"
```

Expected:
```
409
403
422
This order cannot be marked failed in its current state.
```

---

## Task 4: Extract `applyDeliveryCompletionCredit` into `DriverAccountLedgerService`

> This is the only refactor in this milestone. It touches existing happy-path code in `CodeVerificationService` — the spec flagged this explicitly. Run the existing `scripts/orders-e2e.php` after the refactor to confirm zero regression on the delivered path.

**Files:**
- Modify: `app/Services/Driver/DriverAccountLedgerService.php` (add public method)
- Modify: `app/Services/Order/CodeVerificationService.php` (delegate the existing inline logic to the new method)

- [ ] **Step 4.1: Add `applyDeliveryCompletionCredit` to `DriverAccountLedgerService`.**

File: `app/Services/Driver/DriverAccountLedgerService.php`

Add a new public method. Keep all existing methods (`applyFee`, `mutateBucket`) unchanged.

```php
/**
 * Credit a driver for completing a delivery (happy path) or returning a
 * failed delivery to the office (sub-project D). Credits the delivery_fee
 * minus the platform cut into earnings_balance, with auto-debt-offset
 * applied first.
 *
 * Idempotency: the caller is responsible for ensuring this isn't called
 * twice for the same order (e.g., via state-transition guards).
 */
public function applyDeliveryCompletionCredit(User $driver, Order $order): void
{
    $earnings = bcsub((string) $order->delivery_fee, (string) $order->driver_fee_cut_amount, 2);
    if (bccomp($earnings, '0.00', 2) !== 1) {
        return;
    }

    $account = DriverAccount::query()
        ->where('driver_id', $driver->id)
        ->lockForUpdate()
        ->firstOrFail();

    // Auto-debt-offset (spec §4.7): earnings first reduce debt, then add to earnings_balance.
    if (bccomp((string) $account->debt_balance, '0.00', 2) === 1) {
        $offset = bccomp((string) $account->debt_balance, $earnings, 2) === 1
            ? $earnings
            : (string) $account->debt_balance;

        $this->mutateBucket(
            $account,
            DriverAccountBucket::DebtBalance,
            bcmul($offset, '-1', 2),
            DriverAccountTransactionReason::DebtOffset,
            $order,
            null,
            null,
        );

        $earnings = bcsub($earnings, $offset, 2);
    }

    if (bccomp($earnings, '0.00', 2) === 1) {
        $this->mutateBucket(
            $account,
            DriverAccountBucket::EarningsBalance,
            $earnings,
            DriverAccountTransactionReason::OrderCompleted,
            $order,
            null,
            null,
        );
    }
}
```

Add imports at top of file:

```php
use App\Models\Order;
```

(The other imports — `DriverAccountBucket`, `DriverAccountTransactionReason`, `DriverAccount`, `DriverAccountTransaction`, `User` — already exist in the file.)

- [ ] **Step 4.2: Make `mutateBucket` accept null `notes` + `createdByAdminId` for non-strike callers.**

Verify the existing signature already accepts null for both. If not, add the defaults. (It already does — confirmed during plan authoring.)

- [ ] **Step 4.3: Refactor `CodeVerificationService::applyDriverDeliveryFinancials` to delegate.**

File: `app/Services/Order/CodeVerificationService.php`

The current method has three responsibilities: (a) cash_to_deposit credit (cash-at-delivery scenarios), (b) earnings credit + auto-debt-offset, (c) the lifetime bookkeeping is not currently here.

Replace the body of `applyDriverDeliveryFinancials` to:

```php
private function applyDriverDeliveryFinancials(User $driver, Order $order): void
{
    $cash = $order->cashCollectedAtDelivery();
    if (bccomp($cash, '0.00', 2) === 1) {
        $account = DriverAccount::query()
            ->where('driver_id', $driver->id)
            ->lockForUpdate()
            ->firstOrFail();
        $this->mutateBucket($account, DriverAccountBucket::CashToDeposit, $cash, DriverAccountTransactionReason::OrderCompleted, $order);
    }

    $this->ledger->applyDeliveryCompletionCredit($driver, $order);
}
```

> The remaining `mutateBucket` helper inside `CodeVerificationService` should be deleted now that the earnings + debt-offset logic moved to `DriverAccountLedgerService`. **However**, `CodeVerificationService::addPickupCashToDriverAccount` still uses `mutateBucket` for the cash-at-pickup case. **Keep `mutateBucket` and the cash-to-deposit credit local** to `CodeVerificationService` — that responsibility is still cash-rail and order-flow-specific. Only the earnings+debt-offset moved.

To inject the ledger service, update the constructor:

```php
public function __construct(
    private readonly StateTransitionService $transitions,
    private readonly \App\Services\Driver\DriverAccountLedgerService $ledger,
) {}
```

(Or whatever DI pattern matches the file's style — Laravel auto-resolves from the container, no other registration needed.)

- [ ] **Step 4.4: Run the existing happy-path smoke to verify no regression.**

```bash
php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"
```

Expected: `ALL ORDER E2E SMOKE SCENARIOS PASSED`. If any happy-path assertion fails, the refactor broke something — debug before continuing.

> If `scripts/orders-e2e.php` is unavailable in the current state, run a focused Tinker smoke that creates one order, drives it through claim → pickup → arrived → confirm-delivery, and asserts the driver's `earnings_balance` increased by `delivery_fee - cut`.

---

## Task 5: `StorageFeeCalculator` (pure)

**Files:**
- Create: `app/Services/Order/StorageFeeCalculator.php`

- [ ] **Step 5.1: Create the service.**

File: `app/Services/Order/StorageFeeCalculator.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Models\OfficeInventory;
use App\Models\PlatformSetting;
use Illuminate\Support\Carbon;

/**
 * Pure-function just-in-time storage-fee computation against an office_inventory row.
 *
 * Formula: max(0, days_since_received - grace_days) × daily_fee.
 * Settings keys: storage.grace_days, storage.daily_fee.
 * No DB writes. No side effects. Safe to call repeatedly.
 */
final class StorageFeeCalculator
{
    public function compute(OfficeInventory $inventory, ?Carbon $now = null): string
    {
        $now ??= now();
        $graceDays = (int) PlatformSetting::get('storage.grace_days', 5);
        $dailyFee = (string) PlatformSetting::get('storage.daily_fee', 1.00);

        $daysHeld = (int) floor($inventory->received_at->diffInSeconds($now) / 86400);
        $billableDays = max(0, $daysHeld - $graceDays);

        return bcmul((string) $billableDays, $dailyFee, 2);
    }
}
```

- [ ] **Step 5.2: Tinker smoke — verify against synthetic inventory.**

```bash
php artisan tinker --execute="
\$inv = new \App\Models\OfficeInventory(['received_at' => now()->subDays(7)]);
\$inv->received_at = now()->subDays(7);
echo app(\App\Services\Order\StorageFeeCalculator::class)->compute(\$inv); echo PHP_EOL;
\$inv->received_at = now()->subDays(3);
echo app(\App\Services\Order\StorageFeeCalculator::class)->compute(\$inv); echo PHP_EOL;
\$inv->received_at = now()->subDays(30);
echo app(\App\Services\Order\StorageFeeCalculator::class)->compute(\$inv);
"
```

Expected output:
```
2.00
0.00
25.00
```

(7 days − 5 grace = 2 billable × 1.00; 3 days within grace = 0; 30 days − 5 = 25 billable × 1.00.)

---

## Task 6: `ReturnOfficeResolver` (pure)

**Files:**
- Create: `app/Services/Order/ReturnOfficeResolver.php`

- [ ] **Step 6.1: Create the resolver.**

File: `app/Services/Order/ReturnOfficeResolver.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\OrderErrorCode;
use App\Exceptions\Order\OrderDomainException;
use App\Models\OfficeLocation;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\DB;

/**
 * Pure-function: given a pickup point, return the target office for a failed-delivery return.
 *
 * Resolution order:
 *   1. Region whose `boundary` contains the pickup point → its `office_id` (if active).
 *   2. Nearest active office_location by ST_Distance.
 *   3. Throw OrderDomainException(NoReturnOfficeAvailable).
 */
final class ReturnOfficeResolver
{
    public function resolveForPickup(Point $pickup): OfficeLocation
    {
        $lng = $pickup->getLongitude();
        $lat = $pickup->getLatitude();

        // Step 1: region-based resolution
        $regionRow = DB::selectOne(
            "SELECT r.office_id
               FROM regions r
               JOIN office_locations o ON o.id = r.office_id
              WHERE r.office_id IS NOT NULL
                AND o.is_active = true
                AND ST_Contains(r.boundary::geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geometry)
              LIMIT 1",
            [$lng, $lat]
        );

        if ($regionRow !== null) {
            return OfficeLocation::findOrFail((int) $regionRow->office_id);
        }

        // Step 2: nearest active office fallback
        $nearestRow = DB::selectOne(
            "SELECT id
               FROM office_locations
              WHERE is_active = true
                AND location IS NOT NULL
              ORDER BY ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) ASC
              LIMIT 1",
            [$lng, $lat]
        );

        if ($nearestRow !== null) {
            return OfficeLocation::findOrFail((int) $nearestRow->id);
        }

        throw new OrderDomainException(
            OrderErrorCode::NoReturnOfficeAvailable,
            trans('order_messages.no_return_office_available'),
        );
    }
}
```

- [ ] **Step 6.2: Tinker smoke — verify resolution against seeded region + office.**

```bash
php artisan tinker --execute="
\$r = \App\Models\Region::first();
\$c = \DB::selectOne('SELECT ST_X(ST_Centroid(boundary::geometry)) lng, ST_Y(ST_Centroid(boundary::geometry)) lat FROM regions WHERE id = ?', [\$r->id]);
\$pt = new \Clickbar\Magellan\Data\Geometries\Point((float)\$c->lng, (float)\$c->lat, 4326);
\$office = app(\App\Services\Order\ReturnOfficeResolver::class)->resolveForPickup(\$pt);
echo 'office_id=' . \$office->id . ' name=' . \$office->name;
"
```

Expected: prints the office ID + name. If only one office exists locally, that's the one returned.

If the region has no `office_id` set, the resolver falls through to the nearest-office branch — also valid.

---

## Task 7: Extend `OrderPolicy` with 4 new abilities

**Files:**
- Modify: `app/Policies/OrderPolicy.php`

- [ ] **Step 7.1: Add the four new methods.**

File: `app/Policies/OrderPolicy.php`

Add to the existing class (preserve all current methods + the design-note docblock):

```php
public function markDeliveryFailedByDriver(User $user, Order $order): bool
{
    return $this->act($user, $order)
        && in_array($order->status, [
            OrderStatus::PickedUp,
            OrderStatus::DriverEnRouteDropoff,
            OrderStatus::DeliveryInProgress,
        ], true);
}

public function viewByOffice(User $user, Order $order): bool
{
    return $user->hasRole('office_staff')
        && $this->orderInUsersOffice($user, $order);
}

public function receiveReturnByOffice(User $user, Order $order): bool
{
    return $user->hasRole('office_staff')
        && $order->status === OrderStatus::ReturningToOffice
        && $this->orderInUsersOffice($user, $order);
}

public function retrieveByOffice(User $user, Order $order): bool
{
    return $user->hasRole('office_staff')
        && $order->status === OrderStatus::AtOffice
        && $this->orderInUsersOffice($user, $order);
}

private function orderInUsersOffice(User $user, Order $order): bool
{
    if ($order->return_office_id === null) {
        return false;
    }

    return $user->officeStaffAssignments()
        ->active()
        ->where('office_id', $order->return_office_id)
        ->exists();
}
```

(The `act()` method already exists from Task 2 of the A+B plan.)

- [ ] **Step 7.2: Tinker smoke — verify the abilities resolve.**

```bash
php artisan tinker --execute="
\$user = \App\Models\User::first();
\$order = \App\Models\Order::first();
echo \Gate::forUser(\$user)->check('viewByOffice', \$order) ? '1' : '0';
echo \Gate::forUser(\$user)->check('markDeliveryFailedByDriver', \$order) ? '1' : '0';
echo \Gate::forUser(\$user)->check('receiveReturnByOffice', \$order) ? '1' : '0';
echo \Gate::forUser(\$user)->check('retrieveByOffice', \$order) ? '1' : '0';
"
```

Expected output: any combination of `0`/`1` depending on actual state. The important thing: no errors, no "method not defined" exceptions.

---

## Task 8: `FailedDeliveryService` (the orchestrator)

**Files:**
- Create: `app/Services/Order/FailedDeliveryService.php`

This is the largest file in D. It owns every write-side D operation.

- [ ] **Step 8.1: Create the service.**

File: `app/Services/Order/FailedDeliveryService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\DriverActivityStatus;
use App\Enums\DeliveryFeePayer;
use App\Enums\DeliveryFeeStatus;
use App\Enums\OrderActorType;
use App\Enums\OrderErrorCode;
use App\Enums\OrderStatus;
use App\Enums\ReturnFault;
use App\Enums\ReturnReason;
use App\Exceptions\Order\OrderDomainException;
use App\Models\DriverProfile;
use App\Models\OfficeInventory;
use App\Models\OfficeLocation;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\User;
use App\Services\Driver\DriverAccountLedgerService;
use Illuminate\Support\Facades\DB;

final class FailedDeliveryService
{
    private const POST_PICKUP_STATES = [
        OrderStatus::PickedUp,
        OrderStatus::DriverEnRouteDropoff,
        OrderStatus::DeliveryInProgress,
    ];

    public function __construct(
        private readonly StateTransitionService $transitions,
        private readonly ReturnOfficeResolver $officeResolver,
        private readonly StorageFeeCalculator $storageFee,
        private readonly DriverAccountLedgerService $ledger,
    ) {}

    /**
     * Driver-initiated delivery failure. Snapshots return_reason + inferred
     * return_fault + resolved return_office_id, then auto-chains to
     * returning_to_office. Driver stays on_order.
     */
    public function markDeliveryFailedByDriver(
        User $driver,
        Order $order,
        ReturnReason $reason,
        ?string $notes = null,
    ): Order {
        return $this->markFailedCore(
            order: $order,
            actor: $driver,
            actorType: OrderActorType::Driver,
            reason: $reason,
            notes: $notes,
        );
    }

    /**
     * Admin-initiated delivery failure. Same effect as driver-initiated but
     * the admin is the actor; the driver_id on the order is left intact.
     */
    public function markDeliveryFailedByAdmin(
        User $admin,
        Order $order,
        ReturnReason $reason,
        ?string $notes = null,
    ): Order {
        return $this->markFailedCore(
            order: $order,
            actor: $admin,
            actorType: OrderActorType::Admin,
            reason: $reason,
            notes: $notes,
        );
    }

    /**
     * Office staff confirms physical receipt of the returned item. Creates
     * the office_inventory row, transitions to at_office, and credits the
     * driver's earnings balance (with auto-debt-offset).
     *
     * Cash_to_deposit was already updated at pickup time for sender-cash
     * orders; receiver-cash never collected. No new cash movement here.
     */
    public function receiveReturn(
        User $staff,
        Order $order,
        ?string $shelfLocation = null,
        ?string $notes = null,
    ): Order {
        return DB::transaction(function () use ($staff, $order, $shelfLocation, $notes): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::ReturningToOffice) {
                throw new OrderDomainException(
                    OrderErrorCode::OrderNotReceivable,
                    trans('order_messages.order_not_receivable'),
                );
            }

            // Create the inventory row
            $inventory = OfficeInventory::create([
                'order_id' => $order->id,
                'office_id' => $order->return_office_id,
                'received_by_staff_id' => $staff->id,
                'received_at' => now(),
                'shelf_location' => $shelfLocation,
                'accrued_storage_fee' => '0.00',
                'last_fee_accrued_on' => now()->toDateString(),
                'notes' => $notes,
            ]);

            // Transition order → at_office
            $this->transitions->transition(
                order: $order,
                to: OrderStatus::AtOffice,
                actorType: OrderActorType::OfficeStaff,
                actorId: $staff->id,
                metadata: [
                    'event' => 'office_receive_return',
                    'office_id' => $order->return_office_id,
                    'shelf_location' => $shelfLocation,
                    'inventory_id' => $inventory->id,
                ],
            );

            // Snapshot returned_to_office_at (lifecycle audit on orders too — the spec calls for it)
            $order->forceFill(['returned_to_office_at' => now()])->save();

            // Driver financial settlement: earnings credit + debt offset.
            // Driver was on_order during the return leg; flip back to online.
            if ($order->driver_id !== null) {
                $driver = User::query()->findOrFail($order->driver_id);

                // Driver-fault failures absorb the delivery fee — the platform doesn't pay
                // the driver for a failed-because-of-you trip. Spec §6.2.
                if (! in_array($order->return_fault, [ReturnFault::Driver, ReturnFault::Platform], true)) {
                    $this->ledger->applyDeliveryCompletionCredit($driver, $order->refresh());
                }

                DriverProfile::query()
                    ->where('user_id', $driver->id)
                    ->update([
                        'activity_status' => DriverActivityStatus::Online->value,
                        'last_active_at' => now(),
                    ]);
            }

            return $order->refresh()->load(['officeInventory', 'statusLogs', 'driver.driverProfile']);
        });
    }

    /**
     * Office staff confirms seller pickup. Computes owed (delivery_fee if unpaid
     * and fault is sender/receiver, plus accrued storage minus waived), enforces
     * strict cash equality, snapshots financial state on both order and inventory.
     */
    public function retrieve(
        User $staff,
        Order $order,
        string $cashCollected,
        ?string $notes = null,
    ): Order {
        return DB::transaction(function () use ($staff, $order, $cashCollected, $notes): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::AtOffice) {
                throw new OrderDomainException(
                    OrderErrorCode::OrderNotRetrievable,
                    trans('order_messages.order_not_retrievable'),
                );
            }

            $inventory = OfficeInventory::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $accruedStorage = $this->storageFee->compute($inventory);
            $waived = (string) $inventory->retrieval_fees_waived_amount;

            $deliveryFeeOwed = $this->deliveryFeeOwedAtRetrieval($order);
            $totalOwed = bcsub(
                bcadd($deliveryFeeOwed, $accruedStorage, 2),
                $waived,
                2
            );
            if (bccomp($totalOwed, '0.00', 2) === -1) {
                $totalOwed = '0.00';
            }

            $cashNormalised = bcadd($cashCollected, '0', 2);

            if (bccomp($cashNormalised, $totalOwed, 2) === -1) {
                throw new OrderDomainException(
                    OrderErrorCode::InsufficientCashCollected,
                    trans('order_messages.insufficient_cash_collected'),
                    ['owed' => $totalOwed, 'cash_collected' => $cashNormalised],
                );
            }
            if (bccomp($cashNormalised, $totalOwed, 2) === 1) {
                throw new OrderDomainException(
                    OrderErrorCode::ExcessCashCollected,
                    trans('order_messages.excess_cash_collected'),
                    ['owed' => $totalOwed, 'cash_collected' => $cashNormalised],
                );
            }

            // Snapshot inventory
            $inventory->forceFill([
                'accrued_storage_fee' => $accruedStorage,
                'cash_collected_at_retrieval' => $cashNormalised,
                'retrieved_at' => now(),
                'retrieved_by_staff_id' => $staff->id,
                'notes' => $notes ?? $inventory->notes,
            ])->save();

            // Snapshot order
            $orderUpdates = ['storage_fee_accrued' => $accruedStorage];
            if (bccomp($deliveryFeeOwed, '0.00', 2) === 1) {
                $orderUpdates['delivery_fee_status'] = DeliveryFeeStatus::Paid->value;
                $orderUpdates['delivery_fee_paid_at'] = now();
            }
            $order->forceFill($orderUpdates)->save();

            // Transition
            $this->transitions->transition(
                order: $order->refresh(),
                to: OrderStatus::RetrievedBySeller,
                actorType: OrderActorType::OfficeStaff,
                actorId: $staff->id,
                metadata: [
                    'event' => 'office_retrieve',
                    'cash_collected' => $cashNormalised,
                    'delivery_fee_owed' => $deliveryFeeOwed,
                    'storage_fee' => $accruedStorage,
                    'waived' => $waived,
                ],
            );

            return $order->refresh()->load(['officeInventory', 'statusLogs']);
        });
    }

    /**
     * Admin re-routes a returning order to a different office.
     * Does NOT change status. Writes a silent audit row.
     */
    public function redirectReturn(
        User $admin,
        Order $order,
        OfficeLocation $office,
        ?string $reason = null,
    ): Order {
        if ($order->status !== OrderStatus::ReturningToOffice) {
            throw new OrderDomainException(
                OrderErrorCode::OrderNotRedirectable,
                trans('order_messages.order_not_redirectable'),
            );
        }
        if (! $office->is_active) {
            throw new OrderDomainException(
                OrderErrorCode::OfficeInactive,
                trans('order_messages.office_inactive'),
            );
        }

        return DB::transaction(function () use ($admin, $order, $office, $reason): Order {
            $previousOfficeId = $order->return_office_id;
            $order->forceFill(['return_office_id' => $office->id])->save();

            OrderStatusLog::create([
                'order_id' => $order->id,
                'from_status' => OrderStatus::ReturningToOffice->value,
                'to_status' => OrderStatus::ReturningToOffice->value,
                'actor_type' => OrderActorType::Admin->value,
                'actor_id' => $admin->id,
                'reason' => $reason,
                'metadata' => [
                    'event' => 'return_office_redirected',
                    'previous_office_id' => $previousOfficeId,
                    'new_office_id' => $office->id,
                ],
            ]);

            return $order->refresh()->load(['statusLogs']);
        });
    }

    /**
     * Admin waives some or all retrieval fees for an at_office order.
     * Updates office_inventory.retrieval_fees_waived_amount and writes a silent audit row.
     */
    public function waiveRetrievalFees(
        User $admin,
        Order $order,
        string $amount,
        ?string $reason = null,
    ): Order {
        if ($order->status !== OrderStatus::AtOffice) {
            throw new OrderDomainException(
                OrderErrorCode::OrderNotWaivable,
                trans('order_messages.order_not_waivable'),
            );
        }

        return DB::transaction(function () use ($admin, $order, $amount, $reason): Order {
            $inventory = OfficeInventory::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $normalised = bcadd($amount, '0', 2);
            if (bccomp($normalised, '0.00', 2) === -1) {
                $normalised = '0.00';
            }

            $inventory->forceFill(['retrieval_fees_waived_amount' => $normalised])->save();

            OrderStatusLog::create([
                'order_id' => $order->id,
                'from_status' => OrderStatus::AtOffice->value,
                'to_status' => OrderStatus::AtOffice->value,
                'actor_type' => OrderActorType::Admin->value,
                'actor_id' => $admin->id,
                'reason' => $reason,
                'metadata' => [
                    'event' => 'retrieval_fees_waived',
                    'amount' => $normalised,
                ],
            ]);

            return $order->refresh()->load(['officeInventory', 'statusLogs']);
        });
    }

    /**
     * Daily cron path: flip a stale at_office order to abandoned.
     */
    public function abandonStale(Order $order): bool
    {
        if ($order->status !== OrderStatus::AtOffice) {
            return false;
        }

        return DB::transaction(function () use ($order): bool {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== OrderStatus::AtOffice) {
                return false;
            }

            $inventory = OfficeInventory::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $accrued = $this->storageFee->compute($inventory);

            $inventory->forceFill([
                'accrued_storage_fee' => $accrued,
                'abandoned_at' => now(),
                // abandoned_by_admin_id stays null — system actor
            ])->save();

            $order->forceFill(['storage_fee_accrued' => $accrued])->save();

            $this->transitions->transition(
                order: $order->refresh(),
                to: OrderStatus::Abandoned,
                actorType: OrderActorType::System,
                actorId: null,
                metadata: [
                    'event' => 'abandonment_cron',
                    'accrued_storage_fee' => $accrued,
                    'received_at' => $inventory->received_at->toIso8601String(),
                ],
            );

            return true;
        });
    }

    /**
     * Shared core for both markDeliveryFailedByDriver and markDeliveryFailedByAdmin.
     */
    private function markFailedCore(
        Order $order,
        User $actor,
        OrderActorType $actorType,
        ReturnReason $reason,
        ?string $notes,
    ): Order {
        return DB::transaction(function () use ($order, $actor, $actorType, $reason, $notes): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, self::POST_PICKUP_STATES, true)) {
                throw new OrderDomainException(
                    OrderErrorCode::OrderNotFailable,
                    trans('order_messages.order_not_failable'),
                );
            }

            $fault = $this->faultFromReason($reason);
            $office = $this->officeResolver->resolveForPickup($order->pickup_location);

            $order->forceFill([
                'return_reason' => $reason->value,
                'return_fault' => $fault->value,
                'return_office_id' => $office->id,
            ])->save();

            // Transition to delivery_failed
            $this->transitions->transition(
                order: $order,
                to: OrderStatus::DeliveryFailed,
                actorType: $actorType,
                actorId: $actor->id,
                reason: $notes,
                metadata: [
                    'event' => 'mark_delivery_failed',
                    'return_reason' => $reason->value,
                    'return_fault' => $fault->value,
                    'return_office_id' => $office->id,
                ],
            );

            // Auto-chain to returning_to_office
            $this->transitions->transition(
                order: $order->refresh(),
                to: OrderStatus::ReturningToOffice,
                actorType: OrderActorType::System,
                actorId: null,
                metadata: ['event' => 'auto_chain_after_failure'],
            );

            return $order->refresh()->load(['statusLogs', 'driver.driverProfile']);
        });
    }

    private function faultFromReason(ReturnReason $reason): ReturnFault
    {
        return match ($reason) {
            ReturnReason::ReceiverRefused,
            ReturnReason::ReceiverUnreachable => ReturnFault::Receiver,
            ReturnReason::AddressInvalid => ReturnFault::Sender,
            ReturnReason::ItemDamaged,
            ReturnReason::DriverFault => ReturnFault::Driver,
        };
    }

    private function deliveryFeeOwedAtRetrieval(Order $order): string
    {
        // Owed only if the fee is still unpaid AND fault was sender/receiver.
        // Sender pre-paid scenarios already have status=Paid; driver/platform fault → free.
        if ($order->delivery_fee_status === DeliveryFeeStatus::Paid) {
            return '0.00';
        }
        if (! in_array($order->return_fault, [ReturnFault::Sender, ReturnFault::Receiver], true)) {
            return '0.00';
        }

        return (string) $order->delivery_fee;
    }
}
```

- [ ] **Step 8.2: Sanity-check imports and class is resolvable.**

```bash
php -l app/Services/Order/FailedDeliveryService.php
php artisan tinker --execute="echo class_exists('App\Services\Order\FailedDeliveryService', true) ? '1' : '0';"
```

Expected:
```
No syntax errors detected
1
```

---

## Task 9: FormRequests (7 new)

**Files:**
- Create: `app/Http/Requests/Order/MarkDeliveryFailedRequest.php`
- Create: `app/Http/Requests/Order/AdminMarkDeliveryFailedRequest.php`
- Create: `app/Http/Requests/Order/ReceiveReturnRequest.php`
- Create: `app/Http/Requests/Order/RetrieveOrderRequest.php`
- Create: `app/Http/Requests/Order/RedirectReturnRequest.php`
- Create: `app/Http/Requests/Order/WaiveRetrievalFeesRequest.php`
- Create: `app/Http/Requests/Order/OfficeOrdersListRequest.php`

- [ ] **Step 9.1: `MarkDeliveryFailedRequest`.**

File: `app/Http/Requests/Order/MarkDeliveryFailedRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\ReturnReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MarkDeliveryFailedRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(ReturnReason::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

- [ ] **Step 9.2: `AdminMarkDeliveryFailedRequest`.**

File: `app/Http/Requests/Order/AdminMarkDeliveryFailedRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\ReturnReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AdminMarkDeliveryFailedRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(ReturnReason::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

- [ ] **Step 9.3: `ReceiveReturnRequest`.**

File: `app/Http/Requests/Order/ReceiveReturnRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class ReceiveReturnRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shelf_location' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

- [ ] **Step 9.4: `RetrieveOrderRequest`.**

File: `app/Http/Requests/Order/RetrieveOrderRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class RetrieveOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cash_collected' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function normalizedCashCollected(): string
    {
        return bcadd((string) $this->input('cash_collected'), '0', 2);
    }
}
```

- [ ] **Step 9.5: `RedirectReturnRequest`.**

File: `app/Http/Requests/Order/RedirectReturnRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class RedirectReturnRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'office_id' => ['required', 'integer', 'exists:office_locations,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
```

- [ ] **Step 9.6: `WaiveRetrievalFeesRequest`.**

File: `app/Http/Requests/Order/WaiveRetrievalFeesRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class WaiveRetrievalFeesRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
```

- [ ] **Step 9.7: `OfficeOrdersListRequest`.**

File: `app/Http/Requests/Order/OfficeOrdersListRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OfficeOrdersListRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in([
                OrderStatus::ReturningToOffice->value,
                OrderStatus::AtOffice->value,
                OrderStatus::RetrievedBySeller->value,
                OrderStatus::Abandoned->value,
            ])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
```

- [ ] **Step 9.8: Verify all 7 FormRequests compile.**

```bash
for f in MarkDeliveryFailedRequest AdminMarkDeliveryFailedRequest ReceiveReturnRequest RetrieveOrderRequest RedirectReturnRequest WaiveRetrievalFeesRequest OfficeOrdersListRequest; do
    php -l "app/Http/Requests/Order/${f}.php"
done
```

Expected: 7 `No syntax errors detected` lines.

---

## Task 10: Resources

**Files:**
- Modify: `app/Http/Resources/Order/OrderResource.php` (add `return` block when status is in failure chain)
- Create: `app/Http/Resources/Order/OfficeInventoryResource.php`
- Create: `app/Http/Resources/Order/OfficeOrderResource.php`

- [ ] **Step 10.1: Create `OfficeInventoryResource`.**

File: `app/Http/Resources/Order/OfficeInventoryResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Models\OfficeInventory;
use App\Services\Order\StorageFeeCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OfficeInventoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OfficeInventory $i */
        $i = $this->resource;

        return [
            'id' => $i->public_id,
            'office_id' => $i->office_id,
            'received_by_staff_id' => $i->received_by_staff_id,
            'received_at' => $i->received_at?->toIso8601String(),
            'shelf_location' => $i->shelf_location,
            'accrued_storage_fee_snapshot' => (string) $i->accrued_storage_fee,
            'accrued_storage_fee_live' => $i->retrieved_at === null && $i->abandoned_at === null
                ? app(StorageFeeCalculator::class)->compute($i)
                : (string) $i->accrued_storage_fee,
            'retrieval_fees_waived_amount' => (string) $i->retrieval_fees_waived_amount,
            'cash_collected_at_retrieval' => (string) $i->cash_collected_at_retrieval,
            'retrieved_at' => $i->retrieved_at?->toIso8601String(),
            'retrieved_by_staff_id' => $i->retrieved_by_staff_id,
            'abandoned_at' => $i->abandoned_at?->toIso8601String(),
            'abandoned_by_admin_id' => $i->abandoned_by_admin_id,
            'disposal_notes' => $i->disposal_notes,
            'notes' => $i->notes,
        ];
    }
}
```

- [ ] **Step 10.2: Create `OfficeOrderResource`.**

File: `app/Http/Resources/Order/OfficeOrderResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Order;

use App\Enums\DeliveryFeeStatus;
use App\Enums\OrderStatus;
use App\Enums\ReturnFault;
use App\Models\Order;
use App\Services\Order\StorageFeeCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OfficeOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $o */
        $o = $this->resource;
        $inv = $o->officeInventory;

        $deliveryFeeOwed = $this->computeDeliveryFeeOwed($o);
        $storageFee = $inv !== null && $inv->retrieved_at === null && $inv->abandoned_at === null
            ? app(StorageFeeCalculator::class)->compute($inv)
            : ($inv !== null ? (string) $inv->accrued_storage_fee : '0.00');
        $waived = $inv !== null ? (string) $inv->retrieval_fees_waived_amount : '0.00';
        $totalOwed = bcsub(bcadd($deliveryFeeOwed, $storageFee, 2), $waived, 2);
        if (bccomp($totalOwed, '0.00', 2) === -1) {
            $totalOwed = '0.00';
        }

        return [
            'id' => $o->public_id,
            'status' => $o->status->value,
            'order_type' => $o->order_type->value,
            'item' => [
                'description' => $o->item_description,
                'size' => $o->item_size->value,
                'price' => (string) $o->item_price,
            ],
            'sender' => [
                'user_id' => $o->sender_user_id,
                'name' => $o->sender_name,
                'phone' => $o->sender_phone,
            ],
            'pickup_address' => $o->pickup_address,
            'receiver_address' => $o->receiver_address,
            'return' => [
                'office_id' => $o->return_office_id,
                'reason' => $o->return_reason?->value,
                'fault' => $o->return_fault?->value,
                'delivery_failed_at' => $o->delivery_failed_at?->toIso8601String(),
                'returning_to_office_at' => $o->returning_to_office_at?->toIso8601String(),
                'returned_to_office_at' => $o->returned_to_office_at?->toIso8601String(),
                'at_office_at' => $o->at_office_at?->toIso8601String(),
            ],
            'retrieval_owed' => [
                'delivery_fee' => $deliveryFeeOwed,
                'storage_fee_live' => $storageFee,
                'waived' => $waived,
                'total' => $totalOwed,
            ],
            'inventory' => $inv ? (new OfficeInventoryResource($inv))->toArray($request) : null,
            'driver_id' => $o->driver_id,
        ];
    }

    private function computeDeliveryFeeOwed(Order $o): string
    {
        if ($o->delivery_fee_status === DeliveryFeeStatus::Paid) {
            return '0.00';
        }
        if (! in_array($o->return_fault, [ReturnFault::Sender, ReturnFault::Receiver], true)) {
            return '0.00';
        }

        return (string) $o->delivery_fee;
    }
}
```

- [ ] **Step 10.3: Extend `OrderResource` with a `return` block.**

File: `app/Http/Resources/Order/OrderResource.php`

Locate the existing `toArray` body. Find the spot where the sender + receiver + driver blocks are assembled. Append a `return` key conditionally — show it whenever the order's status is in the failure chain:

```php
// inside toArray(), after the existing 'driver' block:
'return' => $this->returnBlock($o, $isSender, $isReceiver),
```

Add a new private method to the class:

```php
/** @return array<string, mixed>|null */
private function returnBlock(\App\Models\Order $o, bool $isSender, bool $isReceiver): ?array
{
    $failureChain = [
        \App\Enums\OrderStatus::DeliveryFailed,
        \App\Enums\OrderStatus::ReturningToOffice,
        \App\Enums\OrderStatus::AtOffice,
        \App\Enums\OrderStatus::RetrievedBySeller,
        \App\Enums\OrderStatus::Abandoned,
    ];

    if (! in_array($o->status, $failureChain, true)) {
        return null;
    }

    // Senders see retrieval-owed live; receivers only see the failed fact.
    $base = [
        'reason' => $o->return_reason?->value,
        'fault' => $o->return_fault?->value,
        'office_id' => $o->return_office_id,
        'returned_to_office_at' => $o->returned_to_office_at?->toIso8601String(),
        'retrieved_by_seller_at' => $o->retrieved_by_seller_at?->toIso8601String(),
        'abandoned_at' => $o->abandoned_at?->toIso8601String(),
        'storage_fee_accrued' => (string) $o->storage_fee_accrued,
    ];

    if ($isSender && $o->status === \App\Enums\OrderStatus::AtOffice) {
        // JIT compute owed for the sender's pickup-trip planning
        $inv = $o->officeInventory;
        if ($inv !== null) {
            $storage = app(\App\Services\Order\StorageFeeCalculator::class)->compute($inv);
            $delivery = ($o->delivery_fee_status !== \App\Enums\DeliveryFeeStatus::Paid
                && in_array($o->return_fault, [\App\Enums\ReturnFault::Sender, \App\Enums\ReturnFault::Receiver], true))
                ? (string) $o->delivery_fee
                : '0.00';
            $waived = (string) $inv->retrieval_fees_waived_amount;
            $total = bcsub(bcadd($delivery, $storage, 2), $waived, 2);
            if (bccomp($total, '0.00', 2) === -1) {
                $total = '0.00';
            }
            $base['owed_at_retrieval'] = [
                'delivery_fee' => $delivery,
                'storage_fee_live' => $storage,
                'waived' => $waived,
                'total' => $total,
            ];
        }
    }

    return $base;
}
```

- [ ] **Step 10.4: Class-existence sanity.**

```bash
php artisan tinker --execute="echo class_exists('App\Http\Resources\Order\OfficeInventoryResource', true) ? '1' : '0'; echo class_exists('App\Http\Resources\Order\OfficeOrderResource', true) ? '1' : '0';"
```

Expected: `11`.

---

## Task 11: Driver controller — `mark-delivery-failed`

**Files:**
- Create: `app/Http/Controllers/Api/Driver/Order/MarkDeliveryFailedController.php`

- [ ] **Step 11.1: Create the invokable controller.**

File: `app/Http/Controllers/Api/Driver/Order/MarkDeliveryFailedController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver\Order;

use App\Enums\ReturnReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\MarkDeliveryFailedRequest;
use App\Http\Resources\Order\DriverOrderResource;
use App\Models\Order;
use App\Services\Order\FailedDeliveryService;

final class MarkDeliveryFailedController extends Controller
{
    public function __construct(private readonly FailedDeliveryService $failures) {}

    public function __invoke(MarkDeliveryFailedRequest $request, Order $order): DriverOrderResource
    {
        $this->authorize('markDeliveryFailedByDriver', $order);

        $updated = $this->failures->markDeliveryFailedByDriver(
            driver: $request->user(),
            order: $order,
            reason: ReturnReason::from((string) $request->input('reason')),
            notes: $request->input('notes'),
        );

        return new DriverOrderResource($updated);
    }
}
```

---

## Task 12: Office controllers (`/api/office/orders/*`)

**Files:**
- Create: `app/Http/Controllers/Api/Office/Order/OrderController.php` (index + show)
- Create: `app/Http/Controllers/Api/Office/Order/ReceiveReturnController.php` (invokable)
- Create: `app/Http/Controllers/Api/Office/Order/RetrieveOrderController.php` (invokable)

- [ ] **Step 12.1: `OrderController` (index + show).**

File: `app/Http/Controllers/Api/Office/Order/OrderController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Order;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\OfficeOrdersListRequest;
use App\Http\Resources\Order\OfficeOrderResource;
use App\Models\Order;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OrderController extends Controller
{
    public function index(OfficeOrdersListRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $assignedOfficeIds = $user->officeStaffAssignments()
            ->active()
            ->pluck('office_id')
            ->all();

        $query = Order::query()
            ->whereIn('return_office_id', $assignedOfficeIds);

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        } else {
            // Default: show inbound + on-shelf orders for this staff's offices
            $query->whereIn('status', [
                OrderStatus::ReturningToOffice->value,
                OrderStatus::AtOffice->value,
            ]);
        }

        $orders = $query
            ->with(['officeInventory', 'driver.driverProfile'])
            ->orderByDesc('status_changed_at')
            ->paginate((int) $request->input('per_page', 30));

        return OfficeOrderResource::collection($orders);
    }

    public function show(Order $order): OfficeOrderResource
    {
        $this->authorize('viewByOffice', $order);
        $order->load(['officeInventory', 'driver.driverProfile', 'statusLogs']);

        return new OfficeOrderResource($order);
    }
}
```

- [ ] **Step 12.2: `ReceiveReturnController`.**

File: `app/Http/Controllers/Api/Office/Order/ReceiveReturnController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ReceiveReturnRequest;
use App\Http\Resources\Order\OfficeOrderResource;
use App\Models\Order;
use App\Services\Order\FailedDeliveryService;

final class ReceiveReturnController extends Controller
{
    public function __construct(private readonly FailedDeliveryService $failures) {}

    public function __invoke(ReceiveReturnRequest $request, Order $order): OfficeOrderResource
    {
        $this->authorize('receiveReturnByOffice', $order);

        $updated = $this->failures->receiveReturn(
            staff: $request->user(),
            order: $order,
            shelfLocation: $request->input('shelf_location'),
            notes: $request->input('notes'),
        );

        return new OfficeOrderResource($updated);
    }
}
```

- [ ] **Step 12.3: `RetrieveOrderController`.**

File: `app/Http/Controllers/Api/Office/Order/RetrieveOrderController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\RetrieveOrderRequest;
use App\Http\Resources\Order\OfficeOrderResource;
use App\Models\Order;
use App\Services\Order\FailedDeliveryService;

final class RetrieveOrderController extends Controller
{
    public function __construct(private readonly FailedDeliveryService $failures) {}

    public function __invoke(RetrieveOrderRequest $request, Order $order): OfficeOrderResource
    {
        $this->authorize('retrieveByOffice', $order);

        $updated = $this->failures->retrieve(
            staff: $request->user(),
            order: $order,
            cashCollected: $request->normalizedCashCollected(),
            notes: $request->input('notes'),
        );

        return new OfficeOrderResource($updated);
    }
}
```

---

## Task 13: Admin endpoint extensions

**Files:**
- Modify: `app/Http/Controllers/Api/Admin/OrderController.php` (add 3 new methods)

- [ ] **Step 13.1: Add 3 admin methods.**

File: `app/Http/Controllers/Api/Admin/OrderController.php`

Inject `FailedDeliveryService` into the constructor (alongside existing dependencies). If the file currently uses `AdminAssignmentService` only, extend the constructor:

```php
public function __construct(
    private readonly AdminAssignmentService $admin,
    private readonly FailedDeliveryService $failures,
    // ... other existing deps
) {}
```

Add three new public methods:

```php
public function markDeliveryFailed(AdminMarkDeliveryFailedRequest $request, Order $order): AdminOrderResource
{
    $updated = $this->failures->markDeliveryFailedByAdmin(
        admin: $request->user(),
        order: $order,
        reason: ReturnReason::from((string) $request->input('reason')),
        notes: $request->input('notes'),
    );
    $updated->load(['statusLogs', 'driver.driverProfile']);

    return new AdminOrderResource($updated);
}

public function redirectReturn(RedirectReturnRequest $request, Order $order): AdminOrderResource
{
    $office = OfficeLocation::findOrFail((int) $request->input('office_id'));
    $updated = $this->failures->redirectReturn(
        admin: $request->user(),
        order: $order,
        office: $office,
        reason: $request->input('reason'),
    );
    $updated->load(['statusLogs']);

    return new AdminOrderResource($updated);
}

public function waiveRetrievalFees(WaiveRetrievalFeesRequest $request, Order $order): AdminOrderResource
{
    $updated = $this->failures->waiveRetrievalFees(
        admin: $request->user(),
        order: $order,
        amount: (string) $request->input('amount'),
        reason: $request->input('reason'),
    );
    $updated->load(['officeInventory', 'statusLogs']);

    return new AdminOrderResource($updated);
}
```

Add imports at the top of the file:

```php
use App\Enums\ReturnReason;
use App\Http\Requests\Order\AdminMarkDeliveryFailedRequest;
use App\Http\Requests\Order\RedirectReturnRequest;
use App\Http\Requests\Order\WaiveRetrievalFeesRequest;
use App\Models\OfficeLocation;
use App\Services\Order\FailedDeliveryService;
```

(Existing imports for `Order`, `AdminOrderResource`, etc., are unchanged.)

---

## Task 14: Routes + rate limiters

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (add `office_orders_read`, `office_action` limiters)
- Modify: `routes/api.php` (8 new routes)

- [ ] **Step 14.1: Register the two new rate limiters.**

File: `app/Providers/AppServiceProvider.php`

Inside the existing `configureRateLimiters()` method, alongside `orders_quote`, `me_action`, `driver_action`:

```php
RateLimiter::for('office_orders_read', fn (\Illuminate\Http\Request $r) => [
    Limit::perMinute(60)->by((string) optional($r->user())->id),
]);
RateLimiter::for('office_action', fn (\Illuminate\Http\Request $r) => [
    Limit::perMinute(10)->by((string) optional($r->user())->id),
]);
```

- [ ] **Step 14.2: Register the 8 new routes.**

File: `routes/api.php`

Add imports near the top with the existing controller imports:

```php
use App\Http\Controllers\Api\Driver\Order\MarkDeliveryFailedController;
use App\Http\Controllers\Api\Office\Order\OrderController as OfficeOrderController;
use App\Http\Controllers\Api\Office\Order\ReceiveReturnController;
use App\Http\Controllers\Api\Office\Order\RetrieveOrderController;
```

**Driver route** — append inside the existing `Route::middleware(['auth:sanctum', 'role:driver'])->prefix('driver')->group(...)` block, alongside the existing `/orders/{public_id}/confirm-pickup` route:

```php
Route::post('orders/{order:public_id}/mark-delivery-failed', MarkDeliveryFailedController::class)
    ->middleware('throttle:driver_action');
```

**Office routes** — add a new group (parallel to the existing `/office/drivers` group):

```php
Route::middleware(['auth:sanctum', 'role:office_staff'])->prefix('office/orders')->group(function (): void {
    Route::get('/', [OfficeOrderController::class, 'index'])
        ->middleware('throttle:office_orders_read');
    Route::get('{order:public_id}', [OfficeOrderController::class, 'show'])
        ->middleware('throttle:office_orders_read');
    Route::post('{order:public_id}/receive-return', ReceiveReturnController::class)
        ->middleware('throttle:office_action');
    Route::post('{order:public_id}/retrieve', RetrieveOrderController::class)
        ->middleware('throttle:office_action');
});
```

**Admin routes** — append inside the existing `Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/orders')->group(...)` block:

```php
Route::post('{order:public_id}/mark-delivery-failed', [AdminOrderController::class, 'markDeliveryFailed']);
Route::post('{order:public_id}/redirect-return', [AdminOrderController::class, 'redirectReturn']);
Route::post('{order:public_id}/waive-retrieval-fees', [AdminOrderController::class, 'waiveRetrievalFees']);
```

(`AdminOrderController` is the existing alias used in the routes file.)

- [ ] **Step 14.3: Verify all 8 routes register.**

```bash
php artisan route:list --path=api 2>&1 | grep -E "mark-delivery-failed|office/orders|receive-return|retrieve|redirect-return|waive-retrieval"
```

Expected output: 8 lines, one per new endpoint.

---

## Task 15: `AbandonStaleOrdersJob` + scheduler

**Files:**
- Create: `app/Jobs/AbandonStaleOrdersJob.php`
- Modify: `routes/console.php`

- [ ] **Step 15.1: Create the job.**

File: `app/Jobs/AbandonStaleOrdersJob.php`

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Services\Order\FailedDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class AbandonStaleOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(FailedDeliveryService $failures): void
    {
        Cache::lock('orders:abandon:sweep', 90)->block(5, function () use ($failures): void {
            $abandonAfterDays = (int) PlatformSetting::get('storage.abandonment_days', 30);
            $cutoff = now()->subDays($abandonAfterDays);

            $abandoned = 0;
            Order::query()
                ->where('status', OrderStatus::AtOffice->value)
                ->whereHas('officeInventory', fn ($q) => $q->where('received_at', '<=', $cutoff))
                ->with('officeInventory')
                ->cursor()
                ->each(function (Order $order) use ($failures, &$abandoned): void {
                    try {
                        if ($failures->abandonStale($order)) {
                            $abandoned++;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Abandon-stale job failed for order', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });

            Log::info('AbandonStaleOrdersJob complete', ['abandoned_count' => $abandoned]);
        });
    }
}
```

- [ ] **Step 15.2: Register the schedule.**

File: `routes/console.php` — append:

```php
use App\Jobs\AbandonStaleOrdersJob;

Schedule::job(new AbandonStaleOrdersJob())
    ->daily()
    ->withoutOverlapping();
```

- [ ] **Step 15.3: Verify schedule registration.**

```bash
php artisan schedule:list 2>&1 | grep -i abandon
```

Expected: line showing `App\Jobs\AbandonStaleOrdersJob` with `0 0 * * *` (daily) timing.

- [ ] **Step 15.4: Tinker smoke — synthetic abandonment.**

```bash
php artisan tinker --execute="
// Skip if no orders are at_office; this just verifies the job runs without error
app(\App\Jobs\AbandonStaleOrdersJob::class)->handle(app(\App\Services\Order\FailedDeliveryService::class));
echo 'job ran without error';
"
```

Expected: `job ran without error` printed; no exceptions.

---

## Task 16: Extend `scripts/orders-e2e.php` with D scenarios

**Files:**
- Modify: `scripts/orders-e2e.php`

- [ ] **Step 16.1: Add a D-block to the script.**

Append at the end of `scripts/orders-e2e.php` (before any final "all passed" echo, or replace the final echo to include the new count). The block should:

1. Set up a fresh order + driver (reuse the harness already in the script).
2. Drive it through claim → confirm-pickup.
3. Call `mark-delivery-failed` with each reason × fault scenario.
4. Verify return_office_id resolved correctly + status auto-chained.
5. Office staff `receive-return`.
6. Verify driver buckets credited (except driver-fault scenarios).
7. Simulate elapsed time (manipulate `office_inventory.received_at`).
8. Office staff `retrieve` with correct cash.
9. Verify final snapshots + driver state.

A minimal sketch (extend the existing script's patterns):

```php
echo "\n=== D1: Driver-fault failure → driver NOT credited ===\n";
$o = createFreshOrder($sender, $pickup, $dropoff);
$driverFlowToPostPickup($o, $driver, $driverToken);
$resp = http($driverToken, 'POST', "/api/driver/orders/{$o->public_id}/mark-delivery-failed", [
    'reason' => 'driver_fault',
]);
$assert($resp->status() === 200, 'mark-delivery-failed (driver_fault) returns 200');
$o = $o->refresh();
$assert($o->status === OrderStatus::ReturningToOffice, 'auto-chained to returning_to_office');
$assert($o->return_fault === ReturnFault::Driver, 'fault inferred as driver');

$staffToken = $officeStaff->createToken('d-smoke')->plainTextToken;
$beforeEarnings = (string) DriverAccount::where('driver_id', $driver->id)->first()->earnings_balance;
$resp = http($staffToken, 'POST', "/api/office/orders/{$o->public_id}/receive-return", []);
$assert($resp->status() === 200, 'receive-return returns 200');
$afterEarnings = (string) DriverAccount::where('driver_id', $driver->id)->first()->earnings_balance;
$assert(bccomp($afterEarnings, $beforeEarnings, 2) === 0, 'driver-fault → no earnings credit');

echo "\n=== D2: Receiver-refused → driver credited; seller pays delivery+storage ===\n";
$o = createFreshOrder($sender, $pickup, $dropoff);
$driverFlowToPostPickup($o, $driver, $driverToken);
http($driverToken, 'POST', "/api/driver/orders/{$o->public_id}/mark-delivery-failed", ['reason' => 'receiver_refused']);
$beforeEarnings = (string) DriverAccount::where('driver_id', $driver->id)->first()->earnings_balance;
http($staffToken, 'POST', "/api/office/orders/{$o->public_id}/receive-return", []);
$afterEarnings = (string) DriverAccount::where('driver_id', $driver->id)->first()->earnings_balance;
$expectedCredit = bcsub((string)$o->refresh()->delivery_fee, (string)$o->driver_fee_cut_amount, 2);
$assert(bccomp(bcsub($afterEarnings, $beforeEarnings, 2), $expectedCredit, 2) === 0, 'driver earnings credited at at_office');

// Simulate 7 days at office
OfficeInventory::where('order_id', $o->id)->update(['received_at' => now()->subDays(7)]);
$resp = http($staffToken, 'POST', "/api/office/orders/{$o->public_id}/retrieve", [
    'cash_collected' => bcadd((string)$o->delivery_fee, '2.00', 2), // delivery_fee + 2 days × 1 LYD
]);
$assert($resp->status() === 200, 'retrieve returns 200');
$o = $o->refresh();
$assert($o->status === OrderStatus::RetrievedBySeller, 'transitioned to retrieved_by_seller');
$assert(bccomp((string)$o->storage_fee_accrued, '2.00', 2) === 0, 'storage fee snapshotted as 2.00');
$assert($o->delivery_fee_status === DeliveryFeeStatus::Paid, 'delivery_fee_status flipped to paid');

echo "\n=== D3: Insufficient cash → 422 ===\n";
// ... (similar pattern, expect 422)

echo "\n=== D4: Abandonment cron ===\n";
$o = createFreshOrder($sender, $pickup, $dropoff);
$driverFlowToPostPickup($o, $driver, $driverToken);
http($driverToken, 'POST', "/api/driver/orders/{$o->public_id}/mark-delivery-failed", ['reason' => 'address_invalid']);
http($staffToken, 'POST', "/api/office/orders/{$o->public_id}/receive-return", []);
OfficeInventory::where('order_id', $o->id)->update(['received_at' => now()->subDays(31)]);
app(\App\Jobs\AbandonStaleOrdersJob::class)->handle(app(\App\Services\Order\FailedDeliveryService::class));
$o = $o->refresh();
$assert($o->status === OrderStatus::Abandoned, 'cron flipped to abandoned');
$assert(bccomp((string)$o->storage_fee_accrued, '25.00', 2) === 0, 'final storage fee snapshot = 25.00');

echo "\n=== D5: Wrong office for order → 403 ===\n";
// ... (set return_office_id to a different office; expect 403)

echo "\n=== D6: Admin redirect-return + admin waive ===\n";
// ... (test both)

echo "\n=== D7: mark-delivery-failed from picked_up (earliest state) ===\n";
// ... (verify state transition works)

echo "\n=== D8: Excess cash collected → 422 ===\n";
// ... (cash > owed, expect 422)
```

Reuse the script's existing helpers (`http`, `$assert`, fixture setup, rollback wrapper).

- [ ] **Step 16.2: Run the smoke script.**

```bash
php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"
```

Expected: `ALL ORDER E2E SMOKE SCENARIOS PASSED` (or whatever the final echo line is) — all A+B + Slice 10 + new D scenarios green.

If any scenario fails, diagnose, fix, re-run. Don't proceed to Task 17 until green.

---

## Task 17: Pint + docs closeout

**Files:**
- Run: `vendor/bin/pint`
- Modify: `docs/CLAUDE.md` (Current Project State row + endpoint table)
- Modify: `docs/SYSTEM_SPECIFICATION.md` (add §17.10)
- Modify: `docs/superpowers/specs/2026-05-13-failed-delivery-and-return-flow-design.md` (flip status to ✅)
- Modify: `docs/CODEX.md` (append Slice 11 entry, if Codex is the implementer)

- [ ] **Step 17.1: Run Pint.**

```bash
vendor/bin/pint
```

Expected: clean exit, no unfixed warnings.

- [ ] **Step 17.2: Update `docs/CLAUDE.md`.**

In the "Current Project State" block:

- Bump "Last updated" to today.
- Update the status line to mention D shipped.
- Under the "Order lifecycle milestone" section, append a new sub-section: `### Failed delivery + return-to-office milestone (YYYY-MM-DD)` with the 8 new endpoints table + locked decisions + cron notes (mirror the existing "Order lifecycle milestone" formatting).
- Update "Next Steps": remove sub-project D from the list, promote #2 (office staff settlement) to the top.
- Update "Open Questions": remove the resolved D items (storage policy, retrieval payment, abandonment).

- [ ] **Step 17.3: Update `docs/SYSTEM_SPECIFICATION.md` §17.**

Add `### 17.10 Failed delivery + return-to-office milestone (YYYY-MM-DD) ✅` mirroring the §17.8 format. Include:
- Endpoint table (8 routes)
- Locked decisions recap (from spec §3)
- Cross-cutting work
- E2E smoke scenarios verified

Also append to §17.9 "Outstanding Architectural Questions": cross out / move resolved items.

- [ ] **Step 17.4: Flip the D spec status.**

File: `docs/superpowers/specs/2026-05-13-failed-delivery-and-return-flow-design.md`

Replace the header line:

```markdown
**Status:** ✅ Implemented (YYYY-MM-DD)
```

- [ ] **Step 17.5: Append a Slice 11 entry to `docs/CODEX.md`.**

```markdown
### 2026-05-13 Slice 11 - Failed Delivery + Return-to-Office Flow

Implemented sub-project D per `docs/superpowers/specs/2026-05-13-failed-delivery-and-return-flow-design.md` and `docs/superpowers/plans/2026-05-13-failed-delivery-and-return-flow.md`.

Files added: ... [summarize]
Files updated: ... [summarize]
Verification:
- ... [route checks, smoke result, pint, schedule:list, migrate:status]
```

(Match Codex's existing slice-entry format.)

---

## Done

After Task 17, sub-project D is complete. Order lifecycle is now fully covered from creation through every terminal state (delivered, retrieved_by_seller, abandoned, cancelled_by_user, cancelled_by_admin).

**Next milestone candidates** (per CLAUDE.md "Next Steps"):
- **Office staff settlement processing** — drivers come to office to settle `cash_to_deposit` against `earnings_balance` / `debt_balance`. Bigger surface area than D. Will need its own spec/plan cycle.
- **Real-time (Reverb)** — push notifications layered onto the existing `OrderStatusChanged` event. Smaller scope; near-zero changes to existing endpoints.
- **Test infrastructure** — promote Tinker smoke scripts to Pest feature tests against a separate test DB.
