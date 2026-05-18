# Settlement & Seller Payouts — Implementation Plan

> **For agentic workers:** Implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Every task is marked **[OWNER: Claude]** or **[OWNER: Codex]** at the top — both implement, alternating by task. The plan is comprehensive — every step contains the exact code to write or modify. Run the verification commands as you go; do NOT skip them.

**Goal:** Close the cash loop. Office staff settles a driver's three buckets in one atomic transaction. Sellers see their per-order earnings move through `pending_settlement → pending_clearance → available → paid_out` and physically collect cash at any active office. Admins get global visibility and a narrow correcting-settlement capability. 15 endpoints + 1 daily cron + 1 new table + targeted modifications to existing tables.

**Architecture:** Service-layer driven, matching the conventions of A+B+C+D. Three new orchestration services (`SettlementService`, `SellerPayoutService`, `SettlementReversalService`) own all writes for their respective domains. A new `seller_earnings` table (1:1 with sale orders) carries the seller-side lifecycle; the `orders` table stays untouched. The existing `Settlement`, `SellerPayout`, and `DriverAccountLedgerService` are extended to fit the new flow rather than replaced.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL + PostGIS, Sanctum 4, Spatie Permission 7, Redis cache, bcmath. No Pest tests yet — verification via Tinker smoke + the existing `scripts/orders-e2e.php` extended with settlement scenarios.

**Spec:** `docs/superpowers/specs/2026-05-17-settlement-and-seller-payouts-design.md`

**Predecessors:**
- `docs/superpowers/specs/2026-05-12-order-lifecycle-design.md` (A+B + Slice 10 — shipped)
- `docs/superpowers/specs/2026-05-13-failed-delivery-and-return-flow-design.md` (Sub-project D — shipped)

---

## Task Owner Split

| # | Task | Owner |
|---|---|---|
| 1 | Migration: `seller_earnings` table | Codex |
| 2 | Migration: modify `seller_payouts` (drop unused, rename column) | Codex |
| 3 | Migration: `seller_payout_orders` pivot | Codex |
| 4 | Enums: `SellerEarningStatus`, simplify `SellerPayoutStatus`, new `SettlementErrorCode` | Codex |
| 5 | Model: `SellerEarning` (with scopes, casts, relationships) | Codex |
| 6 | Models: update `SellerPayout` + create `SellerPayoutOrder` pivot model | Codex |
| 7 | Seeder: extend `OrderLifecyclePlatformSettingsSeeder` with payout settings | Codex |
| 8 | Extract `isAssignedToOffice` helper + write `SettlementPolicy`, `SellerPayoutPolicy`, `SellerEarningPolicy` | **Claude** |
| 9 | `SettlementService` (preview + process) | **Claude** |
| 10 | `SellerPayoutService` (lookup + process) | **Claude** |
| 11 | `SettlementReversalService` (admin correcting settlement) | **Claude** |
| 12 | `ClearSellerEarningsJob` + cron registration | Codex |
| 13 | `CodeVerificationService` integration (spawn `seller_earnings` on delivery) | **Claude** |
| 14 | FormRequests (7 total) | Codex |
| 15 | JsonResources (6 total) | Codex |
| 16 | Driver settlement history controllers + routes | Codex |
| 17 | Seller earnings + payouts controllers + routes | Codex |
| 18 | Office settlement controllers + routes | **Claude** |
| 19 | Office seller-payouts controllers + routes | **Claude** |
| 20 | Admin settlement + payouts controllers + routes (incl. reversal) | **Claude** |
| 21 | Rate limiters in `AppServiceProvider` | Codex |
| 22 | Localization strings (en + ar) | Codex |
| 23 | Smoke scenarios in `scripts/orders-e2e.php` (19 new scenarios) | **Claude** |
| 24 | Doc updates (CLAUDE.md, SYSTEM_SPECIFICATION.md, CODEX.md) | **Claude** |

**Claude tasks (10):** 8, 9, 10, 11, 13, 18, 19, 20, 23, 24
**Codex tasks (14):** 1, 2, 3, 4, 5, 6, 7, 12, 14, 15, 16, 17, 21, 22

---

## File Structure

```
NEW
├── database/migrations/
│   ├── 2026_05_17_000100_create_seller_earnings_table.php
│   ├── 2026_05_17_000200_simplify_seller_payouts_table.php
│   └── 2026_05_17_000300_create_seller_payout_orders_table.php
│
├── app/Models/
│   ├── SellerEarning.php
│   └── SellerPayoutOrder.php
│
├── app/Enums/
│   ├── SellerEarningStatus.php
│   └── SettlementErrorCode.php
│
├── app/Policies/
│   ├── SettlementPolicy.php
│   ├── SellerPayoutPolicy.php
│   └── SellerEarningPolicy.php
│
├── app/Services/Settlement/
│   ├── SettlementService.php
│   ├── SettlementReversalService.php
│   └── SellerPayoutService.php
│
├── app/Jobs/
│   └── ClearSellerEarningsJob.php
│
├── app/Http/Requests/Settlement/
│   ├── ProcessSettlementRequest.php
│   ├── ReverseSettlementRequest.php
│   ├── LookupSellerPayoutRequest.php
│   ├── ProcessSellerPayoutRequest.php
│   ├── ListSettlementsRequest.php          (admin filters)
│   ├── ListSellerPayoutsRequest.php        (admin filters)
│   └── ListOfficeSettlementsRequest.php
│
├── app/Http/Resources/Settlement/
│   ├── SettlementResource.php              (driver/admin view — full)
│   ├── SettlementPreviewResource.php       (office staff — preview)
│   ├── SellerEarningResource.php           (seller dashboard / office lookup row)
│   ├── SellerEarningsSummaryResource.php   (seller dashboard top — totals + breakdown)
│   ├── SellerPayoutResource.php            (receipt detail — seller/office)
│   └── AdminSellerPayoutResource.php       (admin view — full)
│
├── app/Http/Controllers/Api/Driver/Settlement/
│   ├── ListSettlementsController.php
│   └── ShowSettlementController.php
│
├── app/Http/Controllers/Api/Office/Settlement/
│   ├── PreviewSettlementController.php
│   ├── ProcessSettlementController.php
│   ├── ListSettlementsController.php
│   ├── LookupSellerPayoutController.php
│   ├── ProcessSellerPayoutController.php
│   └── ListSellerPayoutsController.php
│
├── app/Http/Controllers/Api/Me/Settlement/
│   ├── ShowEarningsController.php
│   ├── ListSellerPayoutsController.php
│   └── ShowSellerPayoutController.php
│
└── app/Http/Controllers/Api/Admin/Settlement/
    ├── ListSettlementsController.php
    ├── ShowSettlementController.php
    ├── ReverseSettlementController.php
    └── ListSellerPayoutsController.php

MODIFIED
├── app/Enums/SellerPayoutStatus.php           (drop unused cases, keep Paid + Cancelled)
├── app/Models/SellerPayout.php                (rename relationship, drop unused fields, add pivot relation)
├── app/Models/User.php                        (add isAssignedToOffice helper + sellerEarnings relation)
├── app/Models/Order.php                       (add sellerEarning HasOne relation)
├── app/Policies/OrderPolicy.php               (delegate orderInUsersOffice to User::isAssignedToOffice)
├── app/Services/Order/CodeVerificationService.php   (spawn seller_earnings on delivery success)
├── app/Providers/AppServiceProvider.php       (3 new rate limiters)
├── database/seeders/OrderLifecyclePlatformSettingsSeeder.php  (4 new settings)
├── routes/api.php                             (15 new routes)
├── routes/console.php                         (register ClearSellerEarningsJob daily)
├── lang/en/order_messages.php                 (new keys for settlement errors)
├── lang/ar/order_messages.php                 (Arabic translations)
└── scripts/orders-e2e.php                     (19 new smoke scenarios)
```

---

## Task 1: Migration — `seller_earnings` table

**[OWNER: Codex]**

**Files:**
- Create: `database/migrations/2026_05_17_000100_create_seller_earnings_table.php`

- [ ] **Step 1.1: Create the migration file**

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
        // 1:1 with sale orders (order_type IN p2p_sale, merchant_delivery AND item_price > 0).
        // Row is created at delivery success by CodeVerificationService, NEVER at order creation.
        // Carries the seller-side lifecycle so the wide `orders` table doesn't have to.
        Schema::create('seller_earnings', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('order_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            // Denormalized from orders.sender_user_id for fast (seller_user_id, status) queries.
            $table->foreignId('seller_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Snapshot of item_price - commission_amount at row creation.
            $table->decimal('amount', 12, 2);

            // pending_settlement | pending_clearance | available | paid_out
            $table->string('status')->index();

            $table->timestamp('cleared_at')->nullable();     // settlement → pending_clearance
            $table->timestamp('available_at')->nullable();   // cron → available
            $table->timestamp('paid_out_at')->nullable();

            $table->foreignId('paid_by_staff_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignId('seller_payout_id')->nullable()
                ->constrained('seller_payouts')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['seller_user_id', 'status'], 'seller_earnings_seller_status_idx');
            $table->index(['status', 'cleared_at'], 'seller_earnings_status_cleared_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_earnings');
    }
};
```

- [ ] **Step 1.2: Run the migration**

Run: `php artisan migrate`
Expected: `2026_05_17_000100_create_seller_earnings_table .................. DONE`

- [ ] **Step 1.3: Verify the table exists**

Run: `php artisan tinker --execute="dd(\Schema::hasTable('seller_earnings'), \Schema::getColumnListing('seller_earnings'));"`
Expected: `true` and the 13 columns listed.

- [ ] **Step 1.4: Commit**

```bash
git add database/migrations/2026_05_17_000100_create_seller_earnings_table.php
git commit -m "feat(settlement): add seller_earnings table for per-order seller lifecycle"
```

---

## Task 2: Migration — Simplify `seller_payouts`

**[OWNER: Codex]**

The existing table was scaffolded for a request/approve/pay flow. The simplified flow has no requests and no approvals — the office staff creates the row at the moment of cash handover.

**Files:**
- Create: `database/migrations/2026_05_17_000200_simplify_seller_payouts_table.php`

- [ ] **Step 2.1: Create the migration**

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
        Schema::table('seller_payouts', function (Blueprint $table) {
            // Drop the request/approval workflow — payout is now created at moment of cash handover.
            $table->dropForeign(['approved_by_admin_id']);
            $table->dropColumn(['approved_at', 'approved_by_admin_id']);

            $table->dropForeign(['rejected_by_admin_id']);
            $table->dropColumn(['rejected_at', 'rejected_by_admin_id', 'rejection_reason']);

            $table->dropColumn('requested_at');

            // Rename: office staff can pay (not just admin).
            $table->renameColumn('paid_by_admin_id', 'paid_by_staff_id');
        });

        // paid_at default = now() makes the row's birth time the cash-handover time.
        Schema::table('seller_payouts', function (Blueprint $table) {
            $table->timestamp('paid_at')->useCurrent()->change();
        });
    }

    public function down(): void
    {
        Schema::table('seller_payouts', function (Blueprint $table) {
            $table->renameColumn('paid_by_staff_id', 'paid_by_admin_id');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('paid_at')->nullable()->change();
        });
    }
};
```

- [ ] **Step 2.2: Run the migration**

Run: `php artisan migrate`
Expected: `2026_05_17_000200_simplify_seller_payouts_table .................. DONE`

- [ ] **Step 2.3: Verify**

Run: `php artisan tinker --execute="dd(\Schema::getColumnListing('seller_payouts'));"`
Expected: column list should NOT contain `approved_at`, `approved_by_admin_id`, `rejected_at`, `rejected_by_admin_id`, `rejection_reason`, `requested_at`. Should contain `paid_by_staff_id` (not `paid_by_admin_id`).

- [ ] **Step 2.4: Commit**

```bash
git add database/migrations/2026_05_17_000200_simplify_seller_payouts_table.php
git commit -m "refactor(settlement): simplify seller_payouts schema — drop request/approval flow"
```

---

## Task 3: Migration — `seller_payout_orders` pivot

**[OWNER: Codex]**

**Files:**
- Create: `database/migrations/2026_05_17_000300_create_seller_payout_orders_table.php`

- [ ] **Step 3.1: Create the migration**

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
        // Pivot: which orders' proceeds were paid out in a single seller_payout transaction.
        // Each row links a payout to one order with the contributed amount.
        Schema::create('seller_payout_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_payout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount_contributed', 12, 2);
            $table->timestamps();

            $table->unique(['seller_payout_id', 'order_id']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_payout_orders');
    }
};
```

- [ ] **Step 3.2: Run and verify**

Run: `php artisan migrate`
Expected: `DONE`

Run: `php artisan tinker --execute="dd(\Schema::hasTable('seller_payout_orders'));"`
Expected: `true`

- [ ] **Step 3.3: Commit**

```bash
git add database/migrations/2026_05_17_000300_create_seller_payout_orders_table.php
git commit -m "feat(settlement): add seller_payout_orders pivot"
```

---

## Task 4: Enums — `SellerEarningStatus`, simplify `SellerPayoutStatus`, new `SettlementErrorCode`

**[OWNER: Codex]**

**Files:**
- Create: `app/Enums/SellerEarningStatus.php`
- Create: `app/Enums/SettlementErrorCode.php`
- Modify: `app/Enums/SellerPayoutStatus.php` (drop unused cases)

- [ ] **Step 4.1: Read the existing SellerPayoutStatus**

Run: `cat app/Enums/SellerPayoutStatus.php`
Note the cases that exist.

- [ ] **Step 4.2: Create `SellerEarningStatus`**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a sale order's seller-side earning.
 *
 * pending_settlement → cash is with the driver (delivered but unsettled)
 * pending_clearance  → driver settled at office; 48h clearance window in progress
 * available          → seller can collect at any active office
 * paid_out           → terminal; cash handed over
 */
enum SellerEarningStatus: string
{
    case PendingSettlement = 'pending_settlement';
    case PendingClearance = 'pending_clearance';
    case Available = 'available';
    case PaidOut = 'paid_out';

    public function label(): string
    {
        return match ($this) {
            self::PendingSettlement => 'Pending Settlement',
            self::PendingClearance => 'Pending Clearance',
            self::Available => 'Available',
            self::PaidOut => 'Paid Out',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::PaidOut;
    }
}
```

- [ ] **Step 4.3: Simplify `SellerPayoutStatus`**

Replace the entire file:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of a seller payout receipt.
 *
 * In the simplified flow (post-2026-05-17), the row is created at the
 * moment of cash handover, so `Paid` is the only normal-flow value.
 * `Cancelled` is reserved for an admin correcting-payout flow that is
 * out of scope for the current milestone.
 */
enum SellerPayoutStatus: string
{
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isFinal(): bool
    {
        return true;
    }
}
```

- [ ] **Step 4.4: Create `SettlementErrorCode`**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stable, client-readable error codes for settlement and seller-payout endpoints.
 * Mapped to HTTP status codes via httpStatus(). Mirrors the OrderErrorCode pattern.
 */
enum SettlementErrorCode: string
{
    case SettlementExcessRejected = 'SETTLEMENT_EXCESS_REJECTED';
    case SettlementEmpty = 'SETTLEMENT_EMPTY';
    case SettlementCashMismatch = 'SETTLEMENT_CASH_MISMATCH';
    case SettlementNotReversible = 'SETTLEMENT_NOT_REVERSIBLE';
    case SettlementAlreadyReversed = 'SETTLEMENT_ALREADY_REVERSED';

    case PayoutEarningNotAvailable = 'PAYOUT_EARNING_NOT_AVAILABLE';
    case PayoutEarningWrongSeller = 'PAYOUT_EARNING_WRONG_SELLER';
    case PayoutTotalMismatch = 'PAYOUT_TOTAL_MISMATCH';
    case PayoutBelowMinimum = 'PAYOUT_BELOW_MINIMUM';
    case PayoutEmptySelection = 'PAYOUT_EMPTY_SELECTION';

    case OfficeNotAssigned = 'OFFICE_NOT_ASSIGNED';
    case SellerNotFound = 'SELLER_NOT_FOUND';
    case DriverNotFound = 'DRIVER_NOT_FOUND';

    public function httpStatus(): int
    {
        return match ($this) {
            self::SellerNotFound,
            self::DriverNotFound => 404,

            self::OfficeNotAssigned => 403,

            self::SettlementAlreadyReversed => 409,

            default => 422,
        };
    }
}
```

