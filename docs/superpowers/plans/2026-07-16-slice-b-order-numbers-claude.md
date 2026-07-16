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
  app/Support/OrderNumber/OrderNumberBackfiller.php    # assign order_number to rows where it is null (migration + test)
  app/Support/OrderNumber/OrderNumberRetry.php         # closure-based unique-violation retry wrapper
  database/migrations/2026_07_16_000100_add_order_number_to_orders_table.php   # add nullable + backfill + constrain
  tests/Unit/OrderNumberGeneratorTest.php
  tests/Unit/OrderNumberRetryTest.php
  tests/Feature/Order/OrderNumberTest.php              # creation + search + backfill feature tests
MODIFY
  app/Models/Order.php                                 # creating hook (assign), keep out of update path
  app/Services/Order/CreationService.php               # wrap DB::transaction() in a unique-violation retry
  app/Http/Controllers/Api/Admin/OrderController.php   # add order_number to the search closure (guard empty term)
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
  # selective order loads that currently select only id/public_id/etc. — each MUST add order_number to its
  # column selection so it isn't emitted as null:
  app/Http/Controllers/Api/Me/Settlement/ShowEarningsController.php
  app/Events/SellerEarningCleared.php
  app/Jobs/ClearSellerEarningsJob.php
  app/Services/Settlement/SettlementService.php
  app/Services/Settlement/SellerPayoutService.php
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
    // Bodies whose check character is a letter (proves the check char lives in the full 0-9A-Z alphabet):
    ['00000002', 'U'],
    ['00000005', 'O'],
    ['00000008', 'I'],
    ['00000088', 'L'],
]);

it('throws on a non-alphanumeric character in the checksum input', function (): void {
    gen()->checkCharacter('7K3M-9Q2D'); // dash is not alphanumeric — must throw, never silently 0
})->throws(InvalidArgumentException::class);

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

