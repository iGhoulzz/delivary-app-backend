# Test Infrastructure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. **Educational milestone** — each test task carries a `📚 Lesson` note; when narrating to the user, explain the test type, why that level, and what it can/can't catch.

**Goal:** Convert the three Tinker smoke scripts (`staff`, `moderation`, `orders`) into Pest tests under `tests/Feature/Smoke/`, add per-worktree test-DB isolation with safe defaults, and add GitHub Actions CI that runs migrate + Pint + Pest on a PostGIS service.

**Architecture:** New Pest "smoke" feature tests drive the *real services* end-to-end against the test DB with `RefreshDatabase` (integration-style feature tests). Because a fresh test DB is empty (no seeded region/service-area like the dev DB the scripts assumed), a reusable geographic+platform **test-world helper** seeds settings/roles and builds a valid active service-area + region + office + an in-area pickup/dropoff. The Tinker scripts are kept for manual debugging; CI runs Pest only.

**Tech Stack:** Laravel 13 · PHP 8.3 · PostgreSQL + PostGIS · Pest 4 · Pint · clickbar/laravel-magellan · GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-06-04-test-infrastructure-design.md` (locked).

**Prerequisites:** Docker Postgres+PostGIS running locally. Branch off latest `main`.

---

## 📚 Primer (read once, narrate to the user)

- **Unit test** — one class/function in isolation, no framework boot needed beyond autoload (e.g. `tests/Unit/Enums/...`). Fast, pinpoints logic bugs.
- **Feature test** — boots the app, exercises a slice through the framework (DB, services, sometimes HTTP). Your `tests/Feature/*` live here.
- **Integration/smoke test** — a *feature test that drives a whole multi-service scenario* (create→claim→deliver→settle). That's what we're building in `tests/Feature/Smoke/`. They catch wiring bugs unit tests can't (service A's output breaking service B), at the cost of speed.
- **`RefreshDatabase`** — wipes + re-migrates the test DB so each test starts clean and tests can't leak into each other.
- **Factory** — cheap test-data generator. **Seeder** — fixed baseline rows (settings, roles).

---

## File map

**New:**
- `.env.testing.example` — committed template for the test env
- `tests/Support/TestWorld.php` — geographic + platform world builder (service-area/region/office/settings + pickup/dropoff)
- `tests/Feature/Smoke/ModerationLifecycleTest.php`
- `tests/Feature/Smoke/StaffLifecycleTest.php`
- `tests/Feature/Smoke/OrdersHappyPathTest.php`
- `tests/Feature/Smoke/OrdersExceptionsTest.php`
- `tests/Feature/Smoke/OrdersReturnFlowTest.php`
- `tests/Feature/Smoke/OrdersSettlementTest.php`
- `.github/workflows/ci.yml`

**Modified:**
- `phpunit.xml` (test-DB name overridable; safe default preserved)
- `.gitignore` (ignore `.env.testing`)
- `composer.json` (autoload `Tests\\` namespace for `tests/Support` if not already)
- `docs/SYSTEM_SPECIFICATION.md` (§17.16), `docs/CLAUDE.md` (testing section + status)

**Kept untouched:** `scripts/{orders,staff,moderation,realtime}-e2e.php`.

---

## Task 1: Per-worktree test DB with a safe default

**Files:** `phpunit.xml`, `.env.testing.example` (create), `.gitignore`, `docs/CLAUDE.md`

📚 Lesson: test isolation has two layers — per-test (`RefreshDatabase`) and per-environment (which DB). PHPUnit `<env>` is applied before Laravel reads `.env.testing`, and Laravel's dotenv is immutable (first value wins), so a hardcoded `<env>` would block any `.env.testing` override. We remove the hardcoded DB name from `phpunit.xml` and move the **safe default** into a committed `.env.testing.example` that becomes `.env.testing`.

- [ ] **Step 1: Make `phpunit.xml` defer the DB name to the environment**

In `phpunit.xml`, **delete** the line:
```xml
<env name="DB_DATABASE" value="delivary_app_testing"/>
```
Leave the other `<env>` entries. (Now the DB name comes from `.env.testing`/`.env`.)

- [ ] **Step 2: Create `.env.testing.example` (committed, safe default)**

```
APP_ENV=testing
APP_KEY=
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=delivary_app_testing
DB_USERNAME=postgres
DB_PASSWORD=secret
```

- [ ] **Step 3: Gitignore the real `.env.testing`**

Append to `.gitignore`:
```
.env.testing
```

- [ ] **Step 4: Create your worktree's `.env.testing`**

```bash
cp .env.testing.example .env.testing
php artisan key:generate --env=testing
# per-worktree isolation: each worktree picks its own DB name
#   claude worktree:  DB_DATABASE=delivary_app_testing_claude
#   codex  worktree:  DB_DATABASE=delivary_app_testing_codex
# create the DB once (docker):
docker exec -it delivery-postgis createdb -U postgres delivary_app_testing_claude
```

- [ ] **Step 5: Safety + isolation check (write a guard test)**

Create `tests/Feature/Smoke/TestEnvironmentTest.php`:
```php
<?php

declare(strict_types=1);

it('never runs tests against the dev database', function (): void {
    $db = config('database.connections.pgsql.database');
    expect($db)->not->toBe('delivary_app');           // never the dev DB
    expect($db)->toStartWith('delivary_app_testing');  // always a test DB
});
```

- [ ] **Step 6: Run it**

```bash
vendor\bin\pest tests/Feature/Smoke/TestEnvironmentTest.php
```
Expected: PASS. Then confirm a missing config still defaults safely: temporarily rename `.env.testing`, run again — `phpunit.xml`'s remaining env + `.env` must NOT resolve to `delivary_app`. **If it does, add `DB_DATABASE=delivary_app_testing` back to `phpunit.xml` as a `<env>` AND keep `.env.testing` override working by instead removing it only when `.env.testing` exists** — i.e. verify empirically and pick whichever satisfies both safety constraints, then restore `.env.testing`.

- [ ] **Step 7: Document in `docs/CLAUDE.md`** (add to the Testing area):
```
**Per-worktree test DB:** copy `.env.testing.example` → `.env.testing`, set `DB_DATABASE=delivary_app_testing_<worktree>`, and `createdb` it once. CI uses the committed default `delivary_app_testing`. Tests must never target the dev DB `delivary_app`.
```

- [ ] **Step 8: Commit**
```bash
git add phpunit.xml .env.testing.example .gitignore tests/Feature/Smoke/TestEnvironmentTest.php docs/CLAUDE.md
git commit -m "test(infra): per-worktree test DB with safe default + guard test"
```

---

## Task 2: `TestWorld` — geographic + platform test-world builder

The smokes assumed a **seeded** region/service-area (dev DB). A fresh test DB has none, and there is **no Region/ServiceArea factory or seeder**. This helper builds a valid active world so `QuoteService`/`CreationService` work.

**Files:** Create `tests/Support/TestWorld.php`; Test `tests/Feature/Smoke/TestWorldSanityTest.php`

📚 Lesson: integration tests need realistic fixtures. Spatial data (PostGIS polygons) can't come from a plain factory easily, so we build a small reusable world helper — the integration-test equivalent of a factory.

- [ ] **Step 1: Write the sanity test first**

Create `tests/Feature/Smoke/TestWorldSanityTest.php`:
```php
<?php

declare(strict_types=1);

use App\Enums\ItemSize;
use App\Enums\OrderType;
use App\Services\Order\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

it('builds an active region a quote can be produced against', function (): void {
    $world = TestWorld::create();

    $quote = app(QuoteService::class)->quote(
        OrderType::StandardDelivery,
        $world['pickup']['lat'], $world['pickup']['lng'],
        $world['dropoff']['lat'], $world['dropoff']['lng'],
        ItemSize::Small, '0.00', 'sender',
    );

    expect($quote)->toHaveKey('quote_token');
});
```

- [ ] **Step 2: Run it — expect failure** (`Class "Tests\Support\TestWorld" not found`)

```bash
vendor\bin\pest tests/Feature/Smoke/TestWorldSanityTest.php
```

- [ ] **Step 3: Ensure the `Tests\\` namespace autoloads `tests/Support`**

Check `composer.json` `autoload-dev`. It should map `"Tests\\": "tests/"`. If missing, add it and run `composer dump-autoload`.

- [ ] **Step 4: Create `tests/Support/TestWorld.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\OfficeLocation;
use App\Models\Region;
use App\Models\ServiceArea;
use Clickbar\Magellan\Data\Geometries\LineString;
use Clickbar\Magellan\Data\Geometries\Point;
use Clickbar\Magellan\Data\Geometries\Polygon;
use Database\Seeders\OrderLifecyclePlatformSettingsSeeder;
use Database\Seeders\PlatformSettingsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Artisan;

final class TestWorld
{
    /**
     * Seed roles + platform settings and build one active service-area → region
     * → office around Tripoli. Returns the region/office plus an in-area
     * pickup/dropoff pair for QuoteService/CreationService.
     *
     * @return array{region: Region, office: OfficeLocation, pickup: array{lat: float, lng: float}, dropoff: array{lat: float, lng: float}}
     */
    public static function create(): array
    {
        Artisan::call('db:seed', ['--class' => RolesSeeder::class, '--no-interaction' => true]);
        Artisan::call('db:seed', ['--class' => PlatformSettingsSeeder::class, '--no-interaction' => true]);
        Artisan::call('db:seed', ['--class' => OrderLifecyclePlatformSettingsSeeder::class, '--no-interaction' => true]);

        // Closed square ring around Tripoli centre (lat ~32.80–32.95, lng ~13.10–13.30).
        $ring = LineString::make([
            Point::makeGeodetic(32.80, 13.10),
            Point::makeGeodetic(32.80, 13.30),
            Point::makeGeodetic(32.95, 13.30),
            Point::makeGeodetic(32.95, 13.10),
            Point::makeGeodetic(32.80, 13.10), // close the ring
        ]);
        $boundary = Polygon::make([$ring]);

        $serviceArea = ServiceArea::create([
            'name' => 'Test Service Area',
            'boundary' => $boundary,
            'is_active' => true,
        ]);

        $region = Region::create([
            'service_area_id' => $serviceArea->id,
            'name' => 'Test Region',
            'boundary' => $boundary,
            'is_active' => true,
            'base_fee' => '10.00',
        ]);

        $office = OfficeLocation::create([
            'region_id' => $region->id,
            'name' => 'Test Office',
            'address' => 'Test office address',
            'location' => Point::makeGeodetic(32.8872, 13.1913),
            'is_active' => true,
        ]);

        $region->forceFill(['office_id' => $office->id])->save();

        return [
            'region' => $region->fresh(),
            'office' => $office,
            'pickup' => ['lat' => 32.8872, 'lng' => 13.1913],
            'dropoff' => ['lat' => 32.8882, 'lng' => 13.1923],
        ];
    }
}
```

> VERIFY against the codebase while implementing: (a) `Polygon::make` / `LineString::make` signatures in `clickbar/laravel-magellan` (adjust if the API differs — fallback: insert `boundary` via `DB::statement` with `ST_GeogFromText('POLYGON((13.10 32.80, ...))', 4326)`, noting WKT is `lng lat` order); (b) the exact non-nullable pricing columns on `regions` (set any the model requires beyond `base_fee`); (c) that `RegionService`/`QuoteService` resolve the region by `ST_Contains/ST_Intersects` on the pickup point — the box above contains the pickup.

- [ ] **Step 5: Run the sanity test — expect pass** (iterate on the VERIFY notes until green)

```bash
vendor\bin\pest tests/Feature/Smoke/TestWorldSanityTest.php
```

- [ ] **Step 6: Pint + commit**
```bash
vendor\bin\pint tests/Support tests/Feature/Smoke
git add tests/Support/TestWorld.php tests/Feature/Smoke/TestWorldSanityTest.php composer.json
git commit -m "test(infra): TestWorld geographic+platform fixture builder"
```

---

## Task 3: `ModerationLifecycleTest` (warm-up — convert `moderation-e2e.php`)

📚 Lesson: this is the simplest smoke (no geography). Notice how `RefreshDatabase` replaces the script's `DB::beginTransaction()/rollBack()` harness, and each scenario is its own isolated `it()` — if one fails, the others still run and you get a precise red.

**Files:** Create `tests/Feature/Smoke/ModerationLifecycleTest.php` (source: `scripts/moderation-e2e.php`)

- [ ] **Step 1: Write the test (all 6 scenarios as `it()` blocks)**

```php
<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\ModerationReason;
use App\Enums\VehicleType;
use App\Models\AccountModerationAction;
use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Moderation\AccountModerationService;
use App\Services\Staff\StaffService;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('driver', 'web');
    Role::findOrCreate('office_staff', 'web');
    Role::findOrCreate('user', 'web');

    $this->service = app(AccountModerationService::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    // keep a second admin so last-active-admin guard never blocks test targets
    User::factory()->create()->assignRole('admin');
});