- [ ] **Step 4.5: Verify enums load**

Run: `php artisan tinker --execute="dd(\App\Enums\SellerEarningStatus::cases(), \App\Enums\SellerPayoutStatus::cases(), \App\Enums\SettlementErrorCode::SettlementExcessRejected->httpStatus());"`
Expected: 4 earning statuses, 2 payout statuses, integer 422.

- [ ] **Step 4.6: Commit**

```bash
git add app/Enums/SellerEarningStatus.php app/Enums/SettlementErrorCode.php app/Enums/SellerPayoutStatus.php
git commit -m "feat(settlement): add SellerEarningStatus + SettlementErrorCode; simplify SellerPayoutStatus"
```

---

## Task 5: Model — `SellerEarning`

**[OWNER: Codex]**

**Files:**
- Create: `app/Models/SellerEarning.php`
- Modify: `app/Models/Order.php` (add `sellerEarning` HasOne relation)
- Modify: `app/Models/User.php` (add `sellerEarnings` HasMany relation)

- [ ] **Step 5.1: Create `SellerEarning` model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SellerEarningStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class SellerEarning extends Model
{
    use SoftDeletes;

    /** @var array<int, string> */
    protected $fillable = [
        'public_id', 'order_id', 'seller_user_id',
        'amount', 'status',
        'cleared_at', 'available_at', 'paid_out_at',
        'paid_by_staff_id', 'seller_payout_id',
        'notes',
    ];

    protected static function booted(): void
    {
        self::creating(static function (SellerEarning $e): void {
            if (empty($e->public_id)) {
                $e->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'status' => SellerEarningStatus::class,
            'amount' => 'decimal:2',
            'cleared_at' => 'datetime',
            'available_at' => 'datetime',
            'paid_out_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function paidByStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_staff_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(SellerPayout::class, 'seller_payout_id');
    }

    public function scopeForSeller(Builder $query, int $sellerId): Builder
    {
        return $query->where('seller_user_id', $sellerId);
    }

    public function scopeWithStatus(Builder $query, SellerEarningStatus ...$statuses): Builder
    {
        return $query->whereIn(
            'status',
            array_map(static fn (SellerEarningStatus $s): string => $s->value, $statuses),
        );
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', SellerEarningStatus::Available->value);
    }

    public function scopePendingClearance(Builder $query): Builder
    {
        return $query->where('status', SellerEarningStatus::PendingClearance->value);
    }

    public function scopePendingSettlementForDriver(Builder $query, int $driverId): Builder
    {
        return $query
            ->where('status', SellerEarningStatus::PendingSettlement->value)
            ->whereHas('order', fn (Builder $q) => $q->where('driver_id', $driverId));
    }
}
```

- [ ] **Step 5.2: Add `sellerEarning` relation to `Order` model**

Open `app/Models/Order.php`. Find the relations block (after the `merchant()` or similar `BelongsTo` method) and add:

```php
public function sellerEarning(): HasOne
{
    return $this->hasOne(SellerEarning::class);
}
```

Verify `HasOne` is already imported at the top (line 24 per the existing model — already present).

- [ ] **Step 5.3: Add `sellerEarnings` relation to `User` model**

Open `app/Models/User.php`. Find the relations block (look for `officeStaffAssignments()` method) and add nearby:

```php
public function sellerEarnings(): HasMany
{
    return $this->hasMany(SellerEarning::class, 'seller_user_id');
}
```

Verify `HasMany` is already imported (use `grep -n 'HasMany' app/Models/User.php` to check). If not, add the use at the top.

- [ ] **Step 5.4: Verify relations**

Run: `php artisan tinker --execute="dd(\App\Models\Order::first()?->sellerEarning, \App\Models\SellerEarning::first());"`
Expected: both NULL (no rows yet, no errors).

- [ ] **Step 5.5: Commit**

```bash
git add app/Models/SellerEarning.php app/Models/Order.php app/Models/User.php
git commit -m "feat(settlement): add SellerEarning model + Order/User relations"
```

---

## Task 6: Models — Update `SellerPayout` + create `SellerPayoutOrder` pivot

**[OWNER: Codex]**

**Files:**
- Modify: `app/Models/SellerPayout.php` (drop removed fields, rename, add pivot relation)
- Create: `app/Models/SellerPayoutOrder.php` (pivot model)

- [ ] **Step 6.1: Rewrite `SellerPayout`**

Replace the entire file:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SellerPayoutStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class SellerPayout extends Model
{
    use SoftDeletes;

    /** @var array<int, string> */
    protected $fillable = [
        'public_id', 'user_id', 'amount',
        'payout_method', 'office_id',
        'status',
        'paid_at', 'paid_by_staff_id',
        'notes',
    ];

    protected static function booted(): void
    {
        self::creating(static function (SellerPayout $p): void {
            if (empty($p->public_id)) {
                $p->public_id = (string) Str::ulid();
            }
            $p->paid_at ??= now();
            $p->status ??= SellerPayoutStatus::Paid->value;
            $p->payout_method ??= 'cash_at_office';
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'status' => SellerPayoutStatus::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class, 'office_id');
    }

    public function paidByStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_staff_id');
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'seller_payout_orders', 'seller_payout_id', 'order_id')
            ->using(SellerPayoutOrder::class)
            ->withPivot(['amount_contributed'])
            ->withTimestamps();
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(SellerEarning::class, 'seller_payout_id');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeAtOffice(Builder $query, int $officeId): Builder
    {
        return $query->where('office_id', $officeId);
    }
}
```

- [ ] **Step 6.2: Create `SellerPayoutOrder` pivot**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

final class SellerPayoutOrder extends Pivot
{
    public $incrementing = true;

    protected $table = 'seller_payout_orders';

    /** @var array<int, string> */
    protected $fillable = [
        'seller_payout_id', 'order_id', 'amount_contributed',
    ];

    protected function casts(): array
    {
        return [
            'amount_contributed' => 'decimal:2',
        ];
    }
}
```

- [ ] **Step 6.3: Verify**

Run: `php artisan tinker --execute="dd(\App\Models\SellerPayout::query()->getModel()->getFillable());"`
Expected: array containing `'paid_by_staff_id'` (not `paid_by_admin_id`) and NOT containing `approved_at`/`rejected_at`/etc.

- [ ] **Step 6.4: Commit**

```bash
git add app/Models/SellerPayout.php app/Models/SellerPayoutOrder.php
git commit -m "feat(settlement): rewrite SellerPayout model + add SellerPayoutOrder pivot"
```

---

## Task 7: Seeder — Extend platform settings

**[OWNER: Codex]**

**Files:**
- Modify: `database/seeders/OrderLifecyclePlatformSettingsSeeder.php`

- [ ] **Step 7.1: Read the existing seeder**

Run: `cat database/seeders/OrderLifecyclePlatformSettingsSeeder.php`
Note the format used to seed settings (likely `PlatformSetting::updateOrCreate` calls).

- [ ] **Step 7.2: Append the new payout settings**

Open the file and add the following entries inside the `run()` method (mirror the existing format — the actual key-value structure should match what's already there; below is the canonical form):

```php
// ─── Settlement & Payouts (milestone 2026-05-17) ──────────────────────
\App\Models\PlatformSetting::updateOrCreate(
    ['key' => 'payouts.clearance_hours'],
    ['value' => '48', 'type' => 'integer', 'description' => 'Hours between settlement and earning becoming available to seller'],
);
\App\Models\PlatformSetting::updateOrCreate(
    ['key' => 'payouts.min_amount'],
    ['value' => '20.00', 'type' => 'decimal', 'description' => 'Minimum amount (LYD) for a single seller payout'],
);
\App\Models\PlatformSetting::updateOrCreate(
    ['key' => 'payouts.allow_partial'],
    ['value' => 'true', 'type' => 'boolean', 'description' => 'Whether sellers may collect a subset of available earnings in one visit'],
);
\App\Models\PlatformSetting::updateOrCreate(
    ['key' => 'settlement.reverse_window_hours'],
    ['value' => '', 'type' => 'integer', 'description' => 'Optional hard cap (hours) for admin settlement reversal; empty = no cap, only earnings-state check applies'],
);
```

If the existing seeder uses a different shape (e.g. an array passed to a service), match the existing shape exactly — DO NOT change the format. Run `grep -n "updateOrCreate\|PlatformSetting::set" database/seeders/OrderLifecyclePlatformSettingsSeeder.php` first to confirm the pattern.

- [ ] **Step 7.3: Run the seeder**

Run: `php artisan db:seed --class=OrderLifecyclePlatformSettingsSeeder`
Expected: no errors; settings inserted.

- [ ] **Step 7.4: Verify settings landed**

Run: `php artisan tinker --execute="dd(\App\Models\PlatformSetting::get('payouts.clearance_hours'), \App\Models\PlatformSetting::get('payouts.min_amount'), \App\Models\PlatformSetting::get('payouts.allow_partial'));"`
Expected: `48`, `20.00`, `true` (string or coerced types depending on accessor).

- [ ] **Step 7.5: Commit**

```bash
git add database/seeders/OrderLifecyclePlatformSettingsSeeder.php
git commit -m "feat(settlement): seed payouts.* + settlement.reverse_window_hours settings"
```

---

## Task 8: Policies — extract `isAssignedToOffice` helper + three new policies

**[OWNER: Claude]**

We need the office-assignment check in `OrderPolicy::orderInUsersOffice` to be reusable from `SettlementPolicy`, `SellerPayoutPolicy`, etc. Extract it onto `User` as `isAssignedToOffice(int $officeId): bool`, then have `OrderPolicy` and the new policies delegate.

**Files:**
- Modify: `app/Models/User.php` (add `isAssignedToOffice` helper)
- Modify: `app/Policies/OrderPolicy.php` (delegate to the helper)
- Create: `app/Policies/SettlementPolicy.php`
- Create: `app/Policies/SellerPayoutPolicy.php`
- Create: `app/Policies/SellerEarningPolicy.php`

- [ ] **Step 8.1: Add `isAssignedToOffice` to `User`**

In `app/Models/User.php`, find the `officeStaffAssignments()` relationship method and add immediately below it:

```php
/**
 * Whether this user currently holds an ACTIVE office staff assignment at the given office.
 * Used by policies to gate office-scope actions (D + settlement milestones).
 */
public function isAssignedToOffice(int $officeId): bool
{
    return $this->officeStaffAssignments()
        ->active()
        ->where('office_id', $officeId)
        ->exists();
}
```

- [ ] **Step 8.2: Refactor `OrderPolicy::orderInUsersOffice` to use it**

Edit `app/Policies/OrderPolicy.php`, replace the entire `orderInUsersOffice` method:

```php
private function orderInUsersOffice(User $user, Order $order): bool
{
    return $order->return_office_id !== null
        && $user->isAssignedToOffice($order->return_office_id);
}
```

- [ ] **Step 8.3: Create `SettlementPolicy`**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Settlement;
use App\Models\User;

final class SettlementPolicy
{
    public function process(User $staff): bool
    {
        return $staff->hasRole('office_staff')
            && $staff->officeStaffAssignments()->active()->exists();
    }

    public function previewForDriver(User $staff, User $driver): bool
    {
        return $this->process($staff) && $driver->hasRole('driver');
    }

    public function viewByDriver(User $driver, Settlement $settlement): bool
    {
        return $settlement->driver_id === $driver->id
            && $driver->hasRole('driver');
    }

    public function viewByOffice(User $staff, Settlement $settlement): bool
    {
        return $staff->hasRole('office_staff')
            && $staff->isAssignedToOffice($settlement->office_id);
    }

    public function reverse(User $admin, Settlement $settlement): bool
    {
        return $admin->hasRole('admin');
    }
}
```

- [ ] **Step 8.4: Create `SellerPayoutPolicy`**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SellerPayout;
use App\Models\User;

final class SellerPayoutPolicy
{
    public function process(User $staff): bool
    {
        return $staff->hasRole('office_staff')
            && $staff->officeStaffAssignments()->active()->exists();
    }

    public function lookupSeller(User $staff): bool
    {
        return $this->process($staff);
    }

    public function viewBySeller(User $seller, SellerPayout $payout): bool
    {
        return $payout->user_id === $seller->id;
    }

    public function viewByOffice(User $staff, SellerPayout $payout): bool
    {
        return $staff->hasRole('office_staff')
            && $staff->isAssignedToOffice($payout->office_id);
    }

    public function viewByAdmin(User $admin, SellerPayout $payout): bool
    {
        return $admin->hasRole('admin');
    }
}
```

- [ ] **Step 8.5: Create `SellerEarningPolicy`**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SellerEarning;
use App\Models\User;

final class SellerEarningPolicy
{
    public function viewBySeller(User $seller, SellerEarning $earning): bool
    {
        return $earning->seller_user_id === $seller->id;
    }

    public function viewOwnDashboard(User $user): bool
    {
        return true;
    }
}
```

- [ ] **Step 8.6: Verify policy auto-discovery**

Laravel 11+ auto-discovers policies by name convention (`App\Policies\{Model}Policy` for `App\Models\{Model}`). No registration needed. Verify:

Run: `php artisan tinker --execute="dd(\Gate::getPolicyFor(\App\Models\Settlement::class), \Gate::getPolicyFor(\App\Models\SellerPayout::class), \Gate::getPolicyFor(\App\Models\SellerEarning::class));"`
Expected: three instances of the corresponding `*Policy` classes (not `null`).

- [ ] **Step 8.7: Commit**

```bash
git add app/Models/User.php app/Policies/
git commit -m "feat(settlement): policies + extract isAssignedToOffice helper on User"
```

---

## Task 9: `SettlementService` (preview + process)

**[OWNER: Claude]**

The atomic single-call commit. Sole writer of `settlements`, `settlement_orders`, and the corresponding `driver_account_transactions`. Also flips `seller_earnings` rows from `pending_settlement` → `pending_clearance`.

**Files:**
- Create: `app/Services/Settlement/SettlementService.php`
- Create: `app/Exceptions/Settlement/SettlementExcessException.php`
- Create: `app/Exceptions/Settlement/EmptySettlementException.php`
- Create: `app/ValueObjects/SettlementPreview.php`

- [ ] **Step 9.1: Create the exceptions**

`app/Exceptions/Settlement/SettlementExcessException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Settlement;

use App\Enums\SettlementErrorCode;
use RuntimeException;

final class SettlementExcessException extends RuntimeException
{
    public function __construct(
        public readonly string $expectedNet,
        public readonly string $actualNet,
    ) {
        parent::__construct(sprintf(
            'Settlement excess rejected: actual net %s exceeds expected %s. Hand excess back before submitting.',
            $actualNet,
            $expectedNet,
        ));
    }

    public function errorCode(): SettlementErrorCode
    {
        return SettlementErrorCode::SettlementExcessRejected;
    }
}
```

`app/Exceptions/Settlement/EmptySettlementException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Settlement;

use App\Enums\SettlementErrorCode;
use RuntimeException;

final class EmptySettlementException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Driver has no balances to settle (all three buckets at zero).');
    }

    public function errorCode(): SettlementErrorCode
    {
        return SettlementErrorCode::SettlementEmpty;
    }
}
```

- [ ] **Step 9.2: Create the `SettlementPreview` value object**

`app/ValueObjects/SettlementPreview.php`:

```php
<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Models\SellerEarning;
use Illuminate\Support\Collection;

final readonly class SettlementPreview
{
    public function __construct(
        public string $cashToDeposit,
        public string $earningsBalance,
        public string $debtBalance,
        public string $expectedNet,
        public Collection $pendingEarnings,
    ) {}

    /**
     * @return Collection<int, SellerEarning>
     */
    public function pendingEarnings(): Collection
    {
        return $this->pendingEarnings;
    }
}
```

- [ ] **Step 9.3: Create `SettlementService`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Enums\DriverAccountBucket;
use App\Enums\DriverAccountTransactionReason;
use App\Enums\SellerEarningStatus;
use App\Enums\SettlementStatus;
use App\Exceptions\Settlement\EmptySettlementException;
use App\Exceptions\Settlement\SettlementExcessException;
use App\Models\DriverAccount;
use App\Models\DriverAccountTransaction;
use App\Models\OfficeLocation;
use App\Models\SellerEarning;
use App\Models\Settlement;
use App\Models\SettlementOrder;
use App\Models\User;
use App\ValueObjects\SettlementPreview;
use Illuminate\Support\Facades\DB;

final class SettlementService
{
    public function preview(User $driver): SettlementPreview
    {
        $account = DriverAccount::query()
            ->where('driver_id', $driver->id)
            ->firstOrFail();

        $expectedNet = bcsub(
            bcadd((string) $account->cash_to_deposit, (string) $account->debt_balance, 2),
            (string) $account->earnings_balance,
            2,
        );

        $pendingEarnings = SellerEarning::query()
            ->pendingSettlementForDriver($driver->id)
            ->with('order:id,public_id,item_description,item_price,commission_amount')
            ->get();

        return new SettlementPreview(
            cashToDeposit: (string) $account->cash_to_deposit,
            earningsBalance: (string) $account->earnings_balance,
            debtBalance: (string) $account->debt_balance,
            expectedNet: $expectedNet,
            pendingEarnings: $pendingEarnings,
        );
    }

    public function process(
        User $driver,
        User $staff,
        OfficeLocation $office,
        string $cashReceivedFromDriver,
        string $cashPaidToDriver,
        ?string $notes = null,
    ): Settlement {
        return DB::transaction(function () use (
            $driver,
            $staff,
            $office,
            $cashReceivedFromDriver,
            $cashPaidToDriver,
            $notes,
        ): Settlement {
            $account = DriverAccount::query()
                ->where('driver_id', $driver->id)
                ->lockForUpdate()
                ->firstOrFail();

            $cashSnapshot = (string) $account->cash_to_deposit;
            $earningsSnapshot = (string) $account->earnings_balance;
            $debtSnapshot = (string) $account->debt_balance;

            // Reject if every bucket is zero — nothing to settle.
            if (
                bccomp($cashSnapshot, '0.00', 2) === 0
                && bccomp($earningsSnapshot, '0.00', 2) === 0
                && bccomp($debtSnapshot, '0.00', 2) === 0
            ) {
                throw new EmptySettlementException();
            }

            $expectedNet = bcsub(
                bcadd($cashSnapshot, $debtSnapshot, 2),
                $earningsSnapshot,
                2,
            );
            $actualNet = bcsub($cashReceivedFromDriver, $cashPaidToDriver, 2);

            // Excess (driver handed too much) is rejected — must be handed back at counter.
            if (bccomp($actualNet, $expectedNet, 2) === 1) {
                throw new SettlementExcessException($expectedNet, $actualNet);
            }

            $shortage = bccomp($actualNet, $expectedNet, 2) === -1
                ? bcsub($expectedNet, $actualNet, 2)
                : '0.00';

            // Write the settlement row.
            $settlement = Settlement::create([
                'driver_id' => $driver->id,
                'office_id' => $office->id,
                'processed_by_staff_id' => $staff->id,
                'cash_received_from_driver' => $cashReceivedFromDriver,
                'cash_paid_to_driver' => $cashPaidToDriver,
                'cash_to_deposit_cleared' => $cashSnapshot,
                'earnings_balance_cleared' => $earningsSnapshot,
                'debt_balance_cleared' => $debtSnapshot,
                'shortage_amount' => $shortage,
                'excess_amount' => '0.00',
                'status' => SettlementStatus::Completed->value,
                'notes' => $notes,
            ]);

            // Write one driver_account_transactions row per non-zero bucket clear.
            $this->writeBucketTransaction(
                $account, DriverAccountBucket::CashToDeposit,
                bcmul($cashSnapshot, '-1', 2),
                DriverAccountTransactionReason::Settlement, $settlement,
            );
            $this->writeBucketTransaction(
                $account, DriverAccountBucket::EarningsBalance,
                bcmul($earningsSnapshot, '-1', 2),
                DriverAccountTransactionReason::Settlement, $settlement,
            );
            $this->writeBucketTransaction(
                $account, DriverAccountBucket::DebtBalance,
                bcmul($debtSnapshot, '-1', 2),
                DriverAccountTransactionReason::Settlement, $settlement,
            );

            // If there's a shortage, push it to debt_balance with a distinct reason.
            if (bccomp($shortage, '0.00', 2) === 1) {
                $this->writeBucketTransaction(
                    $account, DriverAccountBucket::DebtBalance,
                    $shortage,
                    DriverAccountTransactionReason::SettlementShortage, $settlement,
                );
            }

            // Update the driver_account bucket values + lifetime aggregates.
            $account->cash_to_deposit = '0.00';
            $account->earnings_balance = '0.00';
            $account->debt_balance = $shortage;
            $account->lifetime_cash_handled = bcadd((string) $account->lifetime_cash_handled, $cashReceivedFromDriver, 2);
            $account->save();

            // Flip this driver's pending_settlement earnings to pending_clearance.
            $earnings = SellerEarning::query()
                ->pendingSettlementForDriver($driver->id)
                ->lockForUpdate()
                ->get();

            $now = now();
            foreach ($earnings as $earning) {
                $earning->status = SellerEarningStatus::PendingClearance->value;
                $earning->cleared_at = $now;
                $earning->save();

                SettlementOrder::create([
                    'settlement_id' => $settlement->id,
                    'order_id' => $earning->order_id,
                    'amount_contributed' => (string) $earning->amount,
                ]);
            }

            return $settlement->fresh(['driver', 'office', 'processedByStaff', 'orders']);
        });
    }

    private function writeBucketTransaction(
        DriverAccount $account,
        DriverAccountBucket $bucket,
        string $amount,
        DriverAccountTransactionReason $reason,
        Settlement $reference,
    ): void {
        if (bccomp($amount, '0.00', 2) === 0) {
            return;
        }

        $column = $bucket->value;
        $balanceAfter = bcadd((string) $account->{$column}, $amount, 2);

        DriverAccountTransaction::create([
            'driver_id' => $account->driver_id,
            'bucket' => $bucket->value,
            'amount' => $amount,
            'reason' => $reason->value,
            'reference_type' => $reference::class,
            'reference_id' => $reference->getKey(),
            'balance_after' => $balanceAfter,
        ]);
    }
}
```

- [ ] **Step 9.4: Smoke-verify the service compiles**

Run: `php artisan tinker --execute="dd(new \App\Services\Settlement\SettlementService());"`
Expected: instance dumped, no class-load errors.

- [ ] **Step 9.5: Commit**

```bash
git add app/Services/Settlement/SettlementService.php app/Exceptions/Settlement/ app/ValueObjects/SettlementPreview.php
git commit -m "feat(settlement): SettlementService — atomic preview + process"
```

---

## Task 10: `SellerPayoutService` (lookup + process)

**[OWNER: Claude]**

Sole writer of `seller_payouts`. Mutates `seller_earnings` rows from `available` → `paid_out`.

**Files:**
- Create: `app/Services/Settlement/SellerPayoutService.php`
- Create: `app/Exceptions/Settlement/PayoutValidationException.php`

- [ ] **Step 10.1: Create the exception**

`app/Exceptions/Settlement/PayoutValidationException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Settlement;

use App\Enums\SettlementErrorCode;
use RuntimeException;

final class PayoutValidationException extends RuntimeException
{
    public function __construct(
        public readonly SettlementErrorCode $code,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): SettlementErrorCode
    {
        return $this->code;
    }
}
```

- [ ] **Step 10.2: Create `SellerPayoutService`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Enums\SellerEarningStatus;
use App\Enums\SellerPayoutStatus;
use App\Enums\SettlementErrorCode;
use App\Exceptions\Settlement\PayoutValidationException;
use App\Models\OfficeLocation;
use App\Models\PlatformSetting;
use App\Models\SellerEarning;
use App\Models\SellerPayout;
use App\Models\SellerPayoutOrder;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SellerPayoutService
{
    /**
     * @return Collection<int, SellerEarning>
     */
    public function availableEarningsFor(User $seller): Collection
    {
        return SellerEarning::query()
            ->forSeller($seller->id)
            ->available()
            ->with('order:id,public_id,item_description,order_type,delivered_at')
            ->orderBy('available_at')
            ->get();
    }

    public function process(
        User $seller,
        User $staff,
        OfficeLocation $office,
        Collection $earningPublicIds,
        string $totalForSanityCheck,
        ?string $notes = null,
    ): SellerPayout {
        if ($earningPublicIds->isEmpty()) {
            throw new PayoutValidationException(
                SettlementErrorCode::PayoutEmptySelection,
                'At least one earning must be selected.',
            );
        }

        return DB::transaction(function () use (
            $seller,
            $staff,
            $office,
            $earningPublicIds,
            $totalForSanityCheck,
            $notes,
        ): SellerPayout {
            // Lock all selected earnings for update.
            $earnings = SellerEarning::query()
                ->whereIn('public_id', $earningPublicIds->all())
                ->lockForUpdate()
                ->get();

            if ($earnings->count() !== $earningPublicIds->count()) {
                throw new PayoutValidationException(
                    SettlementErrorCode::PayoutEarningNotAvailable,
                    'One or more selected earnings no longer exist.',
                );
            }

            // Validate each earning: belongs to seller, status=available, no existing payout link.
            foreach ($earnings as $earning) {
                if ($earning->seller_user_id !== $seller->id) {
                    throw new PayoutValidationException(
                        SettlementErrorCode::PayoutEarningWrongSeller,
                        "Earning {$earning->public_id} does not belong to seller {$seller->public_id}.",
                    );
                }
                if ($earning->status !== SellerEarningStatus::Available) {
                    throw new PayoutValidationException(
                        SettlementErrorCode::PayoutEarningNotAvailable,
                        "Earning {$earning->public_id} is not in 'available' state (current: {$earning->status->value}).",
                    );
                }
                if ($earning->seller_payout_id !== null) {
                    throw new PayoutValidationException(
                        SettlementErrorCode::PayoutEarningNotAvailable,
                        "Earning {$earning->public_id} is already linked to a payout.",
                    );
                }
            }

            // Sanity check: server-computed total must match client-submitted total exactly.
            $computedTotal = $earnings->reduce(
                static fn (string $carry, SellerEarning $e): string => bcadd($carry, (string) $e->amount, 2),
                '0.00',
            );
            if (bccomp($computedTotal, $totalForSanityCheck, 2) !== 0) {
                throw new PayoutValidationException(
                    SettlementErrorCode::PayoutTotalMismatch,
                    "Submitted total {$totalForSanityCheck} does not match computed total {$computedTotal}.",
                );
            }

            // Enforce minimum payout amount.
            $minAmount = (string) PlatformSetting::get('payouts.min_amount', '20.00');
            if (bccomp($computedTotal, $minAmount, 2) === -1) {
                throw new PayoutValidationException(
                    SettlementErrorCode::PayoutBelowMinimum,
                    "Total {$computedTotal} is below minimum payout {$minAmount}.",
                );
            }

            // Create the payout receipt row.
            $payout = SellerPayout::create([
                'user_id' => $seller->id,
                'amount' => $computedTotal,
                'payout_method' => 'cash_at_office',
                'office_id' => $office->id,
                'status' => SellerPayoutStatus::Paid->value,
                'paid_at' => now(),
                'paid_by_staff_id' => $staff->id,
                'notes' => $notes,
            ]);

            // Flip each earning to paid_out + link to payout + write pivot row.
            $now = now();
            foreach ($earnings as $earning) {
                $earning->status = SellerEarningStatus::PaidOut->value;
                $earning->paid_out_at = $now;
                $earning->paid_by_staff_id = $staff->id;
                $earning->seller_payout_id = $payout->id;
                $earning->save();

                SellerPayoutOrder::create([
                    'seller_payout_id' => $payout->id,
                    'order_id' => $earning->order_id,
                    'amount_contributed' => (string) $earning->amount,
                ]);
            }

            return $payout->fresh(['user', 'office', 'paidByStaff', 'orders', 'earnings']);
        });
    }
}
```

- [ ] **Step 10.3: Verify**

Run: `php artisan tinker --execute="dd(new \App\Services\Settlement\SellerPayoutService());"`
Expected: instance, no errors.

- [ ] **Step 10.4: Commit**

```bash
git add app/Services/Settlement/SellerPayoutService.php app/Exceptions/Settlement/PayoutValidationException.php
git commit -m "feat(settlement): SellerPayoutService — atomic seller cash handover"
```

---

## Task 11: `SettlementReversalService` (admin correcting settlement)

**[OWNER: Claude]**

Per spec §6.3 and locked decision #9: reversal is allowed ONLY while every contributing earning is still `pending_clearance`. Writes a new `Settlement` row with reversed cash + bucket movements; original row's status flips to `Cancelled` and gets a notes annotation.

**Files:**
- Create: `app/Services/Settlement/SettlementReversalService.php`
- Create: `app/Exceptions/Settlement/SettlementNotReversibleException.php`

- [ ] **Step 11.1: Create the exception**

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Settlement;

use App\Enums\SettlementErrorCode;
use RuntimeException;

final class SettlementNotReversibleException extends RuntimeException
{
    public function __construct(
        public readonly SettlementErrorCode $code,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): SettlementErrorCode
    {
        return $this->code;
    }
}
```

- [ ] **Step 11.2: Create the service**

```php
<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Enums\DriverAccountBucket;
use App\Enums\DriverAccountTransactionReason;
use App\Enums\SellerEarningStatus;
use App\Enums\SettlementErrorCode;
use App\Enums\SettlementStatus;
use App\Exceptions\Settlement\SettlementNotReversibleException;
use App\Models\DriverAccount;
use App\Models\DriverAccountTransaction;
use App\Models\PlatformSetting;
use App\Models\SellerEarning;
use App\Models\Settlement;
use App\Models\SettlementOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SettlementReversalService
{
    public function reverse(Settlement $original, User $admin, string $reason): Settlement
    {
        return DB::transaction(function () use ($original, $admin, $reason): Settlement {
            // Lock the original settlement.
            $original = Settlement::query()->lockForUpdate()->findOrFail($original->id);

            if ($original->status !== SettlementStatus::Completed) {
                throw new SettlementNotReversibleException(
                    SettlementErrorCode::SettlementAlreadyReversed,
                    "Settlement {$original->public_id} is not in Completed status (current: {$original->status->value}).",
                );
            }

            // Optional hard time-cap check.
            $windowHours = PlatformSetting::get('settlement.reverse_window_hours');
            if ($windowHours !== null && $windowHours !== '') {
                if ($original->created_at->diffInHours(now()) > (int) $windowHours) {
                    throw new SettlementNotReversibleException(
                        SettlementErrorCode::SettlementNotReversible,
                        "Reversal window of {$windowHours}h has elapsed.",
                    );
                }
            }

            // Load contributing settlement_orders + their earnings, locked.
            $contributingOrderIds = SettlementOrder::query()
                ->where('settlement_id', $original->id)
                ->pluck('order_id')
                ->all();

            $earnings = SellerEarning::query()
                ->whereIn('order_id', $contributingOrderIds)
                ->lockForUpdate()
                ->get();

            // Reversal only allowed while every contributing earning is still pending_clearance.
            foreach ($earnings as $earning) {
                if ($earning->status !== SellerEarningStatus::PendingClearance) {
                    throw new SettlementNotReversibleException(
                        SettlementErrorCode::SettlementNotReversible,
                        "Earning {$earning->public_id} has progressed to {$earning->status->value}; reversal blocked.",
                    );
                }
            }

            // Lock driver_account.
            $account = DriverAccount::query()
                ->where('driver_id', $original->driver_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Write the correcting settlement row — reverse cash + bucket movements.
            $correcting = Settlement::create([
                'driver_id' => $original->driver_id,
                'office_id' => $original->office_id,
                'processed_by_staff_id' => $admin->id,
                'cash_received_from_driver' => (string) $original->cash_paid_to_driver,
                'cash_paid_to_driver' => (string) $original->cash_received_from_driver,
                'cash_to_deposit_cleared' => bcmul((string) $original->cash_to_deposit_cleared, '-1', 2),
                'earnings_balance_cleared' => bcmul((string) $original->earnings_balance_cleared, '-1', 2),
                'debt_balance_cleared' => bcmul((string) $original->debt_balance_cleared, '-1', 2),
                'shortage_amount' => '0.00',
                'excess_amount' => '0.00',
                'status' => SettlementStatus::Completed->value,
                'notes' => "Reversal of {$original->public_id}: {$reason}",
            ]);

            // Restore the driver's bucket values + write inverse driver_account_transactions.
            $cashRestore = (string) $original->cash_to_deposit_cleared;
            $earningsRestore = (string) $original->earnings_balance_cleared;
            $debtRestore = (string) $original->debt_balance_cleared;
            $shortageRestore = (string) $original->shortage_amount;

            $account->cash_to_deposit = bcadd((string) $account->cash_to_deposit, $cashRestore, 2);
            $account->earnings_balance = bcadd((string) $account->earnings_balance, $earningsRestore, 2);
            $account->debt_balance = bcsub((string) $account->debt_balance, bcadd($debtRestore, $shortageRestore, 2), 2);
            // Guard against negative debt: clamp to zero (spec Critical Rule 5 — no negative balances).
            if (bccomp((string) $account->debt_balance, '0.00', 2) === -1) {
                $account->debt_balance = '0.00';
            }
            $account->save();

            $this->writeTx($account, DriverAccountBucket::CashToDeposit, $cashRestore, $correcting, $admin->id);
            $this->writeTx($account, DriverAccountBucket::EarningsBalance, $earningsRestore, $correcting, $admin->id);
            $this->writeTx(
                $account,
                DriverAccountBucket::DebtBalance,
                bcmul(bcadd($debtRestore, $shortageRestore, 2), '-1', 2),
                $correcting,
                $admin->id,
            );

            // Flip the contributing earnings back to pending_settlement.
            foreach ($earnings as $earning) {
                $earning->status = SellerEarningStatus::PendingSettlement->value;
                $earning->cleared_at = null;
                $earning->save();
            }

            // Soft-delete the original settlement_orders pivot rows (audit-preserving).
            SettlementOrder::query()
                ->where('settlement_id', $original->id)
                ->delete();

            // Mark the original as cancelled with cross-reference.
            $original->status = SettlementStatus::Cancelled->value;
            $original->notes = trim(
                ($original->notes ?? '')
                . "\nReversed by {$correcting->public_id}: {$reason}",
            );
            $original->save();

            return $correcting->fresh(['driver', 'office', 'processedByStaff']);
        });
    }

    private function writeTx(
        DriverAccount $account,
        DriverAccountBucket $bucket,
        string $amount,
        Settlement $reference,
        int $adminId,
    ): void {
        if (bccomp($amount, '0.00', 2) === 0) {
            return;
        }

        DriverAccountTransaction::create([
            'driver_id' => $account->driver_id,
            'bucket' => $bucket->value,
            'amount' => $amount,
            'reason' => DriverAccountTransactionReason::ManualAdjustment->value,
            'reference_type' => $reference::class,
            'reference_id' => $reference->getKey(),
            'balance_after' => (string) $account->{$bucket->value},
            'created_by_admin_id' => $adminId,
            'notes' => 'Settlement reversal',
        ]);
    }
}
```

Note: this service doesn't check the `settlement_orders` model's existence — it uses the existing model from sub-project B. Confirm it exists with `cat app/Models/SettlementOrder.php` before running tests.

- [ ] **Step 11.3: Verify**

Run: `php artisan tinker --execute="dd(new \App\Services\Settlement\SettlementReversalService());"`
Expected: instance, no errors.

- [ ] **Step 11.4: Commit**

```bash
git add app/Services/Settlement/SettlementReversalService.php app/Exceptions/Settlement/SettlementNotReversibleException.php
git commit -m "feat(settlement): SettlementReversalService — admin correcting settlement"
```

---

## Task 12: `ClearSellerEarningsJob` + cron registration

**[OWNER: Codex]**

**Files:**
- Create: `app/Jobs/ClearSellerEarningsJob.php`
- Modify: `routes/console.php`

- [ ] **Step 12.1: Create the job (mirror `AbandonStaleOrdersJob` structure)**

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SellerEarningStatus;
use App\Models\PlatformSetting;
use App\Models\SellerEarning;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ClearSellerEarningsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        Cache::lock('seller-earnings:clear', 90)->block(5, function (): void {
            $hours = (int) PlatformSetting::get('payouts.clearance_hours', 48);
            $cutoff = now()->subHours($hours);
            $advanced = 0;

            SellerEarning::query()
                ->pendingClearance()
                ->where('cleared_at', '<=', $cutoff)
                ->cursor()
                ->each(function (SellerEarning $earning) use (&$advanced): void {
                    try {
                        $earning->status = SellerEarningStatus::Available->value;
                        $earning->available_at = now();
                        $earning->save();
                        $advanced++;
                    } catch (Throwable $exception) {
                        Log::warning('ClearSellerEarningsJob row failed', [
                            'earning_id' => $earning->id,
                            'order_id' => $earning->order_id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                });

            Log::info('ClearSellerEarningsJob complete', ['advanced_count' => $advanced]);
        });
    }
}
```

- [ ] **Step 12.2: Register the cron**

Open `routes/console.php`. Find the `AbandonStaleOrdersJob` schedule line (probably `Schedule::job(new AbandonStaleOrdersJob)->daily()->name('orders.abandon-stale')->withoutOverlapping();` or similar). Below it, add:

```php
Schedule::job(new \App\Jobs\ClearSellerEarningsJob())
    ->daily()
    ->name('seller-earnings.clearance')
    ->withoutOverlapping();
```

- [ ] **Step 12.3: Verify schedule registered**

Run: `php artisan schedule:list`
Expected: output includes a line with `App\Jobs\ClearSellerEarningsJob` running daily.

- [ ] **Step 12.4: Smoke-test the job runs (with no eligible rows)**

Run: `php artisan tinker --execute="(new \App\Jobs\ClearSellerEarningsJob())->handle();"`
Expected: no errors. Check `storage/logs/laravel.log` for `ClearSellerEarningsJob complete` log entry with `advanced_count: 0`.

- [ ] **Step 12.5: Commit**

```bash
git add app/Jobs/ClearSellerEarningsJob.php routes/console.php
git commit -m "feat(settlement): ClearSellerEarningsJob — daily pending_clearance → available"
```

---

## Task 13: `CodeVerificationService` integration — spawn `seller_earnings` on delivery success

**[OWNER: Claude]**

When a sale order's delivery succeeds, create the `seller_earnings` row inline in the existing DB transaction.

**Files:**
- Modify: `app/Services/Order/CodeVerificationService.php`

- [ ] **Step 13.1: Read the existing service**

Run: `cat app/Services/Order/CodeVerificationService.php`
Find the `confirmDelivery` method (or whichever marks the order as delivered). Locate the place inside the existing DB transaction *after* the order status flips to `Delivered` and *after* the driver earnings credit is applied.

- [ ] **Step 13.2: Add the seller earning spawn**

Inside `confirmDelivery` (or equivalent), after the line that sets `$order->status = OrderStatus::Delivered->value;` and `$this->ledger->applyDeliveryCompletionCredit(...);` (the existing call extracted in slice 11), add:

```php
// Spawn seller_earnings row for sale orders (settlement & payout milestone, 2026-05-17).
if (
    in_array(
        $order->order_type,
        [OrderType::P2pSale, OrderType::MerchantDelivery],
        true,
    )
    && bccomp((string) $order->item_price, '0.00', 2) === 1
) {
    $sellerProceeds = bcsub(
        (string) $order->item_price,
        (string) $order->commission_amount,
        2,
    );

    if (bccomp($sellerProceeds, '0.00', 2) === 1) {
        \App\Models\SellerEarning::create([
            'order_id' => $order->id,
            'seller_user_id' => $order->sender_user_id,
            'amount' => $sellerProceeds,
            'status' => \App\Enums\SellerEarningStatus::PendingSettlement->value,
        ]);
    }
}
```

Verify `OrderType` is already imported at the top of the file (it should be from existing code). If not, add `use App\Enums\OrderType;`. Prefer importing `SellerEarning` and `SellerEarningStatus` at the top rather than inline `\App\Models\...` strings, for consistency with existing style — adjust the snippet accordingly when editing.

- [ ] **Step 13.3: Verify by faking a delivery**

Run a tinker scenario that creates a p2p_sale order, drives it through delivery, then asserts a seller_earnings row exists:

```bash
php artisan tinker
```

Inside tinker (this is illustrative — adapt to your local factory setup):

```php
$order = \App\Models\Order::factory()->create([
    'order_type' => 'p2p_sale',
    'item_price' => '100.00',
    'commission_amount' => '5.00',
    'status' => 'delivery_in_progress',
]);

// Simulate confirmDelivery's relevant logic by calling the service or manually inserting the row to verify model side
\App\Models\SellerEarning::create([
    'order_id' => $order->id,
    'seller_user_id' => $order->sender_user_id,
    'amount' => '95.00',
    'status' => 'pending_settlement',
]);

dd(\App\Models\SellerEarning::where('order_id', $order->id)->first());
```

Expected: SellerEarning row found with `amount = 95.00`, `status = pending_settlement`.

(Full end-to-end via `scripts/orders-e2e.php` happens in Task 23.)

- [ ] **Step 13.4: Commit**

```bash
git add app/Services/Order/CodeVerificationService.php
git commit -m "feat(settlement): spawn seller_earnings row on sale-order delivery"
```

---

## Task 14: FormRequests

**[OWNER: Codex]**

7 FormRequests covering all endpoints.

**Files:**
- Create: `app/Http/Requests/Settlement/ProcessSettlementRequest.php`
- Create: `app/Http/Requests/Settlement/ReverseSettlementRequest.php`
- Create: `app/Http/Requests/Settlement/LookupSellerPayoutRequest.php`
- Create: `app/Http/Requests/Settlement/ProcessSellerPayoutRequest.php`
- Create: `app/Http/Requests/Settlement/ListSettlementsRequest.php`
- Create: `app/Http/Requests/Settlement/ListSellerPayoutsRequest.php`
- Create: `app/Http/Requests/Settlement/ListOfficeSettlementsRequest.php`

- [ ] **Step 14.1: `ProcessSettlementRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Settlement;

use Illuminate\Foundation\Http\FormRequest;

final class ProcessSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy enforced at controller level
    }

    public function rules(): array
    {
        return [
            'driver_public_id' => ['required', 'string', 'size:26'],
            'cash_received_from_driver' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'cash_paid_to_driver' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function driverPublicId(): string
    {
        return (string) $this->input('driver_public_id');
    }

    public function cashReceived(): string
    {
        return (string) $this->input('cash_received_from_driver');
    }

    public function cashPaid(): string
    {
        return (string) $this->input('cash_paid_to_driver');
    }
}
```

- [ ] **Step 14.2: `ReverseSettlementRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Settlement;

use Illuminate\Foundation\Http\FormRequest;

final class ReverseSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function reason(): string
    {
        return (string) $this->input('reason');
    }
}
```

- [ ] **Step 14.3: `LookupSellerPayoutRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Settlement;

use Illuminate\Foundation\Http\FormRequest;

final class LookupSellerPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required_without:public_id', 'nullable', 'string', 'max:20'],
            'public_id' => ['required_without:phone', 'nullable', 'string', 'size:26'],
        ];
    }
}
```

- [ ] **Step 14.4: `ProcessSellerPayoutRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Settlement;

use Illuminate\Foundation\Http\FormRequest;

final class ProcessSellerPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'seller_public_id' => ['required', 'string', 'size:26'],
            'earning_public_ids' => ['required', 'array', 'min:1'],
            'earning_public_ids.*' => ['required', 'string', 'size:26', 'distinct'],
            'total_amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function sellerPublicId(): string
    {
        return (string) $this->input('seller_public_id');
    }

    /**
     * @return array<int, string>
     */
    public function earningPublicIds(): array
    {
        return array_values(array_map('strval', (array) $this->input('earning_public_ids', [])));
    }

    public function totalAmount(): string
    {
        return (string) $this->input('total_amount');
    }
}
```

- [ ] **Step 14.5: `ListSettlementsRequest` (admin filters)**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Settlement;

use Illuminate\Foundation\Http\FormRequest;

final class ListSettlementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'driver_public_id' => ['nullable', 'string', 'size:26'],
            'office_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'in:completed,cancelled,disputed'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
```

- [ ] **Step 14.6: `ListSellerPayoutsRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Settlement;

use Illuminate\Foundation\Http\FormRequest;

final class ListSellerPayoutsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'seller_public_id' => ['nullable', 'string', 'size:26'],
            'office_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
```

- [ ] **Step 14.7: `ListOfficeSettlementsRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Settlement;

use Illuminate\Foundation\Http\FormRequest;

final class ListOfficeSettlementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
```

- [ ] **Step 14.8: Verify all requests load**

Run: `php artisan route:list 2>&1 | head -5` (just to verify no syntax errors prevent autoloading).
Run: `php artisan tinker --execute="dd(class_exists(\App\Http\Requests\Settlement\ProcessSettlementRequest::class));"`
Expected: `true`.

- [ ] **Step 14.9: Commit**

```bash
git add app/Http/Requests/Settlement/
git commit -m "feat(settlement): 7 FormRequests for settlement + payout endpoints"
```

---

## Task 15: JsonResources

**[OWNER: Codex]**

6 resources.

**Files:**
- Create: `app/Http/Resources/Settlement/SettlementResource.php`
- Create: `app/Http/Resources/Settlement/SettlementPreviewResource.php`
- Create: `app/Http/Resources/Settlement/SellerEarningResource.php`
- Create: `app/Http/Resources/Settlement/SellerEarningsSummaryResource.php`
- Create: `app/Http/Resources/Settlement/SellerPayoutResource.php`
- Create: `app/Http/Resources/Settlement/AdminSellerPayoutResource.php`

- [ ] **Step 15.1: `SettlementResource`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Settlement;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'driver' => [
                'id' => $this->driver?->public_id,
                'name' => $this->driver?->full_name ?? $this->driver?->name,
            ],
            'office' => [
                'id' => $this->office?->id,
                'name' => $this->office?->name,
            ],
            'processed_by_staff' => [
                'id' => $this->processedByStaff?->public_id,
                'name' => $this->processedByStaff?->full_name ?? $this->processedByStaff?->name,
            ],
            'cash_received_from_driver' => (string) $this->cash_received_from_driver,
            'cash_paid_to_driver' => (string) $this->cash_paid_to_driver,
            'cash_movement' => $this->cashMovement(),
            'cash_to_deposit_cleared' => (string) $this->cash_to_deposit_cleared,
            'earnings_balance_cleared' => (string) $this->earnings_balance_cleared,
            'debt_balance_cleared' => (string) $this->debt_balance_cleared,
            'shortage_amount' => (string) $this->shortage_amount,
            'excess_amount' => (string) $this->excess_amount,
            'notes' => $this->notes,
            'contributing_orders' => $this->whenLoaded(
                'orders',
                fn () => $this->orders->map(static fn ($o): array => [
                    'order_id' => $o->public_id,
                    'amount_contributed' => (string) $o->pivot->amount_contributed,
                ]),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 15.2: `SettlementPreviewResource`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Settlement;

use App\ValueObjects\SettlementPreview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property SettlementPreview $resource */
final class SettlementPreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'buckets' => [
                'cash_to_deposit' => $this->resource->cashToDeposit,
                'earnings_balance' => $this->resource->earningsBalance,
                'debt_balance' => $this->resource->debtBalance,
            ],
            'expected_net' => $this->resource->expectedNet,
            'instructions' => $this->buildInstructions(),
            'pending_earnings' => $this->resource->pendingEarnings->map(static fn ($e): array => [
                'order_id' => $e->order?->public_id,
                'item_description' => $e->order?->item_description,
                'item_price' => (string) ($e->order?->item_price ?? '0.00'),
                'amount' => (string) $e->amount,
            ])->all(),
        ];
    }

    private function buildInstructions(): string
    {
        $net = $this->resource->expectedNet;
        if (bccomp($net, '0.00', 2) === 1) {
            return "Driver should hand over {$net} LYD.";
        }
        if (bccomp($net, '0.00', 2) === -1) {
            $absNet = bcmul($net, '-1', 2);
            return "Platform should pay driver {$absNet} LYD.";
        }
        return 'No cash movement required. Buckets cancel out exactly.';
    }
}
```

- [ ] **Step 15.3: `SellerEarningResource`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Settlement;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SellerEarningResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'order_id' => $this->order?->public_id,
            'order_description' => $this->order?->item_description,
            'amount' => (string) $this->amount,
            'status' => $this->status->value,
            'cleared_at' => $this->cleared_at?->toIso8601String(),
            'available_at' => $this->available_at?->toIso8601String(),
            'paid_out_at' => $this->paid_out_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 15.4: `SellerEarningsSummaryResource`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Settlement;

use App\Enums\SellerEarningStatus;
use App\Models\SellerEarning;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @property array{seller_id: int, earnings: Collection<int, SellerEarning>} $resource */
final class SellerEarningsSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Collection<int, SellerEarning> $earnings */
        $earnings = $this->resource['earnings'];

        $byStatus = static function (SellerEarningStatus $status) use ($earnings): array {
            $rows = $earnings->where('status', $status)->values();
            $total = $rows->reduce(
                static fn (string $carry, SellerEarning $e): string => bcadd($carry, (string) $e->amount, 2),
                '0.00',
            );
            return [
                'count' => $rows->count(),
                'total' => $total,
                'items' => SellerEarningResource::collection($rows)->resolve(),
            ];
        };

        return [
            'pending_settlement' => $byStatus(SellerEarningStatus::PendingSettlement),
            'pending_clearance' => $byStatus(SellerEarningStatus::PendingClearance),
            'available' => $byStatus(SellerEarningStatus::Available),
            'paid_out_recent' => $byStatus(SellerEarningStatus::PaidOut),
        ];
    }
}
```

- [ ] **Step 15.5: `SellerPayoutResource`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Settlement;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SellerPayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'amount' => (string) $this->amount,
            'office' => [
                'id' => $this->office?->id,
                'name' => $this->office?->name,
            ],
            'paid_at' => $this->paid_at?->toIso8601String(),
            'paid_by_staff' => [
                'id' => $this->paidByStaff?->public_id,
                'name' => $this->paidByStaff?->full_name ?? $this->paidByStaff?->name,
            ],
            'status' => $this->status->value,
            'notes' => $this->notes,
            'orders' => $this->whenLoaded(
                'orders',
                fn () => $this->orders->map(static fn ($o): array => [
                    'order_id' => $o->public_id,
                    'item_description' => $o->item_description,
                    'amount_contributed' => (string) $o->pivot->amount_contributed,
                ])->all(),
            ),
        ];
    }
}
```

- [ ] **Step 15.6: `AdminSellerPayoutResource`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Settlement;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminSellerPayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'amount' => (string) $this->amount,
            'seller' => [
                'id' => $this->user?->public_id,
                'name' => $this->user?->full_name ?? $this->user?->name,
                'phone' => $this->user?->phone_number,
            ],
            'office' => [
                'id' => $this->office?->id,
                'name' => $this->office?->name,
            ],
            'paid_at' => $this->paid_at?->toIso8601String(),
            'paid_by_staff' => [
                'id' => $this->paidByStaff?->public_id,
                'name' => $this->paidByStaff?->full_name ?? $this->paidByStaff?->name,
            ],
            'status' => $this->status->value,
            'notes' => $this->notes,
            'order_count' => $this->whenLoaded('orders', fn () => $this->orders->count()),
        ];
    }
}
```

- [ ] **Step 15.7: Verify**

Run: `php artisan tinker --execute="dd(class_exists(\App\Http\Resources\Settlement\SettlementResource::class));"`
Expected: `true`.

- [ ] **Step 15.8: Commit**

```bash
git add app/Http/Resources/Settlement/
git commit -m "feat(settlement): 6 JsonResources for settlement + payout output"
```

---

## Task 16: Driver settlement history controllers + routes

**[OWNER: Codex]**

**Files:**
- Create: `app/Http/Controllers/Api/Driver/Settlement/ListSettlementsController.php`
- Create: `app/Http/Controllers/Api/Driver/Settlement/ShowSettlementController.php`
- Modify: `routes/api.php` (add 2 routes)

- [ ] **Step 16.1: `ListSettlementsController`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settlement\SettlementResource;
use App\Models\Settlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListSettlementsController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $driver = $request->user();
        abort_unless($driver?->hasRole('driver'), 403);

        $settlements = Settlement::query()
            ->forDriver($driver->id)
            ->with(['office', 'processedByStaff'])
            ->latest('created_at')
            ->paginate(20);

        return SettlementResource::collection($settlements);
    }
}
```