it('aliases I/L→1 and O→0 in the body, rejects U, and treats the check char literally', function (): void {
    // Canonical numbers validate.
    expect(gen()->isValid(gen()->build()))->toBeTrue();
    // Body 00000002 → check U. Its aliased spelling (O→0) must validate the same:
    expect(gen()->isValid('ORD-OOOO-OOO2-U'))->toBeTrue();
    // I/L alias to 1: body 11111111 spelled with I must validate iff the check matches body 11111111.
    $check = gen()->checkCharacter('11111111');
    expect(gen()->isValid("ORD-IIII-IIII-{$check}"))->toBeTrue();
    // U is NOT a valid body character (Crockford omits it; it is not an alias).
    expect(gen()->isValid('ORD-UUUU-UUUU-1'))->toBeFalse();
    // The check character is literal — a legit letter check (e.g. U) is accepted, never normalized.
    expect(gen()->isValid('ORD-0000-0002-U'))->toBeTrue();
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

    private const MAX_ATTEMPTS = 5;

    /** Crockford Base32 — 0-9 A-Z minus I, L, O, U (the characters humans confuse). */
    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** ISO 7064 alphanumeric alphabet: value('0')=0 … value('9')=9, value('A')=10 … value('Z')=35. */
    private const ALPHANUMERIC = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** Generate a canonical, unique order number (existence-checked, bounded attempts). */
    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $this->build();
            if (! Order::query()->where('order_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            'Could not generate a unique order_number after '.self::MAX_ATTEMPTS.' attempts.',
        );
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
                throw new \InvalidArgumentException("Non-alphanumeric character '{$char}' in checksum input.");
            }
            $sum = ($value + $product) % $other;
            $product = (2 * ($sum === 0 ? $other : $sum)) % $modulus;
        }

        $pz = $modulus - $product;      // 1..36
        $checkValue = $pz === $other ? 0 : $pz; // 0..35

        return self::ALPHANUMERIC[$checkValue];
    }

    /**
     * Validate an order number. The check character is compared literally; the BODY gets Crockford
     * input-aliasing (I/L → 1, O → 0), and U is rejected (not a Crockford symbol, not an alias).
     */
    public function isValid(string $orderNumber): bool
    {
        $value = strtoupper(trim($orderNumber));
        if (preg_match('/^ORD-([0-9A-Z]{4})-([0-9A-Z]{4})-([0-9A-Z])$/', $value, $m) !== 1) {
            return false;
        }
        $check = $m[3];        // literal — never Crockford-normalized (may itself be I/L/O/U)
        $body = $m[1].$m[2];
        if (str_contains($body, 'U')) {
            return false;      // U is not a Crockford body symbol and has no alias
        }
        // Crockford input-aliasing on the BODY only: I/L → 1, O → 0.
        $body = strtr($body, ['I' => '1', 'L' => '1', 'O' => '0']);

        return $this->checkCharacter($body) === $check;
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

**Files:** Create `app/Support/OrderNumber/OrderNumberBackfiller.php`, `database/migrations/2026_07_16_000100_add_order_number_to_orders_table.php`.

- [ ] **Step 1: Extract the backfill into a reusable, testable action** (so the migration test can drive the
  real backfill code against pre-existing null rows):

```php
<?php // app/Support/OrderNumber/OrderNumberBackfiller.php

declare(strict_types=1);

namespace App\Support\OrderNumber;

use App\Models\Order;

final class OrderNumberBackfiller
{
    /** Assign a unique order_number to every order that currently has none. Collision-safe within the run. */
    public static function run(): void
    {
        $generator = new OrderNumberGenerator();
        $used = [];
        Order::query()->whereNull('order_number')->orderBy('id')->chunkById(500, function ($orders) use ($generator, &$used): void {
            foreach ($orders as $order) {
                do {
                    $candidate = $generator->build();
                } while (isset($used[$candidate]) || Order::query()->where('order_number', $candidate)->exists());
                $used[$candidate] = true;
                Order::query()->whereKey($order->getKey())->update(['order_number' => $candidate]);
            }
        });
    }
}
```

- [ ] **Step 2: Implement the migration** (nullable → backfill → unique + not-null, one file):

```php
<?php

declare(strict_types=1);

use App\Support\OrderNumber\OrderNumberBackfiller;
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

        OrderNumberBackfiller::run(); // assign unique numbers to existing rows (the UNIQUE index below is the backstop)

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

- [ ] **Step 3: Run** `DB_DATABASE=delivary_app_testing php artisan migrate`. **Step 4: Genuine backfill +
  constraint tests** — drive the **real backfill code** against rows that have **no** `order_number` (not the
  creating-hook path), then prove the DB enforces NOT NULL and UNIQUE (add to
  `tests/Feature/Order/OrderNumberTest.php`):

```php
use App\Support\OrderNumber\OrderNumberBackfiller;
use App\Support\OrderNumber\OrderNumberGenerator;
use Illuminate\Support\Facades\DB;

it('backfills pre-existing rows that have no order_number, then enforces the constraints', function (): void {
    // Arrange rows in the pre-backfill state: relax the column, null every order_number.
    $orders = Order::factory()->count(4)->create();
    DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_order_number_unique');
    DB::statement('ALTER TABLE orders ALTER COLUMN order_number DROP NOT NULL');
    DB::table('orders')->update(['order_number' => null]);
    expect(Order::query()->whereNotNull('order_number')->count())->toBe(0);

    // Act: run the ACTUAL backfill code the migration uses.
    OrderNumberBackfiller::run();

    // Assert: every row now has a unique, valid, non-null number.
    $numbers = Order::query()->pluck('order_number');
    expect($numbers->contains(null))->toBeFalse();
    expect($numbers->unique()->count())->toBe(4);
    $numbers->each(fn ($n) => expect((new OrderNumberGenerator())->isValid($n))->toBeTrue());

    // Re-apply the constraints and prove the DB enforces both.
    DB::statement('ALTER TABLE orders ALTER COLUMN order_number SET NOT NULL');
    DB::statement('CREATE UNIQUE INDEX orders_order_number_unique ON orders (order_number)');

    // NOT NULL:
    expect(fn () => DB::table('orders')->where('id', $orders[0]->id)->update(['order_number' => null]))
        ->toThrow(\Illuminate\Database\QueryException::class);
    // UNIQUE:
    $dup = Order::query()->whereKey($orders[0]->id)->value('order_number');
    expect(fn () => DB::table('orders')->where('id', $orders[1]->id)->update(['order_number' => $dup]))
        ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});
```

  (This exercises `OrderNumberBackfiller::run()` on genuinely-empty `order_number` rows — the same code path the
  migration runs — and then verifies the NOT NULL + UNIQUE database constraints directly.)

- [ ] **Step 5: Tinker smoke** — `php artisan tinker --execute="echo \Schema::hasColumn('orders','order_number')?'1':'0';"` → `1`. **Step 6: Commit** `git add app/Support/OrderNumber/OrderNumberBackfiller.php database/migrations tests/Feature/Order/OrderNumberTest.php && git commit -m "feat(orders): order_number column + backfill + unique"`.

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
- [ ] **Step 5: Explicit unique-violation retry — OUTSIDE the transaction, via a closure helper.**
  `CreationService::create()` persists inside `return DB::transaction(function () { … })`
  (`app/Services/Order/CreationService.php:113`). A unique violation **aborts** that Postgres transaction, so
  re-saving *inside* it cannot succeed — the retry must re-run the **whole** transaction (which regenerates
  `order_number` via the `creating` hook). Extract a tiny **closure-based** helper (so it is unit-testable
  without mocking the `final` generator), then wrap the existing transaction call with it:

```php
// app/Support/OrderNumber/OrderNumberRetry.php  (CREATE)
final class OrderNumberRetry
{
    /** Run $tx; on an order_number unique violation, re-run it (fresh order_number) up to $attempts times. */
    public static function run(\Closure $tx, int $attempts = 3): mixed
    {
        for ($i = 1; ; $i++) {
            try {
                return $tx();
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($i >= $attempts || ! str_contains($e->getMessage(), 'orders_order_number_unique')) {
                    throw $e; // exhausted, or a different unique constraint → rethrow
                }
            }
        }
    }
}
```

```php
// app/Services/Order/CreationService.php — wrap the existing DB::transaction(...) call:
return \App\Support\OrderNumber\OrderNumberRetry::run(
    fn () => DB::transaction(function () use (/* existing captures */) {
        /* existing transaction body, unchanged */
    }),
);
```

  Add `app/Support/OrderNumber/OrderNumberRetry.php` to the file map. (`UniqueConstraintViolationException` is
  Laravel's SQLSTATE `23505` wrapper.)

- [ ] **Step 6: Unit-test the retry helper directly** (no generator mock — the `final` class needs none here):

```php
use App\Support\OrderNumber\OrderNumberRetry;
use Illuminate\Database\UniqueConstraintViolationException;

function orderNumberViolation(string $constraint): UniqueConstraintViolationException
{
    // Construct the exception the way Laravel does; the message must contain the constraint name.
    return new UniqueConstraintViolationException(
        'pgsql',
        'insert into "orders" ...',
        [],
        new \PDOException("SQLSTATE[23505]: unique_violation: duplicate key value violates unique constraint \"{$constraint}\""),
    );
}

it('retries an order_number unique violation once, then succeeds', function (): void {
    $calls = 0;
    $result = OrderNumberRetry::run(function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            throw orderNumberViolation('orders_order_number_unique');
        }
        return 'ok';
    });
    expect($result)->toBe('ok');
    expect($calls)->toBe(2);
});