it('suspends a customer so they can no longer log in', function (): void {
    $customer = User::factory()->create(['account_status' => AccountStatus::Active->value]);

    $this->service->suspend($customer, $this->admin, ModerationReason::Abuse, 'spamming');

    expect($customer->fresh()->account_status)->toBe(AccountStatus::Suspended);
    expect($customer->fresh()->account_status->canLogin())->toBeFalse();
});

it('bans an online driver — forces offline, leaves DriverStatus untouched', function (): void {
    $driver = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $driver->assignRole('driver');
    DriverProfile::factory()->create([
        'user_id' => $driver->id,
        'status' => DriverStatus::Active->value,
        'activity_status' => DriverActivityStatus::Online->value,
        'vehicle_type' => VehicleType::Car->value,
    ]);

    $this->service->ban($driver, $this->admin, ModerationReason::Fraud, 'fake orders');

    $profile = DriverProfile::query()->where('user_id', $driver->id)->first();
    expect($driver->fresh()->account_status)->toBe(AccountStatus::Banned);
    expect($profile->activity_status)->toBe(DriverActivityStatus::Offline);
    expect($profile->status)->toBe(DriverStatus::Active);
});

it('reinstates a debtor into suspended_unpaid_fees', function (): void {
    $debtor = User::factory()->create(['account_status' => AccountStatus::Suspended->value]);
    $debtor->assignRole('driver');
    DriverAccount::factory()->create(['driver_id' => $debtor->id, 'debt_balance' => '25.00']);

    $this->service->reinstate($debtor, $this->admin, ModerationReason::Other, 'partial appeal');

    expect($debtor->fresh()->account_status)->toBe(AccountStatus::SuspendedUnpaidFees);
});