- [ ] **Step 16.2: `ShowSettlementController`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Driver\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settlement\SettlementResource;
use App\Models\Settlement;
use Illuminate\Http\Request;

final class ShowSettlementController extends Controller
{
    public function __invoke(Request $request, Settlement $settlement): SettlementResource
    {
        $driver = $request->user();
        abort_unless($driver?->can('viewByDriver', $settlement), 403);

        $settlement->load(['office', 'processedByStaff', 'orders']);

        return new SettlementResource($settlement);
    }
}
```

- [ ] **Step 16.3: Register routes**

In `routes/api.php`, find the driver group (probably `Route::prefix('driver')->middleware(['auth:sanctum', 'role:driver'])->group(function () { ... });`). Add inside:

```php
Route::get('settlements', \App\Http\Controllers\Api\Driver\Settlement\ListSettlementsController::class)
    ->name('driver.settlements.index');
Route::get('settlements/{settlement:public_id}', \App\Http\Controllers\Api\Driver\Settlement\ShowSettlementController::class)
    ->name('driver.settlements.show');
```

- [ ] **Step 16.4: Verify routes**

Run: `php artisan route:list --path=driver/settlements`
Expected: 2 routes listed.

- [ ] **Step 16.5: Commit**

```bash
git add app/Http/Controllers/Api/Driver/Settlement/ routes/api.php
git commit -m "feat(settlement): driver settlement-history endpoints (2)"
```

---

## Task 17: Seller earnings + payouts controllers + routes

**[OWNER: Codex]**

**Files:**
- Create: `app/Http/Controllers/Api/Me/Settlement/ShowEarningsController.php`
- Create: `app/Http/Controllers/Api/Me/Settlement/ListSellerPayoutsController.php`
- Create: `app/Http/Controllers/Api/Me/Settlement/ShowSellerPayoutController.php`
- Modify: `routes/api.php` (add 3 routes)

- [ ] **Step 17.1: `ShowEarningsController`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settlement\SellerEarningsSummaryResource;
use App\Models\SellerEarning;
use Illuminate\Http\Request;

final class ShowEarningsController extends Controller
{
    public function __invoke(Request $request): SellerEarningsSummaryResource
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $earnings = SellerEarning::query()
            ->forSeller($user->id)
            ->with('order:id,public_id,item_description')
            ->orderByDesc('created_at')
            ->limit(200) // safety cap; real seller dashboards rarely show >200
            ->get();

        return new SellerEarningsSummaryResource([
            'seller_id' => $user->id,
            'earnings' => $earnings,
        ]);
    }
}
```