it('rethrows a non-order_number unique violation without retrying', function (): void {
    $calls = 0;
    expect(function () use (&$calls) {
        OrderNumberRetry::run(function () use (&$calls) {
            $calls++;
            throw orderNumberViolation('orders_public_id_unique');
        });
    })->toThrow(UniqueConstraintViolationException::class);
    expect($calls)->toBe(1);
});

it('gives up after the attempt cap', function (): void {
    expect(fn () => OrderNumberRetry::run(fn () => throw orderNumberViolation('orders_order_number_unique'), 3))
        ->toThrow(UniqueConstraintViolationException::class);
});
```

  > If the installed `UniqueConstraintViolationException` constructor signature differs, adjust
  > `orderNumberViolation()` accordingly (the only requirement: `getMessage()` contains the constraint name).

- [ ] **Step 7: Run — PASS. Pint + commit** `git commit -m "feat(orders): generate immutable order_number on create with transaction retry"`.

---

## Task 4: Search by `order_number` (normalized)

**Files:** Modify `app/Http/Controllers/Api/Admin/OrderController.php` (the `index` search closure).

- [ ] **Step 1: Failing test** (append to `tests/Feature/Order/OrderNumberTest.php`). Define a **uniquely-named
  local** admin helper — do **not** rely on `actingAsAdmin()` from another test file (Pest per-file helpers
  aren't loaded when this file runs alone):

```php
// near the top of tests/Feature/Order/OrderNumberTest.php:
function actingAsOrderAdmin(): \App\Models\User
{
    \Spatie\Permission\Models\Role::findOrCreate('admin', 'web');
    $admin = \App\Models\User::factory()->create();
    $admin->assignRole('admin');
    \Laravel\Sanctum\Sanctum::actingAs($admin);

    return $admin;
}

it('finds an order by order_number, with and without dashes and case', function (): void {
    actingAsOrderAdmin();
    $order = Order::factory()->create();
    $number = $order->order_number;               // ORD-XXXX-XXXX-C
    $dashless = str_replace('-', '', $number);    // ORDXXXXXXXXC
    $bodyOnly = substr($dashless, 3, 8);          // XXXXXXXX

    foreach ([$number, strtolower($number), $dashless, $bodyOnly] as $term) {
        $res = $this->getJson('/api/admin/orders?search='.urlencode($term));
        expect(collect($res->json('data'))->pluck('id'))->toContain($order->public_id);
    }
});

