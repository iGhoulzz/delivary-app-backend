# Dashboard Support B (Admin-only) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the four still-unbacked admin dashboard backends — Overview, Finance report, Staff-activity audit timeline, and notification-preference editing — strictly additively.

**Architecture:** New read-only aggregate services + JsonResources + admin controllers; one transactional PATCH. No new tables, no migrations (reporting timezone is a new config file). Revenue/cash are derived from existing snapshots, never written or recalculated. Spec: `docs/superpowers/specs/2026-06-22-dashboard-support-b-design.md`. Process: `docs/WORKFLOW.md`.

**Tech Stack:** Laravel (PHP 8.4), Sanctum, Spatie Permission, PostGIS (clickbar/magellan), Pest. All endpoints `auth:sanctum` + `role:admin` + `staff.password_change_required`.

**Testing conventions:** test snippets below are **illustrative pseudocode**. Real tests use the repo's helpers — `Laravel\Sanctum\Sanctum::actingAs($user)`, `Spatie\Permission\Models\Role::findOrCreate('admin')`, `User::factory()`, and `Tests\Support\TestWorld::create()` for the geographic/platform world. There are no global `makeAdmin()`/`sanctumActingAs()` helpers — either add them to `tests/Pest.php` first or inline the real calls. Exact model relation/column names are verified against the models during TDD (a red test catches a wrong name).

---

## Slice ownership & merge order

| Slice | Owner | Scope | Depends on |
|---|---|---|---|
| **A** | Claude | Finance report + `config/reporting.php` + `ReportingTime` helper | — |
| **C** | Claude | Staff-activity audit timeline (largest slice) | — |
| **B** | Codex | Overview (KPIs + recent order-activity) | A (`ReportingTime`) |
| **D** | Codex | Notification-preference PATCH + policy | — |

**Merge order (per `docs/WORKFLOW.md §5`):** Claude merges **A first** (it owns `config/reporting.php` + `App\Support\ReportingTime`, which B consumes), then **C** (independent). Codex does **B Phase 1** against a local `ReportingTime` stub, then **rebases onto `origin/main`** after A lands and swaps the stub for the real helper (Phase 2); **D** is fully isolated and merges any time. `routes/api.php` is the only shared-touch file — each slice appends its own route lines inside the existing admin group; later slices rebase to absorb earlier additions.

## File-structure map

**New files**
- `config/reporting.php` (A) — `['timezone' => env('REPORTING_TIMEZONE', 'Africa/Tripoli')]`
- `app/Support/ReportingTime.php` (A) — range boundaries + the `AT TIME ZONE` SQL fragment
- `app/Services/Reporting/FinanceReportService.php` (A)
- `app/Http/Resources/Admin/FinanceReportResource.php` (A)
- `app/Http/Requests/Admin/FinanceReportRequest.php` (A)
- `app/Http/Controllers/Api/Admin/FinanceReportController.php` (A)
- `app/Services/Reporting/StaffActivityService.php` (C)
- `app/Http/Resources/Admin/StaffActivityItemResource.php` (C)
- `app/Http/Requests/Admin/StaffActivityRequest.php` (C)
- `app/Http/Controllers/Api/Admin/Staff/StaffActivityController.php` (C)
- `app/Services/Reporting/OverviewMetricsService.php` (B)
- `app/Http/Resources/Admin/OverviewResource.php` (B)
- `app/Http/Controllers/Api/Admin/OverviewController.php` (B)
- `app/Http/Requests/Admin/UpdateNotificationPreferencesRequest.php` (D)
- `app/Http/Resources/Admin/NotificationPreferencesResource.php` (D)
- `app/Services/User/NotificationPreferenceService.php` (D)
- `app/Http/Controllers/Api/Admin/UserNotificationPreferenceController.php` (D)

> **No new User policy.** `AppServiceProvider` already maps `User::class → StaffPolicy` (`Gate::policy(User::class, StaffPolicy::class)`). A second `User` policy would override it and break staff routes. Slice D adds an `updateNotificationPreferences(User $admin, User $target)` **method to the existing `StaffPolicy`** (the mapped User policy) — no new policy class, no provider change.

**Additive touches**
- `routes/api.php` (all) — append route lines inside the existing `admin` group only.
- `app/Policies/StaffPolicy.php` (D) — add `updateNotificationPreferences()` ability (additive method).