- [ ] **Step 17.2: `ListSellerPayoutsController`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settlement\SellerPayoutResource;
use App\Models\SellerPayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListSellerPayoutsController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $payouts = SellerPayout::query()
            ->forUser($user->id)
            ->with(['office', 'paidByStaff'])
            ->latest('paid_at')
            ->paginate(20);

        return SellerPayoutResource::collection($payouts);
    }
}
```

- [ ] **Step 17.3: `ShowSellerPayoutController`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settlement\SellerPayoutResource;
use App\Models\SellerPayout;
use Illuminate\Http\Request;

final class ShowSellerPayoutController extends Controller
{
    public function __invoke(Request $request, SellerPayout $sellerPayout): SellerPayoutResource
    {
        $user = $request->user();
        abort_unless($user?->can('viewBySeller', $sellerPayout), 403);

        $sellerPayout->load(['office', 'paidByStaff', 'orders']);

        return new SellerPayoutResource($sellerPayout);
    }
}
```

- [ ] **Step 17.4: Register routes**

In `routes/api.php`, find the `/me/` group (probably `Route::prefix('me')->middleware('auth:sanctum')->group(...)`). Add inside:

```php
Route::get('earnings', \App\Http\Controllers\Api\Me\Settlement\ShowEarningsController::class)
    ->name('me.earnings.show')
    ->middleware('throttle:seller_earnings_read');

Route::get('seller-payouts', \App\Http\Controllers\Api\Me\Settlement\ListSellerPayoutsController::class)
    ->name('me.seller-payouts.index');

Route::get('seller-payouts/{sellerPayout:public_id}', \App\Http\Controllers\Api\Me\Settlement\ShowSellerPayoutController::class)
    ->name('me.seller-payouts.show');
```