it('does not match every order when the term normalizes to empty', function (): void {
    actingAsOrderAdmin();
    Order::factory()->count(3)->create();
    // '---' → normalizeSearchTerm → '' — the order_number clause must be SKIPPED (never LIKE '%%').
    $res = $this->getJson('/api/admin/orders?search='.urlencode('---'));
    expect($res->json('data'))->toBeEmpty();
});
```

- [ ] **Step 2: Run — expect FAIL. Step 3: Implement** — in `OrderController@index`, extend the existing
  `search` closure so it also matches `order_number` **normalized** (dash/case-insensitive), **guarding the
  empty term**:

```php
// inside the existing:  $query->where(function ($q) use ($search) { … existing orWhere clauses … })
$normalized = app(\App\Support\OrderNumber\OrderNumberGenerator::class)->normalizeSearchTerm($search);
if ($normalized !== '') {  // guard: an empty normalized term must NOT produce LIKE '%%'
    $q->orWhereRaw("upper(replace(order_number, '-', '')) like ?", ['%'.$normalized.'%']);
}
```

> **Exact SQL pinned:** `upper(replace(order_number,'-',''))` with `LIKE '%…%'`, only when the normalized term
> is non-empty. Note a plain functional index does **not** accelerate a leading-wildcard `%term%` scan — if this
> ever gets hot, use a **trigram** index (`pg_trgm`, `gin (… gin_trgm_ops)`) in a follow-up; not needed at
> current volume. Keep the existing search terms untouched.

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
it('exposes order_number on the admin order resource (wrapped in data)', function (): void {
    actingAsOrderAdmin();
    $order = Order::factory()->create();
    $res = $this->getJson('/api/admin/orders/'.$order->public_id);
    // AdminOrderResource single responses are wrapped in `data`.
    expect($res->json('data.order_number'))->toBe($order->order_number);
    expect($res->json('data.id'))->toBe($order->public_id); // id unchanged
});
```
Plus a nested-reference assertion (e.g. `DriverStrikeResource` renders `order.order_number` beside `order.id`)
and an activity-feed assertion (`OverviewMetricsService` activity item carries `order_number` beside
`order_public_id`). Model each on the existing tests for those resources/services, and **check each response's
actual envelope** (`data`-wrapped vs bare) when asserting the JSON path.

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

  **Selective order loads (MUST add the column to the `select`/`->select([...])`/`with([… => fn => select])`)** —
  these currently load orders with an explicit column list that omits `order_number`, so it would serialize as
  `null` unless added. Add `order_number` to each order column selection:
  `Http/Controllers/Api/Me/Settlement/ShowEarningsController.php`, `Events/SellerEarningCleared.php`,
  `Jobs/ClearSellerEarningsJob.php`, `Services/Settlement/SettlementService.php`,
  `Services/Settlement/SellerPayoutService.php`.

  For each file: confirm the order model/relation is loaded and that `order_number` is in the selected columns
  (a plain full model load already has it; a **selective** load must add it explicitly). Where a
  settlement/reporting payload does **not** actually emit an order reference, skip it (verify per file — the rule
  is "beside an existing order id").

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
  normalization covered; body **`I/L/O` aliased** (`I/L→1`, `O→0`) and only body **`U` rejected**; check char
  literal.
- [ ] New orders get a unique valid `order_number`; backfill fills every existing order; immutable on update;
  explicit unique-violation retry proven.
- [ ] Search finds orders by full/dashless/body-only/case-insensitive number; existing search intact.
- [ ] `order_number` present beside `id` in each enumerated payload; `id` unchanged everywhere.
- [ ] Full Pest suite green with no changes to existing expectations.

## Self-review (spec coverage)

- Format ORD + 8 Crockford + ISO 7064 MOD 37,36 check → Task 1 ✓
- Random + bounded existence loop + UNIQUE index + explicit **whole-transaction** retry (via `OrderNumberRetry`,
  since `DB::transaction()` retries only deadlocks) → Tasks 1–3 ✓
- Migration add→backfill→constrain → Task 2 ✓
- `isValidOrderNumber` vs `normalizeSearchTerm` separated; dash/case-insensitive search → Tasks 1, 4 ✓
- Surfaced in every human-facing order payload; `id` still the key → Task 5 ✓
- Additive-only; existing suite unchanged → Task 6 ✓
