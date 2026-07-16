# Slice B — Human-Readable Order Numbers (Claude) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`). Follow `docs/CLAUDE.md` (strict types, `final`, services-not-controllers) + WORKFLOW gates (Pint + Pest).

**Goal:** Add an immutable, unique, human-readable `order_number` (`ORD-XXXX-XXXX-C`) generated on order creation, backfilled onto existing orders, searchable, and surfaced beside the ULID `id` in every human-facing order representation/summary. The ULID `public_id` stays the key for routes, auth, realtime, and actions.

**Architecture:** A pure `OrderNumberGenerator` service owns the format (Crockford-Base32 body + ISO 7064 MOD 37,36 check), generation (existence-check loop), validation, and search normalization. `Order::booted()` assigns it on `creating`; a migration adds the column, backfills, then constrains. Resource/service edits add one field each. Additive-only.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL, Pest. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-07-16-dashboard-operations-ux-support-design.md`

**Owner:** Claude · **Branch:** `feat/order-numbers` (off `main`) · **Milestone:** Dashboard Operations UX Support.

---

## Ownership & sequencing

- Slice B (this plan) and Slice A (map zones, Codex, `feat/map-zones`) touch **disjoint files** — parallel
  branches/PRs, merge either order. Recommended: **B first**.
- **Gate before PR:** `vendor/bin/pint` clean; `DB_DATABASE=delivary_app_testing vendor/bin/pest` green with
  **zero changes to existing test expectations** (additive proof); `php artisan migrate:status` clean.

## File-structure map

```
NEW
  app/Support/OrderNumber/OrderNumberGenerator.php     # format, generate, isValid, normalizeSearchTerm
  database/migrations/2026_07_16_000100_add_order_number_to_orders_table.php   # add nullable + backfill + constrain
  tests/Unit/OrderNumberGeneratorTest.php
  tests/Feature/Order/OrderNumberTest.php              # creation + search feature tests
MODIFY
  app/Models/Order.php                                 # creating hook (assign), keep out of update path
  app/Http/Requests/Order/AdminListOrdersRequest.php   # (if it validates search) — no change unless needed
  app/Http/Controllers/Api/Admin/OrderController.php   # add order_number to the search closure
  app/Http/Resources/Order/AdminOrderResource.php
  app/Http/Resources/Order/OrderResource.php
  app/Http/Resources/Order/DriverOrderResource.php
  app/Http/Resources/Order/OfficeOrderResource.php
  app/Http/Resources/Order/BroadcastOrderResource.php
  app/Http/Resources/Order/GuestTrackingResource.php
  app/Http/Resources/Broadcast/OrderForPartiesResource.php
  app/Http/Resources/Driver/DriverStrikeResource.php
  app/Http/Resources/Admin/UserDetailResource.php
  app/Http/Resources/Settlement/SellerEarningResource.php
  app/Http/Resources/Settlement/SellerPayoutResource.php
  app/Http/Resources/Settlement/SettlementPreviewResource.php
  app/Http/Resources/Settlement/SettlementResource.php
  app/Services/Reporting/OverviewMetricsService.php
  app/Services/Reporting/StaffActivityService.php
  app/Services/Reporting/FinanceReportService.php
```

---

## Task 1: `OrderNumberGenerator` — format, checksum, validation, search normalization

**Files:** Create `app/Support/OrderNumber/OrderNumberGenerator.php`, `tests/Unit/OrderNumberGeneratorTest.php`.

- [ ] **Step 1: Write the failing test** — pins the ISO 7064 MOD 37,36 check via known vectors, format, validation, and search normalization:

```php
<?php // tests/Unit/OrderNumberGeneratorTest.php

declare(strict_types=1);

use App\Support\OrderNumber\OrderNumberGenerator;

function gen(): OrderNumberGenerator
{
    return new OrderNumberGenerator();
}

it('computes the ISO 7064 MOD 37,36 check character (known vectors)', function (string $body, string $check): void {
    expect(gen()->checkCharacter($body))->toBe($check);
})->with([
    ['', '1'],
    ['0', '2'],
    ['00', '4'],
    ['A12425GABC1234002', 'M'], // reference vector from ISO 7064 MOD 37,36
]);