(`throttle:seller_earnings_read` is defined in Task 21; route definition is safe to ship before the limiter — Laravel resolves middleware lazily.)

- [ ] **Step 17.5: Verify**

Run: `php artisan route:list --path=me/earnings`
Expected: 1 route listed. Same for `me/seller-payouts`.

- [ ] **Step 17.6: Commit**

```bash
git add app/Http/Controllers/Api/Me/Settlement/ routes/api.php
git commit -m "feat(settlement): seller earnings + payouts read endpoints (3)"
```

---

## Task 18: Office settlement controllers + routes

**[OWNER: Claude]**

3 endpoints — preview (GET), process (POST), list (GET).

**Files:**
- Create: `app/Http/Controllers/Api/Office/Settlement/PreviewSettlementController.php`
- Create: `app/Http/Controllers/Api/Office/Settlement/ProcessSettlementController.php`
- Create: `app/Http/Controllers/Api/Office/Settlement/ListSettlementsController.php`
- Modify: `routes/api.php`

- [ ] **Step 18.1: `PreviewSettlementController`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settlement\SettlementPreviewResource;
use App\Models\User;
use App\Services\Settlement\SettlementService;
use Illuminate\Http\Request;

final class PreviewSettlementController extends Controller
{
    public function __construct(private readonly SettlementService $settlements)
    {
    }

    public function __invoke(Request $request, string $driverPublicId): SettlementPreviewResource
    {
        $staff = $request->user();
        abort_unless($staff !== null, 401);

        $driver = User::query()
            ->where('public_id', $driverPublicId)
            ->whereHas('roles', fn ($q) => $q->where('name', 'driver'))
            ->first();

        abort_unless($driver !== null, 404, 'Driver not found.');
        abort_unless($staff->can('previewForDriver', [\App\Models\Settlement::class, $driver]), 403);

        return new SettlementPreviewResource($this->settlements->preview($driver));
    }
}
```

- [ ] **Step 18.2: `ProcessSettlementController`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Settlement;

use App\Exceptions\Settlement\EmptySettlementException;
use App\Exceptions\Settlement\SettlementExcessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\ProcessSettlementRequest;
use App\Http\Resources\Settlement\SettlementResource;
use App\Models\OfficeLocation;
use App\Models\User;
use App\Services\Settlement\SettlementService;
use Illuminate\Http\JsonResponse;

final class ProcessSettlementController extends Controller
{
    public function __construct(private readonly SettlementService $settlements)
    {
    }

    public function __invoke(ProcessSettlementRequest $request): JsonResponse|SettlementResource
    {
        $staff = $request->user();
        abort_unless($staff !== null, 401);
        abort_unless($staff->can('process', \App\Models\Settlement::class), 403);

        $driver = User::query()
            ->where('public_id', $request->driverPublicId())
            ->whereHas('roles', fn ($q) => $q->where('name', 'driver'))
            ->first();
        abort_unless($driver !== null, 404, 'Driver not found.');

        $office = $this->resolveStaffOffice($staff);
        abort_unless($office !== null, 403, 'Staff has no active office assignment.');

        try {
            $settlement = $this->settlements->process(
                driver: $driver,
                staff: $staff,
                office: $office,
                cashReceivedFromDriver: $request->cashReceived(),
                cashPaidToDriver: $request->cashPaid(),
                notes: $request->input('notes'),
            );
        } catch (SettlementExcessException $e) {
            return response()->json([
                'error' => $e->errorCode()->value,
                'message' => $e->getMessage(),
                'expected_net' => $e->expectedNet,
                'actual_net' => $e->actualNet,
            ], $e->errorCode()->httpStatus());
        } catch (EmptySettlementException $e) {
            return response()->json([
                'error' => $e->errorCode()->value,
                'message' => $e->getMessage(),
            ], $e->errorCode()->httpStatus());
        }

        return new SettlementResource($settlement);
    }

    private function resolveStaffOffice(User $staff): ?OfficeLocation
    {
        $assignment = $staff->officeStaffAssignments()->active()->first();
        return $assignment?->office;
    }
}
```