it('reinstates a clean user to active', function (): void {
    $clean = $this->makeSuspendedUser();

    $this->service->reinstate($clean, $this->admin, ModerationReason::Other, 'cleared');

    expect($clean->fresh()->account_status)->toBe(AccountStatus::Active);
});

it('records an audit row with correct status snapshots', function (): void {
    $customer = User::factory()->create(['account_status' => AccountStatus::Active->value]);

    $this->service->suspend($customer, $this->admin, ModerationReason::Other, 'x');

    $row = AccountModerationAction::query()->where('user_id', $customer->id)->latest('id')->first();
    expect($row->from_status)->toBe(AccountStatus::Active);
    expect($row->to_status)->toBe(AccountStatus::Suspended);
});

it('audits a staff suspension routed through StaffService', function (): void {
    $staff = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $staff->assignRole('office_staff');

    app(StaffService::class)->suspend($staff, $this->admin);

    $this->assertDatabaseHas('account_moderation_actions', [
        'user_id' => $staff->id,
        'action' => 'suspend',
    ]);
});

// Local helper to keep scenarios readable.
function () {}; // (placeholder removed at implementation — see note)
```

> Replace the trailing placeholder: define `$this->makeSuspendedUser()` inline instead — e.g. `$u = User::factory()->create(['account_status' => AccountStatus::Suspended->value]); return $u;` as a closure in `beforeEach` (`$this->makeSuspendedUser = fn () => ...`) or just inline `User::factory()->create(['account_status' => 'suspended'])` in the one test that needs it. Do NOT ship the placeholder line.

- [ ] **Step 2: Run — expect pass (6 tests)**
```bash
vendor\bin\pest tests/Feature/Smoke/ModerationLifecycleTest.php
```

- [ ] **Step 3: Pint + commit**
```bash
vendor\bin\pint tests/Feature/Smoke/ModerationLifecycleTest.php
git add tests/Feature/Smoke/ModerationLifecycleTest.php
git commit -m "test(smoke): convert moderation-e2e to Pest (ModerationLifecycleTest)"
```

---

## Task 4: `StaffLifecycleTest` (convert `staff-e2e.php`)

📚 Lesson: staff scenarios need a region (offices live in a region) but not the full geographic quote machinery — so we use `TestWorld::create()` for the region/office, then drive `StaffService`. Note the *unhappy-path* scenarios (self-suspend, last-admin) use `->throws()`.

**Files:** Create `tests/Feature/Smoke/StaffLifecycleTest.php` (source: `scripts/staff-e2e.php`)

- [ ] **Step 1: Write the test (6 scenarios)**

Convert each `scripts/staff-e2e.php` scenario 1:1. Use `TestWorld::create()` for region+offices (create a second office via `OfficeLocation::create` in the region as the script does). Mapping:
- S1 create admin + forced password change → assert temp password returned, `must_change_password` true then false after `TempPasswordChangeService::change`.
- S2 create office_staff with 2 offices → `activeOfficeAssignments()->count() === 2`.
- S3 reset another admin's password → `must_change_password` true, tokens revoked.
- S4 suspend office_staff → `account_status === Suspended`, assignments preserved (count 2).
- S5 reinstate then deactivate → reinstate keeps 2; deactivate → Suspended + 0 active assignments.
- S6 guards → self-suspend `->throws()` with "own account"; last-admin → suspend all other admins then suspend root `->throws()` with "last active admin".

Skeleton (fill all 6 from the script, full assertions):
```php
<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Exceptions\Staff\StaffDomainException;
use App\Models\OfficeLocation;
use App\Models\User;
use App\Services\Staff\StaffService;
use App\Services\Staff\TempPasswordChangeService;
use App\Support\DTO\CreateStaffInput;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestWorld;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $world = TestWorld::create();
    $this->region = $world['region'];
    $this->office1 = $world['office'];
    $this->office2 = OfficeLocation::create([
        'region_id' => $this->region->id, 'name' => 'Office 2', 'address' => 'a',
        'location' => Point::makeGeodetic(32.9, 13.2), 'is_active' => true,
    ]);
    $this->staffService = app(StaffService::class);
    $this->rootAdmin = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $this->rootAdmin->assignRole('admin');
    $this->coAdmin = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $this->coAdmin->assignRole('admin');
});