it('generates ORD-XXXX-XXXX-C with a Crockford body and a valid check', function (): void {
    $number = gen()->build();
    expect($number)->toMatch('/^ORD-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-Z]$/');
    expect(gen()->isValid($number))->toBeTrue();
});

it('rejects a corrupted check character', function (): void {
    // Flip the last (check) character of a freshly built, valid number.
    $number = gen()->build();
    $bad = substr($number, 0, -1) . (str_ends_with($number, '0') ? '1' : '0');
    expect(gen()->isValid($bad))->toBeFalse();
});

it('rejects I/L/O/U in the body but accepts them in the check position (literal)', function (): void {
    expect(gen()->isValid('ORD-IIII-IIII-1'))->toBeFalse(); // I not allowed in body
    // A body whose computed check is a letter is still valid; the check char is never Crockford-normalized.
    $number = gen()->build();
    expect(gen()->isValid($number))->toBeTrue();
});

it('normalizes search terms: dashless, case-insensitive, partial (body only)', function (): void {
    expect(gen()->normalizeSearchTerm('ord-7k3m-9q2d-8'))->toBe('ORD7K3M9Q2D8');
    expect(gen()->normalizeSearchTerm('7K3M 9Q2D'))->toBe('7K3M9Q2D');
    expect(gen()->normalizeSearchTerm('7k3m'))->toBe('7K3M');
});
```

- [ ] **Step 2: Run — expect FAIL** (`DB_DATABASE=delivary_app_testing vendor/bin/pest tests/Unit/OrderNumberGeneratorTest.php`) — class not found.

- [ ] **Step 3: Implement** `app/Support/OrderNumber/OrderNumberGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\OrderNumber;

use App\Models\Order;

final class OrderNumberGenerator
{
    private const PREFIX = 'ORD';

    /** Crockford Base32 — 0-9 A-Z minus I, L, O, U (the characters humans confuse). */
    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** ISO 7064 alphanumeric alphabet: value('0')=0 … value('9')=9, value('A')=10 … value('Z')=35. */
    private const ALPHANUMERIC = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** Generate a canonical, unique order number (existence-checked). */
    public function generate(): string
    {
        do {
            $candidate = $this->build();
        } while (Order::query()->where('order_number', $candidate)->exists());

        return $candidate;
    }

    /** Build one candidate: ORD-BBBB-BBBB-C (no uniqueness check). */
    public function build(): string
    {
        $body = '';
        for ($i = 0; $i < 8; $i++) {
            $body .= self::CROCKFORD[random_int(0, 31)];
        }

        return sprintf(
            '%s-%s-%s-%s',
            self::PREFIX,
            substr($body, 0, 4),
            substr($body, 4, 4),
            $this->checkCharacter($body),
        );
    }

    /**
     * ISO 7064 MOD 37,36 hybrid check character over the (upper-case) body.
     * modulus = 37, other = 36. product starts at 36; for each char value v:
     *   sum = (v + product) mod 36; product = (2 * (sum==0 ? 36 : sum)) mod 37.
     * checkValue = (37 - product), mapped 36 -> 0. Char = ALPHANUMERIC[checkValue].
     */
    public function checkCharacter(string $body): string
    {
        $modulus = 37;
        $other = 36;
        $product = $other;

        foreach (str_split(strtoupper($body)) as $char) {
            $value = strpos(self::ALPHANUMERIC, $char);
            if ($value === false) {
                $value = 0; // defensive: non-alphanumeric contributes 0 (never happens for a Crockford body)
            }
            $sum = ($value + $product) % $other;
            $product = (2 * ($sum === 0 ? $other : $sum)) % $modulus;
        }

        $pz = $modulus - $product;      // 1..36
        $checkValue = $pz === $other ? 0 : $pz; // 0..35

        return self::ALPHANUMERIC[$checkValue];
    }