- [ ] **Step 18.3: `ListSettlementsController` (office-scoped)**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\ListOfficeSettlementsRequest;
use App\Http\Resources\Settlement\SettlementResource;
use App\Models\Settlement;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListSettlementsController extends Controller
{
    public function __invoke(ListOfficeSettlementsRequest $request): AnonymousResourceCollection
    {
        $staff = $request->user();
        abort_unless($staff !== null, 401);

        $assignedOfficeIds = $staff->officeStaffAssignments()
            ->active()
            ->pluck('office_id')
            ->all();

        abort_unless(! empty($assignedOfficeIds), 403, 'Staff has no active office assignment.');

        $query = Settlement::query()
            ->whereIn('office_id', $assignedOfficeIds)
            ->with(['driver', 'office', 'processedByStaff']);

        if ($from = $request->input('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('created_at', '<=', $to);
        }

        $perPage = (int) $request->input('per_page', 20);

        return SettlementResource::collection(
            $query->latest('created_at')->paginate($perPage)
        );
    }
}
```

- [ ] **Step 18.4: Register routes**

In `routes/api.php`, find the office_staff group. Add:

```php
Route::prefix('drivers/{driverPublicId}')->group(function (): void {
    Route::get('settlement-preview', \App\Http\Controllers\Api\Office\Settlement\PreviewSettlementController::class)
        ->name('office.settlements.preview');
});

Route::post('settlements', \App\Http\Controllers\Api\Office\Settlement\ProcessSettlementController::class)
    ->middleware('throttle:office_settlement')
    ->name('office.settlements.process');

Route::get('settlements', \App\Http\Controllers\Api\Office\Settlement\ListSettlementsController::class)
    ->name('office.settlements.index');
```

- [ ] **Step 18.5: Verify**

Run: `php artisan route:list --path=office/settlements`
Run: `php artisan route:list --path=office/drivers`
Expected: 3 routes total (1 preview, 1 POST, 1 list).

- [ ] **Step 18.6: Commit**

```bash
git add app/Http/Controllers/Api/Office/Settlement/ routes/api.php
git commit -m "feat(settlement): office settlement endpoints — preview, process, list (3)"
```

---

## Task 19: Office seller-payouts controllers + routes

**[OWNER: Claude]**

3 endpoints — seller lookup, payout process, payout list.

**Files:**
- Create: `app/Http/Controllers/Api/Office/Settlement/LookupSellerPayoutController.php`
- Create: `app/Http/Controllers/Api/Office/Settlement/ProcessSellerPayoutController.php`
- Create: `app/Http/Controllers/Api/Office/Settlement/ListSellerPayoutsController.php`
- Modify: `routes/api.php`

- [ ] **Step 19.1: `LookupSellerPayoutController`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\LookupSellerPayoutRequest;
use App\Http\Resources\Settlement\SellerEarningResource;
use App\Models\User;
use App\Services\Settlement\SellerPayoutService;
use Illuminate\Http\JsonResponse;

final class LookupSellerPayoutController extends Controller
{
    public function __construct(private readonly SellerPayoutService $payouts)
    {
    }

    public function __invoke(LookupSellerPayoutRequest $request): JsonResponse
    {
        $staff = $request->user();
        abort_unless($staff !== null, 401);
        abort_unless($staff->can('lookupSeller', \App\Models\SellerPayout::class), 403);

        $seller = $this->resolveSeller($request);
        if ($seller === null) {
            return response()->json([
                'error' => 'SELLER_NOT_FOUND',
                'message' => 'No seller matched the provided phone or public_id.',
            ], 404);
        }

        $earnings = $this->payouts->availableEarningsFor($seller);
        $total = $earnings->reduce(
            static fn (string $carry, $e): string => bcadd($carry, (string) $e->amount, 2),
            '0.00',
        );

        return response()->json([
            'seller' => [
                'id' => $seller->public_id,
                'name' => $seller->full_name ?? $seller->name,
                'phone' => $seller->phone_number,
            ],
            'available_total' => $total,
            'available_count' => $earnings->count(),
            'earnings' => SellerEarningResource::collection($earnings)->resolve(),
        ]);
    }

    private function resolveSeller(LookupSellerPayoutRequest $request): ?User
    {
        if ($publicId = $request->input('public_id')) {
            return User::query()->where('public_id', $publicId)->first();
        }
        if ($phone = $request->input('phone')) {
            return User::query()->where('phone_number', $phone)->first();
        }
        return null;
    }
}
```

- [ ] **Step 19.2: `ProcessSellerPayoutController`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Settlement;

use App\Exceptions\Settlement\PayoutValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\ProcessSellerPayoutRequest;
use App\Http\Resources\Settlement\SellerPayoutResource;
use App\Models\OfficeLocation;
use App\Models\User;
use App\Services\Settlement\SellerPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

final class ProcessSellerPayoutController extends Controller
{
    public function __construct(private readonly SellerPayoutService $payouts)
    {
    }

    public function __invoke(ProcessSellerPayoutRequest $request): JsonResponse|SellerPayoutResource
    {
        $staff = $request->user();
        abort_unless($staff !== null, 401);
        abort_unless($staff->can('process', \App\Models\SellerPayout::class), 403);

        $seller = User::query()->where('public_id', $request->sellerPublicId())->first();
        if ($seller === null) {
            return response()->json([
                'error' => 'SELLER_NOT_FOUND',
                'message' => 'Seller not found.',
            ], 404);
        }

        $office = $this->resolveStaffOffice($staff);
        if ($office === null) {
            return response()->json([
                'error' => 'OFFICE_NOT_ASSIGNED',
                'message' => 'Staff has no active office assignment.',
            ], 403);
        }

        try {
            $payout = $this->payouts->process(
                seller: $seller,
                staff: $staff,
                office: $office,
                earningPublicIds: Collection::make($request->earningPublicIds()),
                totalForSanityCheck: $request->totalAmount(),
                notes: $request->input('notes'),
            );
        } catch (PayoutValidationException $e) {
            return response()->json([
                'error' => $e->errorCode()->value,
                'message' => $e->getMessage(),
            ], $e->errorCode()->httpStatus());
        }

        return new SellerPayoutResource($payout);
    }

    private function resolveStaffOffice(User $staff): ?OfficeLocation
    {
        return $staff->officeStaffAssignments()->active()->first()?->office;
    }
}
```

- [ ] **Step 19.3: `ListSellerPayoutsController` (office-scoped)**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\ListSellerPayoutsRequest;
use App\Http\Resources\Settlement\SellerPayoutResource;
use App\Models\SellerPayout;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListSellerPayoutsController extends Controller
{
    public function __invoke(ListSellerPayoutsRequest $request): AnonymousResourceCollection
    {
        $staff = $request->user();
        abort_unless($staff !== null, 401);

        $assignedOfficeIds = $staff->officeStaffAssignments()
            ->active()
            ->pluck('office_id')
            ->all();

        abort_unless(! empty($assignedOfficeIds), 403, 'Staff has no active office assignment.');

        $query = SellerPayout::query()
            ->whereIn('office_id', $assignedOfficeIds)
            ->with(['user', 'office', 'paidByStaff']);

        if ($from = $request->input('from')) {
            $query->where('paid_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('paid_at', '<=', $to);
        }

        return SellerPayoutResource::collection(
            $query->latest('paid_at')->paginate((int) $request->input('per_page', 20))
        );
    }
}
```

- [ ] **Step 19.4: Register routes**

In `routes/api.php` office group, add:

```php
Route::get('seller-payouts/lookup', \App\Http\Controllers\Api\Office\Settlement\LookupSellerPayoutController::class)
    ->name('office.seller-payouts.lookup');

Route::post('seller-payouts', \App\Http\Controllers\Api\Office\Settlement\ProcessSellerPayoutController::class)
    ->middleware('throttle:office_payout')
    ->name('office.seller-payouts.process');

Route::get('seller-payouts', \App\Http\Controllers\Api\Office\Settlement\ListSellerPayoutsController::class)
    ->name('office.seller-payouts.index');
```

- [ ] **Step 19.5: Verify**

Run: `php artisan route:list --path=office/seller-payouts`
Expected: 3 routes.

- [ ] **Step 19.6: Commit**

```bash
git add app/Http/Controllers/Api/Office/Settlement/ routes/api.php
git commit -m "feat(settlement): office seller-payout endpoints — lookup, process, list (3)"
```

---

## Task 20: Admin settlement + payouts controllers + routes (incl. reversal)

**[OWNER: Claude]**

4 endpoints.

**Files:**
- Create: `app/Http/Controllers/Api/Admin/Settlement/ListSettlementsController.php`
- Create: `app/Http/Controllers/Api/Admin/Settlement/ShowSettlementController.php`
- Create: `app/Http/Controllers/Api/Admin/Settlement/ReverseSettlementController.php`
- Create: `app/Http/Controllers/Api/Admin/Settlement/ListSellerPayoutsController.php`
- Modify: `routes/api.php`

- [ ] **Step 20.1: `ListSettlementsController` (admin global)**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\ListSettlementsRequest;
use App\Http\Resources\Settlement\SettlementResource;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListSettlementsController extends Controller
{
    public function __invoke(ListSettlementsRequest $request): AnonymousResourceCollection
    {
        $query = Settlement::query()->with(['driver', 'office', 'processedByStaff']);

        if ($driverPublicId = $request->input('driver_public_id')) {
            $driverId = User::query()->where('public_id', $driverPublicId)->value('id');
            $query->where('driver_id', $driverId ?? -1);
        }
        if ($officeId = $request->input('office_id')) {
            $query->where('office_id', $officeId);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($from = $request->input('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('created_at', '<=', $to);
        }

        return SettlementResource::collection(
            $query->latest('created_at')->paginate((int) $request->input('per_page', 20))
        );
    }
}
```

- [ ] **Step 20.2: `ShowSettlementController` (admin)**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settlement\SettlementResource;
use App\Models\Settlement;

final class ShowSettlementController extends Controller
{
    public function __invoke(Settlement $settlement): SettlementResource
    {
        $settlement->load(['driver', 'office', 'processedByStaff', 'orders']);

        return new SettlementResource($settlement);
    }
}
```

- [ ] **Step 20.3: `ReverseSettlementController`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Settlement;

use App\Exceptions\Settlement\SettlementNotReversibleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\ReverseSettlementRequest;
use App\Http\Resources\Settlement\SettlementResource;
use App\Models\Settlement;
use App\Services\Settlement\SettlementReversalService;
use Illuminate\Http\JsonResponse;

final class ReverseSettlementController extends Controller
{
    public function __construct(private readonly SettlementReversalService $reversal)
    {
    }

    public function __invoke(
        ReverseSettlementRequest $request,
        Settlement $settlement,
    ): JsonResponse|SettlementResource {
        $admin = $request->user();
        abort_unless($admin !== null, 401);
        abort_unless($admin->can('reverse', $settlement), 403);

        try {
            $correcting = $this->reversal->reverse($settlement, $admin, $request->reason());
        } catch (SettlementNotReversibleException $e) {
            return response()->json([
                'error' => $e->errorCode()->value,
                'message' => $e->getMessage(),
            ], $e->errorCode()->httpStatus());
        }

        return new SettlementResource($correcting);
    }
}
```

- [ ] **Step 20.4: `ListSellerPayoutsController` (admin global)**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Settlement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\ListSellerPayoutsRequest;
use App\Http\Resources\Settlement\AdminSellerPayoutResource;
use App\Models\SellerPayout;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListSellerPayoutsController extends Controller
{
    public function __invoke(ListSellerPayoutsRequest $request): AnonymousResourceCollection
    {
        $query = SellerPayout::query()->with(['user', 'office', 'paidByStaff', 'orders']);

        if ($sellerPublicId = $request->input('seller_public_id')) {
            $sellerId = User::query()->where('public_id', $sellerPublicId)->value('id');
            $query->where('user_id', $sellerId ?? -1);
        }
        if ($officeId = $request->input('office_id')) {
            $query->where('office_id', $officeId);
        }
        if ($from = $request->input('from')) {
            $query->where('paid_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('paid_at', '<=', $to);
        }

        return AdminSellerPayoutResource::collection(
            $query->latest('paid_at')->paginate((int) $request->input('per_page', 20))
        );
    }
}
```

- [ ] **Step 20.5: Register routes**

In `routes/api.php` admin group, add:

```php
Route::get('settlements', \App\Http\Controllers\Api\Admin\Settlement\ListSettlementsController::class)
    ->name('admin.settlements.index');

Route::get('settlements/{settlement:public_id}', \App\Http\Controllers\Api\Admin\Settlement\ShowSettlementController::class)
    ->name('admin.settlements.show');

Route::post('settlements/{settlement:public_id}/reverse', \App\Http\Controllers\Api\Admin\Settlement\ReverseSettlementController::class)
    ->name('admin.settlements.reverse');

Route::get('seller-payouts', \App\Http\Controllers\Api\Admin\Settlement\ListSellerPayoutsController::class)
    ->name('admin.seller-payouts.index');
```

- [ ] **Step 20.6: Verify**

Run: `php artisan route:list --path=admin/settlements`
Run: `php artisan route:list --path=admin/seller-payouts`
Expected: 4 routes total (3 settlements, 1 payouts).

- [ ] **Step 20.7: Commit**

```bash
git add app/Http/Controllers/Api/Admin/Settlement/ routes/api.php
git commit -m "feat(settlement): admin settlement + payouts endpoints (4)"
```

---

## Task 21: Rate limiters

**[OWNER: Codex]**

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 21.1: Read existing rate limiters**

Run: `cat app/Providers/AppServiceProvider.php`
Find the `configureRateLimiters()` method.

- [ ] **Step 21.2: Add three new limiters**

Inside the `configureRateLimiters()` method, add:

```php
RateLimiter::for('office_settlement', static function (Request $request): Limit {
    $key = (string) ($request->user()?->id ?? $request->ip());
    return Limit::perMinute(60)->by($key);
});

RateLimiter::for('office_payout', static function (Request $request): Limit {
    $key = (string) ($request->user()?->id ?? $request->ip());
    return Limit::perMinute(60)->by($key);
});

RateLimiter::for('seller_earnings_read', static function (Request $request): Limit {
    $key = (string) ($request->user()?->id ?? $request->ip());
    return Limit::perMinute(30)->by($key);
});
```

Verify `RateLimiter`, `Limit`, and `Request` are already imported at the top of the file (they should be — existing limiters use them).

- [ ] **Step 21.3: Verify**

Run: `php artisan route:list --path=office/settlements`
Expected: the POST line shows `throttle:office_settlement` middleware applied with no errors.

Send a probe with curl (should not 500):
```bash
curl -s -X POST http://127.0.0.1:8000/api/office/settlements -H 'Accept: application/json' -o /dev/null -w '%{http_code}\n'
```
Expected: `401` (unauthenticated) or `422` (validation) — NOT `500`.

- [ ] **Step 21.4: Commit**

```bash
git add app/Providers/AppServiceProvider.php
git commit -m "feat(settlement): 3 rate limiters for settlement + payout endpoints"
```

---

## Task 22: Localization strings (en + ar)

**[OWNER: Codex]**

**Files:**
- Modify: `lang/en/order_messages.php` (or create `lang/en/settlement_messages.php`)
- Modify: `lang/ar/order_messages.php` (or create `lang/ar/settlement_messages.php`)

- [ ] **Step 22.1: Check existing file shape**

Run: `cat lang/en/order_messages.php | head -30`
If the file is small/focused, create a NEW `settlement_messages.php`. If it's a general messages bucket, append.

- [ ] **Step 22.2: Add English settlement keys**

Create `lang/en/settlement_messages.php`:

```php
<?php

declare(strict_types=1);

return [
    'settlement' => [
        'excess_rejected' => 'Cash received exceeds amount owed. Hand the excess back to the driver before submitting.',
        'empty' => 'Driver has no balances to settle.',
        'cash_mismatch' => 'Cash counts do not reconcile against the driver account.',
        'not_reversible' => 'Settlement can no longer be reversed.',
        'already_reversed' => 'Settlement has already been reversed.',
    ],
    'payout' => [
        'earning_not_available' => 'One or more selected earnings are not available for payout.',
        'earning_wrong_seller' => 'Selected earnings do not all belong to this seller.',
        'total_mismatch' => 'Submitted total does not match the sum of selected earnings.',
        'below_minimum' => 'Selected total is below the minimum payout amount.',
        'empty_selection' => 'Select at least one earning to pay out.',
    ],
    'office' => [
        'not_assigned' => 'You are not assigned to any active office.',
    ],
    'seller' => [
        'not_found' => 'Seller not found.',
    ],
    'driver' => [
        'not_found' => 'Driver not found.',
    ],
];
```

- [ ] **Step 22.3: Add Arabic translations**

Create `lang/ar/settlement_messages.php`:

```php
<?php

declare(strict_types=1);

return [
    'settlement' => [
        'excess_rejected' => 'النقدية المستلمة تتجاوز المبلغ المستحق. أعد الفائض إلى السائق قبل التأكيد.',
        'empty' => 'لا توجد أرصدة لتسويتها مع هذا السائق.',
        'cash_mismatch' => 'المبالغ النقدية لا تطابق حساب السائق.',
        'not_reversible' => 'لم يعد بالإمكان عكس هذه التسوية.',
        'already_reversed' => 'تم عكس هذه التسوية مسبقاً.',
    ],
    'payout' => [
        'earning_not_available' => 'بعض الأرباح المختارة غير متاحة للصرف.',
        'earning_wrong_seller' => 'الأرباح المختارة لا تخص هذا البائع.',
        'total_mismatch' => 'المجموع المُقدَّم لا يطابق مجموع الأرباح المختارة.',
        'below_minimum' => 'المجموع أقل من الحد الأدنى للصرف.',
        'empty_selection' => 'اختر أرباحاً واحدة على الأقل للصرف.',
    ],
    'office' => [
        'not_assigned' => 'لست مُعيّناً لأي مكتب نشط.',
    ],
    'seller' => [
        'not_found' => 'البائع غير موجود.',
    ],
    'driver' => [
        'not_found' => 'السائق غير موجود.',
    ],
];
```

- [ ] **Step 22.4: Verify**

Run: `php artisan tinker --execute="dd(__('settlement_messages.settlement.empty'), __('settlement_messages.payout.below_minimum'));"`
Expected: returns the English strings (assuming default locale is `en`).

- [ ] **Step 22.5: Commit**

```bash
git add lang/en/settlement_messages.php lang/ar/settlement_messages.php
git commit -m "feat(settlement): localization strings (en + ar)"
```

---

## Task 23: Smoke scenarios in `scripts/orders-e2e.php`

**[OWNER: Claude]**

Extend the existing smoke script (currently 17 scenarios after D) with 19 new settlement scenarios. The script is rollback-wrapped per scenario.

**Files:**
- Modify: `scripts/orders-e2e.php`

- [ ] **Step 23.1: Read the existing script structure**

Run: `head -120 scripts/orders-e2e.php`
Note the per-scenario pattern (likely a `runScenario(string $name, Closure $body)` helper that wraps in `DB::beginTransaction()` + `DB::rollBack()`).

- [ ] **Step 23.2: Append the 19 new scenarios**

Append the following block at the end of the script (adapt the surrounding helper-call shape to match what already exists — the scenario bodies below assume helpers `seedDriver()`, `seedSeller()`, `seedOffice()`, `createSettledSaleOrder()`, etc.; if those don't exist, inline the equivalent factory/seeder calls).

```php
// ─────────────────────────────────────────────────────────────────────
// Settlement & Seller Payouts — milestone 2026-05-17 (19 scenarios)
// ─────────────────────────────────────────────────────────────────────

runScenario('Settlement: match exact', function (): void {
    $driver = seedDriverWithBuckets(cash: '100.00', earnings: '30.00', debt: '0.00');
    $staff = seedOfficeStaff($officeId = seedOffice());
    $office = \App\Models\OfficeLocation::find($officeId);

    $settlement = app(\App\Services\Settlement\SettlementService::class)
        ->process($driver, $staff, $office, '70.00', '0.00', null);

    assertEqual($settlement->status->value, 'completed');
    $driver->driverAccount->refresh();
    assertEqual((string) $driver->driverAccount->cash_to_deposit, '0.00');
    assertEqual((string) $driver->driverAccount->earnings_balance, '0.00');
    assertEqual((string) $driver->driverAccount->debt_balance, '0.00');
});

runScenario('Settlement: zero net (buckets cancel)', function (): void {
    // cash_to_deposit + debt = earnings exactly. No cash changes hands.
    $driver = seedDriverWithBuckets(cash: '50.00', earnings: '50.00', debt: '0.00');
    $staff = seedOfficeStaff($officeId = seedOffice());
    $office = \App\Models\OfficeLocation::find($officeId);

    $settlement = app(\App\Services\Settlement\SettlementService::class)
        ->process($driver, $staff, $office, '0.00', '0.00', null);

    assertEqual($settlement->cashMovement(), '0.00');
});

runScenario('Settlement: platform pays driver', function (): void {
    // earnings > cash + debt. Platform pays driver.
    $driver = seedDriverWithBuckets(cash: '20.00', earnings: '100.00', debt: '0.00');
    $staff = seedOfficeStaff($officeId = seedOffice());
    $office = \App\Models\OfficeLocation::find($officeId);

    $settlement = app(\App\Services\Settlement\SettlementService::class)
        ->process($driver, $staff, $office, '0.00', '80.00', null);

    assertEqual((string) $settlement->cash_paid_to_driver, '80.00');
});

runScenario('Settlement: acknowledged shortage', function (): void {
    $driver = seedDriverWithBuckets(cash: '100.00', earnings: '0.00', debt: '0.00');
    $staff = seedOfficeStaff($officeId = seedOffice());
    $office = \App\Models\OfficeLocation::find($officeId);

    $settlement = app(\App\Services\Settlement\SettlementService::class)
        ->process($driver, $staff, $office, '70.00', '0.00', 'driver short');

    assertEqual((string) $settlement->shortage_amount, '30.00');
    $driver->driverAccount->refresh();
    assertEqual((string) $driver->driverAccount->debt_balance, '30.00');
});

runScenario('Settlement: excess rejected (422)', function (): void {
    $driver = seedDriverWithBuckets(cash: '50.00', earnings: '0.00', debt: '0.00');
    $staff = seedOfficeStaff($officeId = seedOffice());
    $office = \App\Models\OfficeLocation::find($officeId);

    try {
        app(\App\Services\Settlement\SettlementService::class)
            ->process($driver, $staff, $office, '80.00', '0.00', null);
        throw new \RuntimeException('Expected SettlementExcessException');
    } catch (\App\Exceptions\Settlement\SettlementExcessException $e) {
        assertEqual($e->errorCode()->value, 'SETTLEMENT_EXCESS_REJECTED');
    }
});

runScenario('Settlement: empty buckets rejected', function (): void {
    $driver = seedDriverWithBuckets(cash: '0.00', earnings: '0.00', debt: '0.00');
    $staff = seedOfficeStaff($officeId = seedOffice());
    $office = \App\Models\OfficeLocation::find($officeId);

    try {
        app(\App\Services\Settlement\SettlementService::class)
            ->process($driver, $staff, $office, '0.00', '0.00', null);
        throw new \RuntimeException('Expected EmptySettlementException');
    } catch (\App\Exceptions\Settlement\EmptySettlementException $e) {
        assertEqual($e->errorCode()->value, 'SETTLEMENT_EMPTY');
    }
});

runScenario('Settlement: no pending sale earnings (standard-delivery-only driver)', function (): void {
    $driver = seedDriverWithBuckets(cash: '100.00', earnings: '0.00', debt: '0.00');
    // No seller_earnings rows seeded.
    $staff = seedOfficeStaff($officeId = seedOffice());
    $office = \App\Models\OfficeLocation::find($officeId);

    $settlement = app(\App\Services\Settlement\SettlementService::class)
        ->process($driver, $staff, $office, '100.00', '0.00', null);

    assertEqual($settlement->orders()->count(), 0);
});

runScenario('Settlement: multi-seller earnings flip together', function (): void {
    $driver = seedDriverWithBuckets(cash: '300.00', earnings: '0.00', debt: '0.00');
    seedSaleOrderWithEarning($driver, $sellerA = seedSeller(), itemPrice: '100.00', commission: '5.00');
    seedSaleOrderWithEarning($driver, $sellerB = seedSeller(), itemPrice: '150.00', commission: '7.50');
    seedSaleOrderWithEarning($driver, $sellerC = seedSeller(), itemPrice: '50.00', commission: '2.50');
    $staff = seedOfficeStaff($officeId = seedOffice());
    $office = \App\Models\OfficeLocation::find($officeId);

    $settlement = app(\App\Services\Settlement\SettlementService::class)
        ->process($driver, $staff, $office, '300.00', '0.00', null);

    $pendingClearance = \App\Models\SellerEarning::query()
        ->whereIn('seller_user_id', [$sellerA->id, $sellerB->id, $sellerC->id])
        ->pendingClearance()
        ->count();
    assertEqual($pendingClearance, 3);
});

runScenario('Cron: clearance flips eligible earnings to available', function (): void {
    $earning = seedClearedEarning($cleared = now()->subHours(49)); // 49h ago

    (new \App\Jobs\ClearSellerEarningsJob())->handle();

    $earning->refresh();
    assertEqual($earning->status->value, 'available');
    assertNotNull($earning->available_at);
});

runScenario('Cron: clearance skips ineligible (<48h)', function (): void {
    $earning = seedClearedEarning(now()->subHours(10));

    (new \App\Jobs\ClearSellerEarningsJob())->handle();

    $earning->refresh();
    assertEqual($earning->status->value, 'pending_clearance');
});

runScenario('Payout: happy path — all available', function (): void {
    $seller = seedSeller();
    $earningA = seedAvailableEarning($seller, '30.00');
    $earningB = seedAvailableEarning($seller, '47.00');
    $earningC = seedAvailableEarning($seller, '25.00');
    $staff = seedOfficeStaff($officeId = seedOffice());
    $office = \App\Models\OfficeLocation::find($officeId);

    $payout = app(\App\Services\Settlement\SellerPayoutService::class)->process(
        $seller, $staff, $office,
        collect([$earningA->public_id, $earningB->public_id, $earningC->public_id]),
        '102.00',
    );

    assertEqual((string) $payout->amount, '102.00');
    assertEqual($payout->orders()->count(), 3);
    foreach ([$earningA, $earningB, $earningC] as $e) {
        $e->refresh();
        assertEqual($e->status->value, 'paid_out');
    }
});

runScenario('Payout: partial selection', function (): void {
    $seller = seedSeller();
    $earningA = seedAvailableEarning($seller, '30.00');
    $earningB = seedAvailableEarning($seller, '47.00');
    $staff = seedOfficeStaff($officeId = seedOffice());
    $office = \App\Models\OfficeLocation::find($officeId);

    $payout = app(\App\Services\Settlement\SellerPayoutService::class)->process(
        $seller, $staff, $office, collect([$earningA->public_id]), '30.00',
    );

    $earningB->refresh();
    assertEqual($earningB->status->value, 'available');
});

runScenario('Payout: total sanity-check mismatch (422)', function (): void {
    $seller = seedSeller();
    $earningA = seedAvailableEarning($seller, '30.00');
    $earningB = seedAvailableEarning($seller, '20.00');
    $staff = seedOfficeStaff($officeId = seedOffice());
    $office = \App\Models\OfficeLocation::find($officeId);

    try {
        app(\App\Services\Settlement\SellerPayoutService::class)->process(
            $seller, $staff, $office,
            collect([$earningA->public_id, $earningB->public_id]),
            '100.00', // wrong: actual is 50.00
        );
        throw new \RuntimeException('Expected PayoutValidationException');
    } catch (\App\Exceptions\Settlement\PayoutValidationException $e) {
        assertEqual($e->errorCode()->value, 'PAYOUT_TOTAL_MISMATCH');
    }
});

runScenario('Payout: below minimum (422)', function (): void {
    $seller = seedSeller();
    $earning = seedAvailableEarning($seller, '5.00'); // below 20.00 min
    $staff = seedOfficeStaff($officeId = seedOffice());
    $office = \App\Models\OfficeLocation::find($officeId);

    try {
        app(\App\Services\Settlement\SellerPayoutService::class)->process(
            $seller, $staff, $office, collect([$earning->public_id]), '5.00',
        );
        throw new \RuntimeException('Expected PayoutValidationException');
    } catch (\App\Exceptions\Settlement\PayoutValidationException $e) {
        assertEqual($e->errorCode()->value, 'PAYOUT_BELOW_MINIMUM');
    }
});

runScenario('Payout: wrong seller (422)', function (): void {
    $sellerA = seedSeller();
    $sellerB = seedSeller();
    $earning = seedAvailableEarning($sellerA, '50.00');
    $staff = seedOfficeStaff($officeId = seedOffice());
    $office = \App\Models\OfficeLocation::find($officeId);

    try {
        app(\App\Services\Settlement\SellerPayoutService::class)->process(
            $sellerB, $staff, $office, collect([$earning->public_id]), '50.00',
        );
        throw new \RuntimeException('Expected PayoutValidationException');
    } catch (\App\Exceptions\Settlement\PayoutValidationException $e) {
        assertEqual($e->errorCode()->value, 'PAYOUT_EARNING_WRONG_SELLER');
    }
});

runScenario('Reversal: happy path — all pending_clearance', function (): void {
    [$settlement, $driver] = seedFreshSettlementWithPendingEarnings();
    $admin = seedAdmin();

    $correcting = app(\App\Services\Settlement\SettlementReversalService::class)
        ->reverse($settlement, $admin, 'Agent miscount caught at end of shift');

    $settlement->refresh();
    assertEqual($settlement->status->value, 'cancelled');
    assertEqual($correcting->status->value, 'completed');
    // Driver buckets restored:
    $driver->driverAccount->refresh();
    assertEqual((string) $driver->driverAccount->cash_to_deposit, (string) $settlement->cash_to_deposit_cleared);
});

runScenario('Reversal: blocked — earning past clearance', function (): void {
    [$settlement, $driver] = seedFreshSettlementWithPendingEarnings();
    // Advance one earning to available.
    \App\Models\SellerEarning::query()
        ->whereIn('order_id', $settlement->orders()->pluck('orders.id'))
        ->limit(1)
        ->update(['status' => 'available', 'available_at' => now()]);

    $admin = seedAdmin();

    try {
        app(\App\Services\Settlement\SettlementReversalService::class)
            ->reverse($settlement, $admin, 'too late');
        throw new \RuntimeException('Expected SettlementNotReversibleException');
    } catch (\App\Exceptions\Settlement\SettlementNotReversibleException $e) {
        assertEqual($e->errorCode()->value, 'SETTLEMENT_NOT_REVERSIBLE');
    }
});

runScenario('Office: staff at office A can settle driver assigned to office B', function (): void {
    $officeA = seedOffice('Office A');
    $officeB = seedOffice('Office B');
    $driver = seedDriverWithBuckets(cash: '50.00', earnings: '0.00', debt: '0.00', officeId: $officeB);
    $staff = seedOfficeStaff($officeA); // staff at A
    $officeAModel = \App\Models\OfficeLocation::find($officeA);

    $settlement = app(\App\Services\Settlement\SettlementService::class)
        ->process($driver, $staff, $officeAModel, '50.00', '0.00', null);

    assertEqual($settlement->office_id, $officeA);
});

runScenario('Concurrent settlement: second attempt sees empty buckets', function (): void {
    $driver = seedDriverWithBuckets(cash: '100.00', earnings: '0.00', debt: '0.00');
    $staff = seedOfficeStaff($officeId = seedOffice());
    $office = \App\Models\OfficeLocation::find($officeId);

    $svc = app(\App\Services\Settlement\SettlementService::class);
    $svc->process($driver, $staff, $office, '100.00', '0.00', null);

    try {
        $svc->process($driver, $staff, $office, '0.00', '0.00', null);
        throw new \RuntimeException('Expected EmptySettlementException');
    } catch (\App\Exceptions\Settlement\EmptySettlementException $e) {
        assertEqual($e->errorCode()->value, 'SETTLEMENT_EMPTY');
    }
});
```

- [ ] **Step 23.3: Add the new helper functions at the top of the file (or in the helpers section)**

```php
function seedDriverWithBuckets(string $cash, string $earnings, string $debt, ?int $officeId = null): \App\Models\User {
    $driver = \App\Models\User::factory()->create([
        'phone_verified_at' => now(),
    ]);
    $driver->assignRole('driver');
    \App\Models\DriverAccount::create([
        'driver_id' => $driver->id,
        'cash_to_deposit' => $cash,
        'earnings_balance' => $earnings,
        'debt_balance' => $debt,
        'max_cash_liability' => '500.00',
    ]);
    return $driver;
}

function seedSeller(): \App\Models\User {
    $seller = \App\Models\User::factory()->create(['phone_verified_at' => now()]);
    return $seller;
}

function seedOffice(?string $name = null): int {
    return \App\Models\OfficeLocation::factory()->create([
        'name' => $name ?? 'Test Office ' . random_int(1000, 9999),
        'is_active' => true,
    ])->id;
}

function seedOfficeStaff(int $officeId): \App\Models\User {
    $staff = \App\Models\User::factory()->create(['phone_verified_at' => now()]);
    $staff->assignRole('office_staff');
    \App\Models\OfficeStaffAssignment::create([
        'user_id' => $staff->id,
        'office_id' => $officeId,
        'is_active' => true,
        'assigned_at' => now(),
    ]);
    return $staff;
}

function seedAdmin(): \App\Models\User {
    $admin = \App\Models\User::factory()->create(['phone_verified_at' => now()]);
    $admin->assignRole('admin');
    return $admin;
}

function seedSaleOrderWithEarning(\App\Models\User $driver, \App\Models\User $seller, string $itemPrice, string $commission): \App\Models\SellerEarning {
    $order = \App\Models\Order::factory()->create([
        'order_type' => 'p2p_sale',
        'status' => 'delivered',
        'sender_user_id' => $seller->id,
        'driver_id' => $driver->id,
        'item_price' => $itemPrice,
        'commission_amount' => $commission,
    ]);
    return \App\Models\SellerEarning::create([
        'order_id' => $order->id,
        'seller_user_id' => $seller->id,
        'amount' => bcsub($itemPrice, $commission, 2),
        'status' => 'pending_settlement',
    ]);
}

function seedClearedEarning(\Carbon\Carbon $clearedAt): \App\Models\SellerEarning {
    $seller = seedSeller();
    $driver = seedDriverWithBuckets(cash: '0.00', earnings: '0.00', debt: '0.00');
    $order = \App\Models\Order::factory()->create([
        'order_type' => 'p2p_sale',
        'status' => 'delivered',
        'sender_user_id' => $seller->id,
        'driver_id' => $driver->id,
        'item_price' => '100.00',
        'commission_amount' => '5.00',
    ]);
    return \App\Models\SellerEarning::create([
        'order_id' => $order->id,
        'seller_user_id' => $seller->id,
        'amount' => '95.00',
        'status' => 'pending_clearance',
        'cleared_at' => $clearedAt,
    ]);
}

function seedAvailableEarning(\App\Models\User $seller, string $amount): \App\Models\SellerEarning {
    $driver = seedDriverWithBuckets(cash: '0.00', earnings: '0.00', debt: '0.00');
    $order = \App\Models\Order::factory()->create([
        'order_type' => 'p2p_sale',
        'status' => 'delivered',
        'sender_user_id' => $seller->id,
        'driver_id' => $driver->id,
        'item_price' => bcadd($amount, '5.00', 2),
        'commission_amount' => '5.00',
    ]);
    return \App\Models\SellerEarning::create([
        'order_id' => $order->id,
        'seller_user_id' => $seller->id,
        'amount' => $amount,
        'status' => 'available',
        'cleared_at' => now()->subHours(72),
        'available_at' => now()->subHours(24),
    ]);
}

function seedFreshSettlementWithPendingEarnings(): array {
    $driver = seedDriverWithBuckets(cash: '100.00', earnings: '0.00', debt: '0.00');
    $seller = seedSeller();
    seedSaleOrderWithEarning($driver, $seller, '100.00', '5.00');
    $staff = seedOfficeStaff($officeId = seedOffice());
    $office = \App\Models\OfficeLocation::find($officeId);

    $settlement = app(\App\Services\Settlement\SettlementService::class)
        ->process($driver, $staff, $office, '100.00', '0.00', null);

    return [$settlement, $driver];
}

function assertNotNull(mixed $value): void {
    if ($value === null) {
        throw new \RuntimeException('Assertion failed: expected non-null value.');
    }
}
```

Note: if `assertEqual()` and `runScenario()` are already defined in the existing script, do NOT redefine. Only add the helpers that don't already exist. Inspect the existing file first.

- [ ] **Step 23.4: Run the smoke script**

Run: `php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"`

Expected output: all 19 new scenarios pass, alongside the 17 existing. Total 36 scenarios. Any failure → stop, debug, fix, re-run.

- [ ] **Step 23.5: Commit**

```bash
git add scripts/orders-e2e.php
git commit -m "test(settlement): 19 smoke scenarios for settlement + payouts + reversal + cron"
```

---

## Task 24: Doc updates

**[OWNER: Claude]**

**Files:**
- Modify: `docs/CLAUDE.md` (Current Project State section + endpoint table for this milestone)
- Modify: `docs/SYSTEM_SPECIFICATION.md` (new §17 entry)
- Modify: `docs/CODEX.md` (if Codex shipped any tasks)

- [ ] **Step 24.1: Update `docs/CLAUDE.md`**

In the "Current Project State" block:

- Update `Last updated:` to today's date (2026-05-17 or whenever).
- Update the status line: `Status: ... Order lifecycle is now fully covered through every terminal state, plus driver settlement + seller payouts (milestone 2026-05-17 ✅). Real-time + admin staff CRUD are next.`
- Update the Group 8 table cell to: `settlements, settlement_orders, seller_payouts, seller_payout_orders, seller_earnings, office_inventory` (add the two new tables).

Add a new milestone block at the bottom of "Current Project State":

```markdown
### Settlement & Seller Payouts milestone (2026-05-17)

| Endpoint | Method | Auth |
|---|---|---|
| `/api/driver/settlements` | GET | sanctum + role:driver |
| `/api/driver/settlements/{public_id}` | GET | sanctum + role:driver + SettlementPolicy::viewByDriver |
| `/api/me/earnings` | GET | sanctum (throttle:seller_earnings_read) |
| `/api/me/seller-payouts` | GET | sanctum |
| `/api/me/seller-payouts/{public_id}` | GET | sanctum + SellerPayoutPolicy::viewBySeller |
| `/api/office/drivers/{driver_public_id}/settlement-preview` | GET | sanctum + role:office_staff + active assignment |
| `/api/office/settlements` | POST | sanctum + role:office_staff + active assignment (throttle:office_settlement) |
| `/api/office/settlements` | GET | sanctum + role:office_staff + active assignment |
| `/api/office/seller-payouts/lookup` | GET | sanctum + role:office_staff + active assignment |
| `/api/office/seller-payouts` | POST | sanctum + role:office_staff + active assignment (throttle:office_payout) |
| `/api/office/seller-payouts` | GET | sanctum + role:office_staff + active assignment |
| `/api/admin/settlements` | GET | sanctum + role:admin |
| `/api/admin/settlements/{public_id}` | GET | sanctum + role:admin |
| `/api/admin/settlements/{public_id}/reverse` | POST | sanctum + role:admin |
| `/api/admin/seller-payouts` | GET | sanctum + role:admin |

**Locked decisions:** all-or-nothing settlement (single atomic POST); excess rejected (must be handed back); disagreement leaves zero DB trace; new `seller_earnings` table is 1:1 with sale orders (orders table untouched); 48h clearance window via `payouts.clearance_hours`; any driver/seller at any office, gated by staff's assigned office; admin reversal blocked once any contributing earning leaves `pending_clearance`; identity verification at counter is visual (no payout codes); Bavix wallet not used for sellers (earnings computed from SUM); seller_earnings row spawned at delivery time, never at order creation.

**Architecture:** Three services own all writes — `SettlementService` (preview + process, sole writer of `settlements` + `settlement_orders` + driver_account_transactions), `SellerPayoutService` (lookup + process, sole writer of `seller_payouts` + `seller_payout_orders`), `SettlementReversalService` (admin correcting-settlement pattern, immutable original + reversed twin). `CodeVerificationService` spawns the `seller_earnings` row inline on delivery success for sale orders only. `User::isAssignedToOffice(int)` helper extracted to back all office-scope policy checks.

**Background job:** `ClearSellerEarningsJob`, scheduled daily as `seller-earnings.clearance`. Flips `pending_clearance → available` once `cleared_at <= now() - payouts.clearance_hours`.

**Smoke test:** `scripts/orders-e2e.php` now covers 36 rollback-wrapped scenarios.
```

Add to the "Next Steps" section: remove "Office staff settlement processing" (item #1 — done), promote everything else by one.

- [ ] **Step 24.2: Update `docs/SYSTEM_SPECIFICATION.md`**

Add §17.11 (or whichever is next):

```markdown
### 17.11 Settlement & Seller Payouts milestone (2026-05-17) ✅

Closes the cash loop. Office staff reconciles a driver's three buckets in one atomic transaction (all-or-nothing, excess rejected, disagreement leaves zero trace per §11.4). Sellers track per-order earnings through `pending_settlement → pending_clearance → available → paid_out` via the new `seller_earnings` table (1:1 with sale orders; the wide `orders` table stays untouched). 48h clearance window between settlement and seller-availability is configurable. Sellers collect cash at any active office; identity verification at the counter is visual (staff judgement) — no payout codes. Admin reversal is permitted only while every contributing earning is still `pending_clearance` — the standard correcting-settlement pattern (immutable original + reversed twin row).

15 endpoints: 2 driver, 3 seller, 6 office staff (3 settlement + 3 payout), 4 admin. 1 daily cron (`ClearSellerEarningsJob`). 1 new table (`seller_earnings`) + 1 new pivot (`seller_payout_orders`) + schema cleanup on `seller_payouts` (dropped the unused request/approval workflow columns).
```

- [ ] **Step 24.3: Update `docs/CODEX.md`**

If Codex shipped any of tasks 1–7, 12, 14–17, 21–22, add a "Slice 12" entry summarizing which Codex tasks were included.

```markdown
## Slice 12 — Settlement & Seller Payouts (2026-05-17)

Codex tasks shipped:
- Migrations: `seller_earnings`, simplified `seller_payouts`, `seller_payout_orders` pivot (Tasks 1–3)
- Enums: `SellerEarningStatus`, simplified `SellerPayoutStatus`, `SettlementErrorCode` (Task 4)
- Models: `SellerEarning`, updated `SellerPayout`, `SellerPayoutOrder` pivot model (Tasks 5–6)
- Platform settings extension (Task 7)
- `ClearSellerEarningsJob` + cron registration (Task 12)
- 7 FormRequests, 6 Resources (Tasks 14–15)
- Driver settlement-history + seller earnings/payouts controllers (Tasks 16–17)
- 3 rate limiters (Task 21)
- Localization en + ar (Task 22)

Claude tasks shipped: policies (8), three settlement services (9–11), `CodeVerificationService` integration (13), office settlement controllers (18), office seller-payout controllers (19), admin settlement controllers (20), 19 smoke scenarios (23), docs (24).
```

- [ ] **Step 24.4: Verify docs render and aren't broken**

Run: `grep -c "Settlement & Seller Payouts" docs/CLAUDE.md docs/SYSTEM_SPECIFICATION.md`
Expected: both files show at least 1 match each.

- [ ] **Step 24.5: Commit**

```bash
git add docs/CLAUDE.md docs/SYSTEM_SPECIFICATION.md docs/CODEX.md
git commit -m "docs(settlement): document milestone — CLAUDE.md, system spec, CODEX.md"
```

---

## Self-Review

This is the final inline check before handing off.

### Spec coverage

Walking through each spec section:

| Spec § | Requirement | Plan task |
|---|---|---|
| §1 Purpose | Close the cash loop end-to-end | All tasks together |
| §2 Non-goals | None spawn code | (correctly absent from plan) |
| §3.1 Full scope | One milestone covers settlement + payout + clearance | Tasks 1–24 |
| §3.2 All-or-nothing settlement | Service zeros all 3 buckets in one shot | Task 9 |
| §3.3 Single atomic POST | One commit endpoint + preview GET | Tasks 9, 18 |
| §3.4 Zero trace on disagreement | Agent simply doesn't call API; no code needed | (correctly no task) |
| §3.5 Server-computed outcome, excess→422 | `SettlementExcessException` thrown when `actualNet > expectedNet` | Task 9 |
| §3.6 `seller_earnings` table 1:1 | New table, unique on order_id | Task 1 |
| §3.7 48h clearance | `payouts.clearance_hours` setting + cron | Tasks 7, 12 |
| §3.8 Office authorization | `isAssignedToOffice` + policies | Task 8 |
| §3.9 Admin reversal narrowed scope | `SettlementReversalService` blocks past pending_clearance | Task 11 |
| §3.10 Visual identity verification | No payout codes generated; staff judges visually | (no code task — by design) |
| §3.11 Per-order partial payout | Service accepts arbitrary earning subset | Task 10 |
| §3.12 Earning row at delivery time | `CodeVerificationService` inline spawn | Task 13 |
| §3.13 No driver reversal past clearance | Same constraint as §3.9 in reversal service | Task 11 |
| §4 State machines | `SellerEarningStatus` enum + service transitions | Tasks 4, 9, 10, 11, 12 |
| §5 Schema | 3 migrations + relation additions | Tasks 1–3, 5, 6 |
| §6.1 SettlementService | Class + preview + process | Task 9 |
| §6.2 SellerPayoutService | Class + lookup + process | Task 10 |
| §6.3 SettlementReversalService | Class + reverse | Task 11 |
| §6.4 ClearSellerEarningsJob | Daily cron with Cache::lock | Task 12 |
| §6.5 Integration points | CodeVerificationService modification | Task 13 |
| §7 Endpoints (15) | 2 driver + 3 office settlement + 3 office payout + 3 seller + 4 admin | Tasks 16, 17, 18, 19, 20 |
| §8 Policies | 3 new policies + shared helper | Task 8 |
| §9 Platform settings | 4 new settings seeded | Task 7 |
| §10 Enums | 3 new/modified enums | Task 4 |
| §10b Money math | bcmath throughout | Tasks 9, 10, 11 |
| §11 Concurrency | lockForUpdate on driver_accounts, earnings, settlement | Tasks 9, 10, 11 |
| §12 Rate limits | 3 limiters | Task 21 |
| §13 Smoke scenarios | 19 scenarios in script | Task 23 |
| §14 Doc updates | CLAUDE.md, spec, CODEX.md | Task 24 |
| §15 Open questions | Settlement reversal notification (deferred); receipt format (UI); reverse_window_hours default | Acknowledged inline |
| §16 Implementation split | Claude/Codex per-task | Task table at top |

**No spec gaps. Every locked decision has a concrete implementation task.**

### Placeholder scan

- No "TBD", "TODO", or "implement later" anywhere.
- No "Add appropriate error handling" — every exception path is named with class + code.
- No "Write tests for the above" — Task 23 contains 19 explicit scenarios with code.
- No "Similar to Task N" — every code block is fully spelled out.

### Type consistency check

- `SettlementService::process` parameter order: `(User $driver, User $staff, OfficeLocation $office, string $cashReceived, string $cashPaid, ?string $notes)` — matches the controller call in Task 18.
- `SellerPayoutService::process` parameter order: `(User $seller, User $staff, OfficeLocation $office, Collection $earningPublicIds, string $totalForSanityCheck, ?string $notes)` — matches Task 19 controller.
- `SettlementReversalService::reverse(Settlement, User, string)` — matches Task 20 controller.
- `SellerEarning::pendingSettlementForDriver(int $driverId)` scope used in Task 9 — defined in Task 5. ✅
- `SellerEarning::pendingClearance()` scope used in Task 12 — defined in Task 5. ✅
- `SellerEarning::available()` scope used in Task 10 — defined in Task 5. ✅
- `User::isAssignedToOffice(int)` used in Tasks 8 (policies) — defined in Task 8 step 1. ✅
- `SettlementErrorCode::SettlementExcessRejected` used in Task 9 — defined in Task 4. ✅
- All `SellerPayoutOrder` references — model defined in Task 6, used in Task 10. ✅
- `Settlement::cashMovement()` — already exists on the existing Settlement model (verified during exploration).

### Order-of-operations check

Tasks are dependency-ordered:
- Migrations (1–3) before models (5–6) before services (9–11) before controllers (16–20).
- Task 8 (policies) before controllers (16–20) since controllers call `$user->can(...)`.
- Task 12 (job) is independent — can ship anywhere after Task 5 (model).
- Task 13 (integration) requires Task 5 (model) — placed correctly.
- Task 23 (smoke) requires everything above — placed last before docs.
- Task 24 (docs) is final.

✅ All dependencies respected.

---

**End of plan.**

Plan complete and saved to `docs/superpowers/plans/2026-05-17-settlement-and-seller-payouts.md`.