it('creates an admin who must change a temporary password on first use', function (): void {
    $created = $this->staffService->create(new CreateStaffInput(
        phoneNumber: '+218910'.random_int(100000, 999999),
        firstName: 'New', lastName: 'Admin', email: null, role: 'admin',
    ), $this->rootAdmin);

    expect($created['temporary_password'])->toBeString();
    expect($created['user']->must_change_password)->toBeTrue();

    $changed = app(TempPasswordChangeService::class)
        ->change($created['user'], $created['temporary_password'], 'newPass99X');
    expect($changed['user']->must_change_password)->toBeFalse();
    expect($changed['token'])->toBeString();
});

// ... S2–S6 converted 1:1 from scripts/staff-e2e.php (full assertions). For S6:
it('rejects self-suspension', function (): void {
    expect(fn () => $this->staffService->suspend($this->rootAdmin, $this->rootAdmin))
        ->toThrow(StaffDomainException::class);
});
```

- [ ] **Step 2: Run — expect pass; Step 3: Pint + commit**
```bash
vendor\bin\pest tests/Feature/Smoke/StaffLifecycleTest.php
vendor\bin\pint tests/Feature/Smoke/StaffLifecycleTest.php
git add tests/Feature/Smoke/StaffLifecycleTest.php
git commit -m "test(smoke): convert staff-e2e to Pest (StaffLifecycleTest)"
```

---

## Tasks 5–8: Orders smoke → Pest, in 4 batches (source: `scripts/orders-e2e.php`)

**Shared conversion recipe (applies to every orders batch):**
1. `uses(RefreshDatabase::class)`.
2. `beforeEach`: `$w = TestWorld::create();` keep `$this->region/office/pickup/dropoff`; create the sender/driver/admin/officeStaff + driver profile/account the way `orders-e2e.php`'s preamble does (lines ~154–214), but via `User::factory()` + `assignRole`. Reuse `makeOnlineDriverAt()` from `tests/Pest.php` where the script uses an online driver.
3. Each `echo "Scenario N"` block → one `it('...')`; each `$assert(cond, 'msg')` → `expect(...)->...`; each "should throw" try/catch → `expect(fn () => ...)->toThrow(OrderDomainException::class)` and assert `->errorCode`.
4. Build orders through the **real services** exactly as the script: `QuoteService` → `CreationService` → `ClaimService`/`AdminAssignmentService` → `CodeVerificationService` → `FailedDeliveryService` → `SettlementService`/`SellerPayoutService`/`SettlementReversalService`, plus jobs (`EscalationService`, `AbandonStaleOrdersJob`, `ClearSellerEarningsJob`, `AutoOfflineService`).
5. The script is the **authoritative source** for each scenario's exact steps + assertions — port them faithfully (≥ the script's assertions).

📚 Lesson (narrate): these are the highest-value, slowest tests — they prove services compose correctly. We split them so each file/PR stays reviewable.

### Task 5 — `OrdersHappyPathTest` (Batch A)
Scenario 1 of `orders-e2e.php`: create → go online → broadcast contains order → claim → confirm-pickup (auto-chain) → update location → arrived-dropoff → confirm-delivery; assert statuses at each step + `cash_to_deposit`/`earnings_balance` credit + driver back online.
- [ ] Write `tests/Feature/Smoke/OrdersHappyPathTest.php` per recipe; one `it()` per logical step or one `it()` for the full happy path with stepwise `expect`s (prefer a few focused `it()`s: "claim moves to en_route_pickup", "delivery credits driver buckets", etc.).
- [ ] Run → pass; Pint; commit `test(smoke): orders happy-path batch A`.

### Task 6 — `OrdersExceptionsTest` (Batch B)
Scenarios 2–8: escalation tiers + no-driver timeout; retry + free cancel from no_driver; admin assign/unassign; pre-pickup cancellation fees; admin pre-pickup cancel frees driver; driver-fault unassign (strike + ledger debit); after-pickup sender cancel rejected (`OrderErrorCode::OrderNotCancellableFromState`).
- [ ] Write per recipe; the rejection scenarios use `->toThrow(OrderDomainException::class)` + assert `->errorCode`.
- [ ] Run → pass; Pint; commit `test(smoke): orders exceptions/cancellation batch B`.

### Task 7 — `OrdersReturnFlowTest` (Batch C)
Scenarios 9–17: driver-fault failure → returning_to_office → receive-return (no driver earnings); receiver-fault retrieval (delivery + storage); sender-paid retrieval (storage only); admin redirect-return audit; admin waiver zero-cash retrieval; admin mark-failed from picked_up; abandonment cron snapshots storage; excess-cash retrieval rejected; auto-offline stale driver.
- [ ] Write per recipe (use `OfficeInventory::where(...)->update(['received_at' => now()->subDays(7)])` exactly as the script to age storage; dispatch `AbandonStaleOrdersJob`/`AutoOfflineService` as the script does).
- [ ] Run → pass; Pint; commit `test(smoke): orders return/failed-delivery batch C`.

### Task 8 — `OrdersSettlementTest` (Batch D)
Scenarios 18–32: settlement happy/empty/excess/shortage/zero-net; earning flips pending_settlement→pending_clearance; clearance cron (eligible/ineligible); payout happy/partial/mismatch/below-min; reversal happy/blocked-past-clearance/debt-restore regression. Reuse the script's `$freshDriver`/`$freshEarning` helpers — port them as private closures or a `tests/Support` helper.
- [ ] Write per recipe; settlement/payout rejections assert the `Settlement*Exception::errorCode()` values from the script.
- [ ] Run → pass; Pint; commit `test(smoke): orders settlement/payout batch D`.

After Task 8: `vendor\bin\pest tests/Feature/Smoke` should show **≥ 44 scenario tests** green (6 + 6 + 32 + sanity/env).

---

## Task 9: GitHub Actions CI

**Files:** Create `.github/workflows/ci.yml`

📚 Lesson: CI runs your exact suite on a clean machine — it catches "works on my machine" gaps (a forgotten migration, a missing PHP extension, the PostGIS dependency).

- [ ] **Step 1: Create `.github/workflows/ci.yml`**

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      postgres:
        image: postgis/postgis:16-3.4
        env:
          POSTGRES_DB: delivary_app_testing
          POSTGRES_USER: postgres
          POSTGRES_PASSWORD: secret
        ports:
          - 5432:5432
        options: >-
          --health-cmd "pg_isready -U postgres"
          --health-interval 10s --health-timeout 5s --health-retries 5

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_pgsql, bcmath, gd, exif, redis
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Prepare env
        run: |
          cp .env.example .env
          php artisan key:generate

      - name: Migrate (test DB)
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_DATABASE: delivary_app_testing
          DB_USERNAME: postgres
          DB_PASSWORD: secret
        run: php artisan migrate --force

      - name: Pint (style)
        run: vendor/bin/pint --test

      - name: Pest
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_DATABASE: delivary_app_testing
          DB_USERNAME: postgres
          DB_PASSWORD: secret
        run: vendor/bin/pest
```