**Test files** (one per endpoint, plus service unit tests)
- `tests/Feature/Admin/FinanceReportTest.php` · `tests/Unit/Reporting/ReportingTimeTest.php` (A)
- `tests/Feature/Admin/StaffActivityTest.php` (C)
- `tests/Feature/Admin/OverviewTest.php` (B)
- `tests/Feature/Admin/NotificationPreferencesTest.php` (D)

---

# SLICE A — Finance report + reporting time (Claude)

### Task A1: `config/reporting.php` + `ReportingTime` helper

**Files:**
- Create: `config/reporting.php`, `app/Support/ReportingTime.php`
- Test: `tests/Unit/Reporting/ReportingTimeTest.php`

- [ ] **Step 1 — config file**
```php
<?php
declare(strict_types=1);
return [
    // Operational reporting timezone (app runs in UTC). Day-bucketing for the
    // dashboard uses this so "today" matches the Libya ops day.
    'timezone' => env('REPORTING_TIMEZONE', 'Africa/Tripoli'),
];
```

- [ ] **Step 2 — failing test**
```php
it('computes reporting-tz day boundaries and the sql tz expression', function () {
    config()->set('reporting.timezone', 'Africa/Tripoli');
    $rt = new App\Support\ReportingTime();

    // 23:00 UTC on the 21st is 01:00 Tripoli on the 22nd → "today" = 22nd local
    expect($rt->localDate(Carbon\CarbonImmutable::parse('2026-06-21T23:00:00Z')))->toBe('2026-06-22');
    expect($rt->sqlLocalDate('orders.created_at'))
        ->toContain("AT TIME ZONE 'Africa/Tripoli'");
    [$from, $to] = $rt->rangeBounds('7d');
    expect($to->greaterThan($from))->toBeTrue();
});
```

- [ ] **Step 3 — run, expect FAIL** (`Class "App\Support\ReportingTime" not found`).

- [ ] **Step 4 — implement**
```php
<?php
declare(strict_types=1);
namespace App\Support;

use Carbon\CarbonImmutable;

final class ReportingTime
{
    public function timezone(): string
    {
        return (string) config('reporting.timezone', 'Africa/Tripoli');
    }

    /** Reporting-tz calendar date (YYYY-MM-DD) for a UTC instant. */
    public function localDate(CarbonImmutable $utc): string
    {
        return $utc->setTimezone($this->timezone())->format('Y-m-d');
    }

    /** SQL fragment: a UTC timestamp column → reporting-tz ::date. Column is trusted (caller-supplied literal). */
    public function sqlLocalDate(string $column): string
    {
        $tz = $this->timezone();
        return "(({$column} AT TIME ZONE 'UTC') AT TIME ZONE '{$tz}')::date";
    }

    /**
     * UTC [from, to) bounds for a range token, computed on reporting-tz day edges.
     * 'today' = current local day; '7d'/'30d' = last N local days incl. today; 'all' = [epoch, now].
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function rangeBounds(string $range): array
    {
        $tz = $this->timezone();
        $now = CarbonImmutable::now($tz);
        $to = $now->setTimezone('UTC');
        $from = match ($range) {
            'today' => $now->startOfDay(),
            '7d'    => $now->startOfDay()->subDays(6),
            '30d'   => $now->startOfDay()->subDays(29),
            'all'   => CarbonImmutable::createFromTimestamp(0, $tz),
            default  => $now->startOfDay()->subDays(29),
        };
        return [$from->setTimezone('UTC'), $to];
    }
}
```

- [ ] **Step 5 — run, expect PASS.**
- [ ] **Step 6 — commit** `feat(reporting): add reporting timezone config + ReportingTime helper`.

### Task A2: `FinanceReportService` — revenue/cash aggregates

**Files:**
- Create: `app/Services/Reporting/FinanceReportService.php`
- Test: `tests/Feature/Admin/FinanceReportTest.php` (service-level cases; reuse for the endpoint in A3)

**Key rules (from spec §5.1–5.2):**
- **Time basis: revenue is recognized at delivery, so ALL revenue queries (range filter, `daily_trend`, `recent_orders`) bucket on `orders.delivered_at`, never `orders.created_at`.** An order created last week and delivered today belongs to *today's* period. (`delivered_at` is non-null for `status='delivered'` rows.) The reporting-tz day grouping (`ReportingTime::sqlLocalDate`) is applied to `delivered_at`.
- `accrued`: only orders with `status = 'delivered'`. `commission = Σ commission_amount`, `fee_cut = Σ driver_fee_cut_amount`, `total = commission + fee_cut`. A `standard_delivery` delivered order contributes `fee_cut` (sale-only commission is 0).
- `cash`: only **finalized** rows. `settlement_cash_net = Σ(cash_received_from_driver − cash_paid_to_driver)` over `settlements` **where `status = 'completed'`** (excludes disputed/cancelled), bucketed by `settlements.created_at` (a settlement row is created at completion). `payouts = Σ amount` over `seller_payouts` **where `status = 'paid'`**, bucketed by **`seller_payouts.paid_at`** (when cash actually moved), not `created_at`. `total = settlement_cash_net − payouts`.
- `gap = accrued.total − cash.total`.
- `by_office` (revenue): spatial join pickup→region→office through **active** regions+service_areas; null region/office → `unassigned`. `by_office` cash side / `office_id` filter: settlements/payouts use stored `office_id`.
- All money as decimal **strings**; combine with `bcmath`.