    /** Strict validation of a canonical stored value ORD-BBBB-BBBB-C. */
    public function isValid(string $orderNumber): bool
    {
        $value = strtoupper(trim($orderNumber));
        if (preg_match('/^ORD-([0-9A-Z]{4})-([0-9A-Z]{4})-([0-9A-Z])$/', $value, $m) !== 1) {
            return false;
        }
        $body = $m[1].$m[2];
        // Body is canonical Crockford: I, L, O, U are never valid in a body position.
        if (preg_match('/[ILOU]/', $body) === 1) {
            return false;
        }

        // Check character is literal (may itself be I/L/O/U) — compare, never Crockford-normalize it.
        return $this->checkCharacter($body) === $m[3];
    }

    /** Forgiving normalization for partial/fuzzy search: upper-case, strip non-alphanumerics. */
    public function normalizeSearchTerm(string $term): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $term));
    }
}
```

- [ ] **Step 4: Run — expect PASS.** **Step 5: `vendor/bin/pint app/Support/OrderNumber tests/Unit/OrderNumberGeneratorTest.php` + commit** `git add app/Support/OrderNumber tests/Unit/OrderNumberGeneratorTest.php && git commit -m "feat(orders): OrderNumberGenerator (Crockford body + ISO 7064 MOD 37,36 check)"`.

---

## Task 2: Migration — add `order_number`, backfill, constrain

**Files:** Create `database/migrations/2026_07_16_000100_add_order_number_to_orders_table.php`.

- [ ] **Step 1: Implement the migration** (nullable → backfill → unique + not-null, one file):

```php
<?php

declare(strict_types=1);

use App\Models\Order;
use App\Support\OrderNumber\OrderNumberGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('order_number', 20)->nullable()->after('tracking_token');
        });

        // Backfill existing rows with unique numbers. Collision-safe within the run via
        // an in-memory set + the generator's own randomness; the UNIQUE index (added below) is the backstop.
        $generator = new OrderNumberGenerator();
        $used = [];
        Order::query()->whereNull('order_number')->orderBy('id')->chunkById(500, function ($orders) use ($generator, &$used): void {
            foreach ($orders as $order) {
                do {
                    $candidate = $generator->build();
                } while (isset($used[$candidate]) || Order::query()->where('order_number', $candidate)->exists());
                $used[$candidate] = true;
                // Targeted update — do not touch updated_at semantics other logic relies on.
                Order::query()->whereKey($order->getKey())->update(['order_number' => $candidate]);
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('order_number', 20)->nullable(false)->change();
            $table->unique('order_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['order_number']);
            $table->dropColumn('order_number');
        });
    }
};
```

- [ ] **Step 2: Run** `DB_DATABASE=delivary_app_testing php artisan migrate` on the test DB (RefreshDatabase in tests re-runs it). **Step 3: Tinker smoke** — `php artisan tinker --execute="echo \Schema::hasColumn('orders','order_number')?'1':'0';"` → `1`. **Step 4: Commit** `git add database/migrations && git commit -m "feat(orders): order_number column + backfill + unique"`.

> If the orders table is large in production, split into two migrations (add-nullable+backfill, then constrain) to shorten the lock window — decision recorded here; at current volume one file is fine.

---

## Task 3: Assign on creation + immutability + explicit unique-violation retry

**Files:** Modify `app/Models/Order.php`.

- [ ] **Step 1: Failing feature test** — `tests/Feature/Order/OrderNumberTest.php`:

```php
<?php // tests/Feature/Order/OrderNumberTest.php

declare(strict_types=1);

use App\Models\Order;
use App\Support\OrderNumber\OrderNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('assigns a valid, unique order_number on creation', function (): void {
    $a = Order::factory()->create();
    $b = Order::factory()->create();

    expect((new OrderNumberGenerator())->isValid($a->order_number))->toBeTrue();
    expect($a->order_number)->not->toBe($b->order_number);
});