> VERIFY while implementing: the PHP extensions list matches what `composer.json` requires (check `ext-*` in require). Magellan needs PostGIS — `postgis/postgis` image provides it. Pest reads `phpunit.xml` env; the explicit `DB_*` step env ensures the test DB even without a committed `.env.testing` (which is gitignored).

- [ ] **Step 2: Commit + push; confirm the workflow goes green on the PR**
```bash
git add .github/workflows/ci.yml
git commit -m "ci: run migrate + Pint + Pest on PostGIS service"
```
Open the PR; watch the Actions run. Iterate (extensions, migrate, PostGIS) until green. **CI green is this task's pass condition.**

---

## Task 10: Docs

**Files:** `docs/SYSTEM_SPECIFICATION.md` (§17.16), `docs/CLAUDE.md`

- [ ] **Step 1:** Add `### 17.16 Test Infrastructure milestone (2026-06-04) ✅` summarizing: smokes converted to `tests/Feature/Smoke/`, `TestWorld` fixture, per-worktree test DB, CI (PostGIS + migrate + Pint + Pest), scripts retained, realtime smoke deferred. Note final suite count.
- [ ] **Step 2:** Update `docs/CLAUDE.md` "Current Project State" (mark milestone ✅; Next Steps → Merchant deliveries #1) and the Testing section (per-worktree DB instructions already added in Task 1; add "smoke scenarios live in `tests/Feature/Smoke/`, also kept as `scripts/*-e2e.php` for manual runs").
- [ ] **Step 3:** Commit `docs(test-infra): record milestone (§17.16 + Current Project State)`.

---

## Final verification

- [ ] `vendor\bin\pest` — full suite green (163 existing + ~44 smoke scenarios).
- [ ] `vendor\bin\pint --test` — clean.
- [ ] CI green on the PR.
- [ ] `php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"` still passes (scripts retained).
- [ ] Two worktrees with different `.env.testing` DB names run Pest without interfering.

---

## Self-Review

- **Spec coverage:** §1 convert-all-3 → Tasks 3–8; §2 per-worktree DB → Task 1; §3 conversion recipe → Tasks 3–8; §4 batching → Tasks 5–8; §5 CI → Task 9; §6 realtime out-of-scope → not touched (noted); §7 file map → all; §8 done criteria → Final verification. The one spec gap surfaced during planning (no Region/ServiceArea fixtures) is covered by the added **Task 2 (`TestWorld`)**. ✅
- **Placeholders:** the moderation test has an explicit "remove this placeholder" note (the trailing closure) — flagged, not shipped. Orders batches reference the authoritative script + a full recipe rather than reproducing 600 lines verbatim (faithful-conversion task, source in-repo).
- **Type consistency:** `TestWorld::create()` returns `{region, office, pickup, dropoff}` used identically across Tasks 2/4/5–8. Service names (`AccountModerationService`, `StaffService`, `QuoteService`, `CreationService`, …) match the codebase. Error-code assertions reference real enums (`OrderErrorCode`, `StaffErrorCode`, settlement exceptions).