- [ ] **Step 1 — failing test (delivered-only + non-sale fee-cut counted)**
```php
it('accrues delivered orders only and counts non-sale fee cut', function () {
    $world = Tests\Support\TestWorld::create();
    // delivered standard_delivery: commission 0, fee_cut 2.00
    $d = makeDeliveredOrder($world, type: 'standard_delivery', commission: '0.00', feeCut: '2.00');
    // delivered p2p_sale: commission 5.00, fee_cut 1.00
    $s = makeDeliveredOrder($world, type: 'p2p_sale', commission: '5.00', feeCut: '1.00');
    // cancelled p2p_sale with identical snapshot → MUST be excluded
    makeOrder($world, status: 'cancelled_by_user', commission: '5.00', feeCut: '1.00');
    // in-flight assigned with snapshot → MUST be excluded
    makeOrder($world, status: 'assigned', commission: '5.00', feeCut: '1.00');

    $report = app(App\Services\Reporting\FinanceReportService::class)->build('all', null);

    expect($report['accrued']['commission'])->toBe('5.00');
    expect($report['accrued']['fee_cut'])->toBe('3.00');   // 2.00 + 1.00 (non-sale counted)
    expect($report['accrued']['total'])->toBe('8.00');
});
```
> `makeDeliveredOrder`/`makeOrder` are local test factories the executor writes against the real `Order` factory + `TestWorld` (set `status`, `type`, `commission_amount`, `driver_fee_cut_amount`, and a `pickup_location` inside the world's region).

- [ ] **Step 2 — run, expect FAIL** (service missing).

- [ ] **Step 3 — implement the service** (constructor-inject `ReportingTime`; parameterised SQL):
```php
<?php
declare(strict_types=1);
namespace App\Services\Reporting;

use App\Support\ReportingTime;
use Illuminate\Support\Facades\DB;

final class FinanceReportService
{
    public function __construct(private readonly ReportingTime $time) {}

    /** @return array<string,mixed> */
    public function build(string $range, ?int $officeId): array
    {
        [$from, $to] = $this->time->rangeBounds($range);

        $accrued = $this->accrued($from, $to, $officeId);
        $cash = $this->cash($from, $to, $officeId);
        $gap = bcsub($accrued['total'], $cash['total'], 2);

        return [
            'accrued' => $accrued,
            'cash' => $cash,
            'gap' => $gap,
            'by_source' => [
                ['key' => 'commission', 'amount' => $accrued['commission']],
                ['key' => 'fee_cut', 'amount' => $accrued['fee_cut']],
            ],
            'by_merchant' => $this->byMerchant($from, $to, $officeId),
            'by_office' => $this->byOffice($from, $to, $officeId),
            'daily_trend' => $this->dailyTrend($from, $to, $officeId),
            'recent_orders' => $this->recentOrders($from, $to, $officeId),
        ];
    }

    /** Delivered-only accrued revenue, bucketed by delivered_at; office via spatial region join. */
    private function accrued(\Carbon\CarbonImmutable $from, \Carbon\CarbonImmutable $to, ?int $officeId): array
    {
        $row = DB::table('orders')
            ->where('orders.status', 'delivered')
            ->whereBetween('orders.delivered_at', [$from, $to])
            ->when($officeId, fn ($q) => $q->whereIn('orders.id', $this->orderIdsForOffice($officeId)))
            ->selectRaw('COALESCE(SUM(commission_amount),0)::text AS commission, COALESCE(SUM(driver_fee_cut_amount),0)::text AS fee_cut')
            ->first();

        $commission = $this->money($row->commission);
        $feeCut = $this->money($row->fee_cut);
        return [
            'commission' => $commission,
            'fee_cut' => $feeCut,
            'total' => bcadd($commission, $feeCut, 2),
        ];
    }

    /** Subquery: delivered order ids whose pickup falls in a region owned by $officeId (active region+area). */
    private function orderIdsForOffice(int $officeId)
    {
        return DB::table('orders')
            ->join('regions', fn ($j) => $j->whereRaw(
                'ST_Contains(regions.boundary::geometry, orders.pickup_location::geometry)'
            ))
            ->join('service_areas', 'service_areas.id', '=', 'regions.service_area_id')
            ->where('regions.is_active', true)
            ->where('service_areas.is_active', true)
            ->where('regions.office_id', $officeId)
            ->select('orders.id');
    }

    private function cash($from, $to, ?int $officeId): array
    {
        $settle = DB::table('settlements')
            ->where('status', 'completed')                 // exclude disputed/cancelled
            ->whereBetween('created_at', [$from, $to])
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->selectRaw('COALESCE(SUM(cash_received_from_driver - cash_paid_to_driver),0)::text AS net')
            ->value('net');
        $payouts = DB::table('seller_payouts')
            ->where('status', 'paid')                       // only paid-out cash
            ->whereBetween('paid_at', [$from, $to])         // when cash actually moved
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->selectRaw('COALESCE(SUM(amount),0)::text AS total')
            ->value('total');

        $net = $this->money($settle);
        $pay = $this->money($payouts);
        return [
            'settlement_cash_net' => $net,
            'payouts' => $pay,
            'total' => bcsub($net, $pay, 2),
        ];
    }

    // byMerchant($from,$to,$officeId) / byOffice(...) / dailyTrend(...) / recentOrders($from,$to,$officeId):
    // all delivered-only, all bucketed/filtered on orders.delivered_at, decimal-string outputs.
    // byOffice groups on regions.office_id via the spatial join (NULL → 'unassigned');
    // dailyTrend groups on $this->time->sqlLocalDate('orders.delivered_at');
    // recentOrders returns the latest ~12 delivered orders in-range (order by delivered_at desc),
    // with platform_revenue = bcadd(commission_amount, driver_fee_cut_amount).
    // ... (implemented in subsequent steps A2b–A2e, each its own failing test → impl → pass → commit)

    private function money(?string $v): string
    {
        return bcadd($v ?? '0', '0', 2);
    }
}
```

- [ ] **Step 4 — run, expect PASS** (the delivered-only/non-sale assertion).
- [ ] **Step 5 — commit** `feat(finance): FinanceReportService accrued + cash (delivered-only)`.
- [ ] **Step A2-delivery-period — failing test then confirm:** an order with `created_at` *before* the range but `delivered_at` *inside* it **counts**; one `created_at` inside but `delivered_at` after the range (or null) does **not**. Proves revenue buckets on delivery time, not creation time. (Use `range='today'` with explicit `delivered_at` timestamps.)
- [ ] **Steps A2b–A2e — repeat the TDD cycle** for `byMerchant`, `byOffice` (incl. an out-of-region pickup → `unassigned` test), `dailyTrend` (reporting-tz day grouping on `delivered_at`), `recentOrders` (latest ~12 delivered in-range with `platform_revenue = commission+fee_cut`). One failing test → impl → pass → commit each.

### Task A3: Finance endpoint (request + resource + controller + route)

**Files:**
- Create: `FinanceReportRequest.php`, `FinanceReportResource.php`, `FinanceReportController.php`
- Modify: `routes/api.php`
- Test: extend `tests/Feature/Admin/FinanceReportTest.php`

- [ ] **Step 1 — failing endpoint test**
```php
it('returns the finance report for an admin and 403 for non-admin', function () {
    Tests\Support\TestWorld::create();
    sanctumActingAs(makeAdmin());
    $res = getJson('/api/admin/finance/report?range=30d')->assertOk();
    $res->assertJsonStructure([
        'range', 'office',
        'accrued' => ['total', 'commission', 'fee_cut'],
        'cash' => ['total', 'settlement_cash_net', 'payouts'],
        'gap', 'by_source', 'by_merchant', 'by_office', 'daily_trend', 'recent_orders',
    ]);

    sanctumActingAs(User::factory()->create());           // no admin role
    getJson('/api/admin/finance/report')->assertForbidden();
});

it('422s on a bad range', function () {
    sanctumActingAs(makeAdmin());
    getJson('/api/admin/finance/report?range=bogus')->assertStatus(422);
});
```

- [ ] **Step 2 — run, expect FAIL** (route 404).

- [ ] **Step 3 — implement**
  - `FinanceReportRequest`: `range` → `nullable|in:today,7d,30d,all`; `office_id` → `nullable|string|exists:office_locations,public_id` (an unknown office is a clean **422** from validation, per spec — not a 404). Default range `30d`.
  - `FinanceReportController@__invoke`: after validation, resolve the validated `office_id` public_id → internal id (`OfficeLocation::where('public_id', …)->value('id')`); call `FinanceReportService::build($range, $officeId)`; wrap in `FinanceReportResource`.
  - `FinanceReportResource`: pass arrays through; `office` is `null` or `{ public_id, name }`.
  - Route (inside the admin group): `Route::get('finance/report', FinanceReportController::class)->name('admin.finance.report');`

- [ ] **Step 4 — run, expect PASS.**
- [ ] **Step 5 — `vendor/bin/pint` then commit** `feat(admin): GET /admin/finance/report endpoint`.

---

# SLICE C — Staff-activity audit timeline (Claude)

> Largest slice. Built in two passes: C1 append-only event sources, C2 latest-pointer sources, then C3 wires the endpoint + safety tests. If finer review is wanted, C1/C2 can be separate PRs sharing the resource.

### Task C1: `StaffActivityService` — append-only event sources

**Files:**
- Create: `app/Services/Reporting/StaffActivityService.php`
- Test: `tests/Feature/Admin/StaffActivityTest.php`

**Sources (actor column = the viewed staff id), each producing a normalized item `['kind','occurred_at','actor'=>['public_id','name'], ...safeFields]`:**
- `order_action` — `order_status_logs` where `actor_id = :id AND actor_type IN ('admin','office_staff')` → `order:{public_id}`, `from_status`, `to_status`.
- `settlement_processed` — `settlements.processed_by_staff_id` → `settlement:{public_id}`, `driver:{public_id,name}`, `cash_received_from_driver`, `cash_paid_to_driver`.
- `seller_payout_paid` — `seller_payouts.paid_by_staff_id` → `payout:{public_id}`, `seller:{public_id,name}`, `amount`.
- `account_moderation` — `account_moderation_actions.actor_id` → `target:{public_id,name}`, `action`, `reason_code`.
- `driver_account_adjustment` — `driver_account_transactions.created_by_admin_id` → `driver:{public_id,name}`, `bucket`, `amount`, `reason`.
- `driver_strike_issued` — `driver_strikes.issued_by_admin_id` → `driver:{public_id,name}`, `reason`, `fee_amount`.
- `driver_strike_voided` — `driver_strikes.voided_by_admin_id` → `strike:{public_id}`, `driver:{public_id,name}`, `void_reason` (sorted on `voided_at`).
- `office_return_received` — `office_inventory.received_by_staff_id` → `order:{public_id}`.
- `office_order_retrieved` — `office_inventory.retrieved_by_staff_id` → `order:{public_id}`.

- [ ] **Step 1 — failing test (the actor_type fix + coverage)**
```php
it('includes admin/office_staff order actions but not user actions for the same id', function () {
    $world = Tests\Support\TestWorld::create();
    $staff = makeAdmin();
    $order = makeDeliveredOrder($world);

    // staff order action written as admin
    OrderStatusLog::create(['order_id'=>$order->id,'from_status'=>'assigned','to_status'=>'cancelled_by_admin',
        'actor_type'=>'admin','actor_id'=>$staff->id]);
    // a 'user' row with the SAME actor_id must NOT appear
    OrderStatusLog::create(['order_id'=>$order->id,'from_status'=>'created','to_status'=>'awaiting_driver',
        'actor_type'=>'user','actor_id'=>$staff->id]);

    $items = app(App\Services\Reporting\StaffActivityService::class)->timeline($staff, kinds: null);
    $kinds = collect($items)->pluck('kind');
    expect($kinds)->toContain('order_action');
    expect(collect($items)->where('kind','order_action'))->toHaveCount(1); // only the admin row
});
```

- [ ] **Step 2 — run, expect FAIL.**
- [ ] **Step 3 — implement** each source as a private method returning a normalized collection; `timeline()` merges them, sorts by `occurred_at` desc. Use parameterised queries scoped on the actor column. Resolve `{public_id,name}` for referenced users/drivers/merchants by eager-loading (no N+1). Optional `kinds` filter restricts which source methods run.
- [ ] **Step 4 — run, expect PASS.**
- [ ] **Step 5 — commit** `feat(staff-activity): append-only event sources`.
- [ ] **Steps C1b… — TDD a case per remaining append-only source** (settlement, payout, moderation, adjustment, strike issue/void, office received/retrieved): seed a row authored by the staff, assert it surfaces with the right kind + safe fields. One test → confirm → commit per source (or grouped in 2–3 commits).

### Task C2: latest-pointer attribution sources

Add to `StaffActivityService`: `driver_approved` (`driver_profiles.approved_by_admin_id`, `approved_at`), `driver_document_verified` (`driver_documents.verified_by_admin_id`, `document_type`), `merchant_onboarded` (`merchant_profiles.created_by_admin_id`), `merchant_approved` (`merchant_profiles.approved_by_admin_id`, `approved_at`), `setting_updated` (`platform_settings.updated_by_admin_id`, `key` only — **value omitted**), `order_abandoned` (`office_inventory.abandoned_by_admin_id`).

- [ ] **Step 1 — failing test**
```php
it('surfaces latest-pointer attributions and never leaks setting values', function () {
    $staff = makeAdmin();
    PlatformSetting::set('pricing.per_km_rate', '0.50', $staff->id); // sets updated_by_admin_id
    $items = app(App\Services\Reporting\StaffActivityService::class)->timeline($staff, kinds: null);
    $setting = collect($items)->firstWhere('kind', 'setting_updated');
    expect($setting['key'])->toBe('pricing.per_km_rate');
    expect($setting)->not->toHaveKey('value');
});
```
- [ ] **Step 2 — run, expect FAIL → Step 3 implement → Step 4 PASS → Step 5 commit** `feat(staff-activity): latest-pointer attribution sources`.

### Task C3: endpoint (request + resource + controller + route + safety)

**Files:**
- Create: `StaffActivityRequest.php` (`kinds` → `nullable|array`, each `in:<kind list>`; pagination `per_page` → `nullable|int|max:100`), `StaffActivityItemResource.php`, `Staff/StaffActivityController.php`
- Modify: `routes/api.php`
- Test: extend `tests/Feature/Admin/StaffActivityTest.php`

- [ ] **Step 1 — failing test (endpoint + safety + 404 binding)**
```php
it('returns a paginated dashboard-safe timeline for a staff public_id', function () {
    $world = Tests\Support\TestWorld::create();
    $admin = makeAdmin();
    $staff = makeOfficeStaff($world);            // some staff user
    seedAssortedActivityFor($staff, $world);     // settlement, payout, moderation, strike, order action...

    sanctumActingAs($admin);
    $res = getJson("/api/admin/staff/{$staff->public_id}/activity")->assertOk();
    $res->assertJsonStructure(['data' => [['kind','occurred_at','actor'=>['public_id','name']]], 'meta']);

    // safety: no internal ids / codes / phones anywhere in the body
    $body = $res->getContent();
    expect($body)->not->toContain('"id":'.$staff->id);
    expect($body)->not->toMatch('/pickup_code|delivery_code/');
});

it('404s on unknown staff and 403s for non-admin', function () {
    sanctumActingAs(makeAdmin());
    getJson('/api/admin/staff/01JUNKNOWNPUBLICID/activity')->assertNotFound();
    sanctumActingAs(User::factory()->create());
    getJson('/api/admin/staff/whatever/activity')->assertForbidden();
});
```

- [ ] **Step 2 — run, expect FAIL.**
- [ ] **Step 3 — implement.** Controller resolves `{staff}` by `public_id` (route-model-bind `User` on `public_id`, or 404), calls `StaffActivityService::timeline()`, paginates the merged array (`new LengthAwarePaginator(...)` or array-slice), returns `StaffActivityItemResource::collection`. Resource emits only the normalized safe fields + `actor:{public_id,name}`. Route: `Route::get('staff/{staff:public_id}/activity', StaffActivityController::class)->name('admin.staff.activity');`
- [ ] **Step 4 — run, expect PASS.**
- [ ] **Step 5 — `pint` then commit** `feat(admin): GET /admin/staff/{staff}/activity audit timeline`.

---

# SLICE B — Overview (Codex)

> Depends on `App\Support\ReportingTime` (Slice A). **Phase 1:** create a minimal local `ReportingTime` stub (same signatures) so B builds/tests in isolation; mark a note. **Phase 2:** after A merges, rebase onto `origin/main`, delete the stub, use the real helper, re-run.

### Task B1: `OverviewMetricsService` — KPI cards

**Files:**
- Create: `app/Services/Reporting/OverviewMetricsService.php`
- Test: `tests/Feature/Admin/OverviewTest.php`

**KPIs (spec §6.1):**
- `delivered_today`: count of orders **bucketed on `orders.delivered_at`** (the delivery time, not `created_at`) falling in the current reporting-tz day; `delta_pct`/`direction` vs the previous local day; `sparkline` = last 7 local-day counts. Uses `ReportingTime::sqlLocalDate('orders.delivered_at')`.
- `active_orders`: count of **non-terminal** orders (`status NOT IN` the 5 terminal states); `delta`/`sparkline` = null.
- `online_drivers`: count `driver_profiles.activity_status <> 'offline'`; trend null.
- `pending_settlements`: count of `driver_accounts` where `cash_to_deposit <> 0 OR earnings_balance <> 0 OR debt_balance <> 0`; trend null.

- [ ] **Step 1 — failing tests**
```php
it('counts non-terminal orders as active and three-bucket pending settlements', function () {
    $world = Tests\Support\TestWorld::create();
    makeOrder($world, status: 'at_office');          // non-terminal → counts
    makeOrder($world, status: 'delivered');          // terminal → not
    makeOrder($world, status: 'cancelled_by_user');  // terminal → not
    // a driver account with only earnings_balance set
    makeDriverAccount(earnings: '5.00', cash: '0.00', debt: '0.00');

    $m = app(App\Services\Reporting\OverviewMetricsService::class)->build();
    expect(collect($m['stats'])->firstWhere('id','active_orders')['value'])->toBe(1);
    expect(collect($m['stats'])->firstWhere('id','pending_settlements')['value'])->toBe(1);
});

it('counts delivered_today by delivery time in the reporting timezone', function () {
    $world = Tests\Support\TestWorld::create();
    // created yesterday, delivered "today" → must count today
    makeDeliveredOrder($world, createdAt: now()->subDay(), deliveredAt: now());
    // delivered at 23:30 UTC yesterday but that is 01:30 Tripoli today → still counts today
    makeDeliveredOrder($world, deliveredAt: Carbon\CarbonImmutable::parse('today 23:30', 'UTC')->subDay());

    $m = app(App\Services\Reporting\OverviewMetricsService::class)->build();
    $card = collect($m['stats'])->firstWhere('id', 'delivered_today');
    expect($card['value'])->toBeGreaterThanOrEqual(1);
    expect($card['sparkline'])->toHaveCount(7);
    expect($card)->toHaveKeys(['delta_pct', 'direction']);
});
```
- [ ] **Step 2 — run, expect FAIL → Step 3 implement** (use `ReportingTime::sqlLocalDate` for delivered counts; terminal set from `OrderStatus::isTerminal()` values). Each KPI emits `['id','value','money'=>false,'delta_pct'=>?,'direction'=>?,'sparkline'=>?]`; null trend fields for the three gauges. **→ Step 4 PASS → Step 5 commit** `feat(overview): KPI metrics service`.

### Task B2: recent order-activity feed

Add `OverviewMetricsService::recentActivity()` — latest ~15 `order_status_logs` (eager-load `order` + actor), `kind` mapped from `to_status` (`delivered|assigned|failed|pending|driver`), each item `['kind','order_public_id','actor'=>['public_id','name']|null,'to_status','occurred_at']`.

- [ ] **Step 1 failing test** (asserts the 15-item feed maps `to_status` → kind and actor is a public_id) **→ Step 2 FAIL → Step 3 impl → Step 4 PASS → Step 5 commit** `feat(overview): recent order-activity feed`.

### Task B3: endpoint (resource + controller + route)

**Files:** create `OverviewResource.php`, `OverviewController.php`; modify `routes/api.php`; extend `OverviewTest.php`.

- [ ] **Step 1 — failing endpoint test**
```php
it('returns overview for admin, 403 otherwise', function () {
    Tests\Support\TestWorld::create();
    sanctumActingAs(makeAdmin());
    getJson('/api/admin/overview')->assertOk()
        ->assertJsonStructure(['stats'=>[['id','value','money','delta_pct','direction','sparkline']],'activity']);
    sanctumActingAs(User::factory()->create());
    getJson('/api/admin/overview')->assertForbidden();
});
```
- [ ] **Step 2 FAIL → Step 3 impl** (controller calls service, wraps in `OverviewResource`; route `Route::get('overview', OverviewController::class)->name('admin.overview');`) **→ Step 4 PASS → Step 5 `pint` + commit** `feat(admin): GET /admin/overview endpoint`.
- [ ] **Step 6 — Phase 2 rebase:** after Slice A is on `origin/main`, `git rebase origin/main`, delete the `ReportingTime` stub, point at `App\Support\ReportingTime`, re-run `OverviewTest` green.

---

# SLICE D — Notification-preference editing (Codex)

> Fully isolated. The only **write** in this milestone — transactional + `Log::info` soft trail, **no DB audit** (spec §3/§6.4).

### Task D1: policy + request + service + endpoint

**Files:**
- Create: `UpdateNotificationPreferencesRequest.php`, `NotificationPreferencesResource.php`, `NotificationPreferenceService.php`, `UserNotificationPreferenceController.php`
- Modify: `routes/api.php`, `app/Policies/StaffPolicy.php` (add the ability — **do not** create a new User policy; `User::class` is already mapped to `StaffPolicy` in `AppServiceProvider`)
- Test: `tests/Feature/Admin/NotificationPreferencesTest.php`

- [ ] **Step 1 — failing test**
```php
it('lets an admin patch a user notification prefs, partial + 422 on empty', function () {
    $admin = makeAdmin();
    $target = User::factory()->create([
        'push_notifications_enabled'=>true,'sms_notifications_enabled'=>true,'email_notifications_enabled'=>true,
    ]);
    sanctumActingAs($admin);

    $res = patchJson("/api/admin/users/{$target->public_id}/notification-preferences", ['sms'=>false])->assertOk();
    expect($res->json('notification_preferences.sms'))->toBeFalse();
    expect($target->fresh()->push_notifications_enabled)->toBeTrue(); // partial: untouched

    patchJson("/api/admin/users/{$target->public_id}/notification-preferences", [])->assertStatus(422);

    sanctumActingAs(User::factory()->create());
    patchJson("/api/admin/users/{$target->public_id}/notification-preferences", ['sms'=>true])->assertForbidden();
});
```

- [ ] **Step 2 — run, expect FAIL.**
- [ ] **Step 3 — implement.**
  - Request: `push|sms|email` each `sometimes|boolean`; a custom rule (or `after`) requiring at least one present (empty → 422).
  - Service: `update(User $target, array $prefs, User $actor)` inside `DB::transaction`, maps `push→push_notifications_enabled` etc., saves only present keys, then `Log::info('admin.notification_prefs.updated', ['actor'=>$actor->public_id,'target'=>$target->public_id,'changed'=>array_keys($prefs)])`.
  - Policy: add `updateNotificationPreferences(User $admin, User $target): bool => $admin->hasRole('admin')` **to the existing `StaffPolicy`** (already mapped to `User::class`). Controller calls `$this->authorize('updateNotificationPreferences', $target)`. No new policy class, no `AppServiceProvider` change.
  - Controller: route-bind `{user:public_id}`, validate, authorize, call service, return `NotificationPreferencesResource` → `{ notification_preferences: { push, sms, email } }`.
  - Route: `Route::patch('users/{user:public_id}/notification-preferences', UserNotificationPreferenceController::class)->name('admin.users.notification-preferences.update');`
- [ ] **Step 4 — run, expect PASS.**
- [ ] **Step 5 — `pint` + commit** `feat(admin): PATCH user notification preferences`.

---

# Final verification (per owner, before each PR)

Run from the owner's worktree with its isolated DB (`docs/WORKFLOW.md §7`):

- [ ] `vendor/bin/pint` — clean.
- [ ] `DB_DATABASE=<worktree_db> vendor/bin/pest` — full suite green, **zero changes to existing test expectations** (the additive/non-refactor proof).
- [ ] `php artisan route:list --path=api` — the new routes present.
- [ ] `php artisan migrate:status` — unchanged (this milestone adds **no** migrations).
- [ ] Cross-review the other agent's slices (`superpowers:requesting-code-review`); reviewer notes addressed before merge.
- [ ] Merge order: **A → C** (Claude), then Codex rebases and merges **B → D**.
- [ ] Closeout (`docs/WORKFLOW.md §8`): CODEX.md log entries, CLAUDE.md "Current Project State" + endpoint table, SYSTEM_SPECIFICATION §17.19, memory handoff.

## Self-review notes (spec coverage)

- Overview KPIs + activity feed → Slice B ✅; Finance report (accrued delivered-only, cash, gap, breakdowns, trend, recent) → Slice A ✅; Staff-activity (15 sources, actor_type fix, append-only vs latest-pointer, safety) → Slice C ✅; notification-pref editing → Slice D ✅.
- Timezone (Africa/Tripoli via config) → A1 ✅. Office attribution active regions+areas → A2 ✅. Three-bucket pending settlements + non-terminal active_orders → B1 ✅. No migrations / additive → file map ✅.