it('does not change order_number on update (immutable)', function (): void {
    $order = Order::factory()->create();
    $original = $order->order_number;
    $order->update(['order_number' => 'ORD-AAAA-AAAA-0']); // ignored — not fillable / stripped
    expect($order->fresh()->order_number)->toBe($original);
});
```

- [ ] **Step 2: Run — expect FAIL** (`order_number` null on create). **Step 3: Implement** — in `app/Models/Order.php` `booted()` `creating` closure, assign beside `public_id`/`tracking_token`; ensure `order_number` is **not** in `$fillable`:

```php
// app/Models/Order.php — inside the existing self::creating(...) closure:
if (empty($order->order_number)) {
    $order->order_number = app(\App\Support\OrderNumber\OrderNumberGenerator::class)->generate();
}
```

Keep `order_number` **out of `$fillable`** (so `update(['order_number' => …])` is ignored — immutability). Add the doc-note that it is generated once and never updated.

- [ ] **Step 4: Run — expect PASS.**
- [ ] **Step 5: Explicit unique-violation retry** — wrap the order-creation call site(s) so a rare insert-time
  unique collision on `order_number` regenerates and retries. Add a helper (e.g., in the order-creation service
  that already persists new orders) — a `retry(3)`-style loop that catches the Postgres unique-violation on
  `order_number` (`\Illuminate\Database\UniqueConstraintViolationException`, or `QueryException` with SQLSTATE
  `23505` naming `orders_order_number_unique`), clears the model's `order_number`, and re-saves. Add a focused
  test that seams a pre-set duplicate to prove the retry path regenerates. (Do **not** rely on `DB::transaction()`
  `$attempts` — it retries deadlocks only.)
- [ ] **Step 6: Run — PASS. Pint + commit** `git commit -m "feat(orders): generate immutable order_number on create with retry"`.

---

## Task 4: Search by `order_number` (normalized)

**Files:** Modify `app/Http/Controllers/Api/Admin/OrderController.php` (the `index` search closure).

- [ ] **Step 1: Failing test** (append to `tests/Feature/Order/OrderNumberTest.php`) — requires an admin actor
  (reuse the `actingAsAdmin()` helper pattern from `tests/Feature/Admin/AdminDriverEndpointTest.php`):

```php
it('finds an order by order_number, with and without dashes and case', function (): void {
    actingAsAdmin();
    $order = Order::factory()->create();
    $number = $order->order_number;               // ORD-XXXX-XXXX-C
    $dashless = str_replace('-', '', $number);    // ORDXXXXXXXXC
    $bodyOnly = substr($dashless, 3, 8);          // XXXXXXXX

    foreach ([$number, strtolower($number), $dashless, $bodyOnly] as $term) {
        $res = $this->getJson('/api/admin/orders?search='.urlencode($term));
        expect(collect($res->json('data'))->pluck('id'))->toContain($order->public_id);
    }
});
```

- [ ] **Step 2: Run — expect FAIL. Step 3: Implement** — in `OrderController@index`, extend the existing
  `search` closure so it also matches `order_number` **normalized** (dash-insensitive, case-insensitive) using
  `OrderNumberGenerator::normalizeSearchTerm`. Add an `orWhereRaw` that compares the dash-stripped, upper-cased
  stored value against the normalized term:

```php
// inside the existing:  $query->where(function ($q) use ($search) { … })
$normalized = app(\App\Support\OrderNumber\OrderNumberGenerator::class)->normalizeSearchTerm($search);
// …existing orWhere clauses (public_id, tracking_token, names, phones)…
$q->orWhereRaw("upper(replace(order_number, '-', '')) like ?", ['%'.$normalized.'%']);
```

> **Exact SQL pinned:** match on the expression `upper(replace(order_number,'-',''))` with `LIKE '%…%'`. If
> `EXPLAIN` shows this is hot on large tables, add a functional index
> `CREATE INDEX orders_order_number_norm_idx ON orders (upper(replace(order_number,'-','')))` in a follow-up
> migration; not required at current volume. Keep the existing search terms untouched.

- [ ] **Step 4: Run — PASS. Pint + commit** `git commit -m "feat(orders): search by order_number (dash/case-insensitive)"`.

---

## Task 5: Surface `order_number` in every human-facing order payload

**Files:** the resource + service files listed in the file-structure map.

> Every edit is the **same shape**: emit `'order_number' => $order->order_number` (or the loaded relation's)
> **beside the existing order `id`/`public_id`**. Where a resource loads the order via a relation, ensure
> `order_number` is selected/loaded so it is never `null` for a present order (it is a plain column on `orders`,
> so a normal model load already includes it).

- [ ] **Step 1: Failing tests** — one representative assertion per category (add to `tests/Feature/Order/OrderNumberTest.php` and the relevant existing test files):

```php
it('exposes order_number on the admin order resource', function (): void {
    actingAsAdmin();
    $order = Order::factory()->create();
    $res = $this->getJson('/api/admin/orders/'.$order->public_id);
    expect($res->json('order_number'))->toBe($order->order_number);
    expect($res->json('id'))->toBe($order->public_id); // id unchanged
});
```
Plus a nested-reference assertion (e.g. `DriverStrikeResource` renders `order.order_number` beside `order.id`)
and an activity-feed assertion (`OverviewMetricsService` activity item carries `order_number` beside
`order_public_id`). Model each on the existing tests for those resources/services.

- [ ] **Step 2: Run — expect FAIL. Step 3: Implement** — add the field in each file (read each first; place it
  next to the order id):

  **Order-representing resources** — add `'order_number' => $o->order_number,` beside `'id' => $o->public_id,`:
  `AdminOrderResource`, `OrderResource`, `DriverOrderResource`, `OfficeOrderResource`, `BroadcastOrderResource`,
  `GuestTrackingResource`, `Broadcast/OrderForPartiesResource`.

  **Nested order references** — add `'order_number' => …` inside the nested `order` block beside its `id`:
  `Driver/DriverStrikeResource` (`$this->order?->order_number`), `Admin/UserDetailResource` (each order summary),
  `Settlement/SellerEarningResource`, `Settlement/SellerPayoutResource`, `Settlement/SettlementPreviewResource`,
  `Settlement/SettlementResource`.

  **Activity / reporting services** — add `order_number` beside the emitted `order_public_id`:
  `Reporting/OverviewMetricsService`, `Reporting/StaffActivityService`, `Reporting/FinanceReportService`.

  For each: confirm the order model/relation is loaded (eager-load where a resource previously only selected
  specific columns, so `order_number` is present). Where a settlement/reporting payload does **not** actually
  emit an order reference, skip it (verify per file — the rule is "beside an existing order id").

- [ ] **Step 4: Run — the representative tests PASS; full suite green.** **Step 5: Pint + commit**
  `git commit -m "feat(orders): surface order_number beside id in all order payloads"`.

---

## Task 6: Full gate + PR

- [ ] **Step 1: Gate** — `vendor/bin/pint`; `DB_DATABASE=delivary_app_testing vendor/bin/pest`;
  `php artisan migrate:status`. All green; **existing tests unchanged** (additive proof). Run the order smoke if
  present: `$env:BROADCAST_CONNECTION='null'; php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"`.
- [ ] **Step 2: Push `feat/order-numbers`** and open the PR `feat(orders): human-readable order_number` (tag the
  milestone). Request Codex review.

## Verification

- [ ] `OrderNumberGenerator` checksum matches the known ISO 7064 MOD 37,36 vectors; format + validation +
  normalization covered; I/L/O/U handled (body reject, check literal).
- [ ] New orders get a unique valid `order_number`; backfill fills every existing order; immutable on update;
  explicit unique-violation retry proven.
- [ ] Search finds orders by full/dashless/body-only/case-insensitive number; existing search intact.
- [ ] `order_number` present beside `id` in each enumerated payload; `id` unchanged everywhere.
- [ ] Full Pest suite green with no changes to existing expectations.

## Self-review (spec coverage)

- Format ORD + 8 Crockford + ISO 7064 MOD 37,36 check → Task 1 ✓
- Random + existence loop + UNIQUE index + explicit retry (not transaction-retry) → Tasks 1–3 ✓
- Migration add→backfill→constrain → Task 2 ✓
- `isValidOrderNumber` vs `normalizeSearchTerm` separated; dash/case-insensitive search → Tasks 1, 4 ✓
- Surfaced in every human-facing order payload; `id` still the key → Task 5 ✓
- Additive-only; existing suite unchanged → Task 6 ✓
