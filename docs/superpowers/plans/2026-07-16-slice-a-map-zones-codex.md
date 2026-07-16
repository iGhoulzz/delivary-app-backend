# Slice A — Map Zone GeoJSON (Codex) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`). Follow `docs/CLAUDE.md` + WORKFLOW gates (Pint + Pest).

**Goal:** Add `GET /api/admin/map/zones` — service-area + region polygons as one cacheable GeoJSON `FeatureCollection`, separate from the 60s-polled `/map/overview`, so a future dashboard "Zones" overlay can draw them without re-transferring polygons.

**Architecture:** A single invokable `MapZonesController` runs two raw `ST_AsGeoJSON(…, 6)` queries (service_areas; regions LEFT JOIN office_locations), assembles one `kind`-discriminated `FeatureCollection` in PHP, and serves it with a weak `ETag` + `304` conditional handling. Read-only, admin-gated. No new tables.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL + PostGIS. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-07-16-dashboard-operations-ux-support-design.md`

**Owner:** Codex · **Branch:** `feat/map-zones` (off `main`) · **Milestone:** Dashboard Operations UX Support.

---

## Ownership & sequencing

- Disjoint from Slice B (order numbers, Claude) — parallel branches/PRs, merge either order.
- **Gate before PR:** `vendor/bin/pint` clean; `DB_DATABASE=delivary_app_testing_codex vendor/bin/pest` green
  (Codex test DB per WORKFLOW §4); `php artisan route:list --path=api/admin/map` shows `map/zones`.

## Data source (verified)

- `service_areas`: `id`, `name`, `boundary` `GEOGRAPHY(Polygon,4326)` (GIST-indexed), `is_active`, timestamps.
- `regions`: `id`, `service_area_id`, `office_id` (**nullable**), `name`, `boundary`, `is_active`, `base_fee`,
  timestamps. `regions.office_id → office_locations.id`; the public ref is `office_locations.public_id`.

## File-structure map

```
NEW
  app/Http/Controllers/Api/Admin/MapZonesController.php
  tests/Feature/Admin/MapZonesTest.php
MODIFY
  routes/api.php   # add: Route::get('map/zones', MapZonesController::class)->name('map.zones');
```

---

## Task 1: Route + admin gate

**Files:** Modify `routes/api.php`; create `app/Http/Controllers/Api/Admin/MapZonesController.php` (stub).

- [ ] **Step 1: Failing test** — `tests/Feature/Admin/MapZonesTest.php` (gate only for now):

```php
<?php // tests/Feature/Admin/MapZonesTest.php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
});

function actingAsMapAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    return $admin;
}

it('rejects a non-admin', function (): void {
    Sanctum::actingAs(User::factory()->create());
    expect($this->getJson('/api/admin/map/zones')->status())->toBe(403);
});

it('rejects an admin who must change password', function (): void {
    $admin = User::factory()->create(['must_change_password' => true]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);
    expect($this->getJson('/api/admin/map/zones')->status())->toBe(403);
});
```

- [ ] **Step 2: Run — expect FAIL** (route missing). **Step 3: Implement** — add the route in the existing
  admin map group (beside `map/overview`, same middleware `auth:sanctum` + `role:admin` +
  `staff.password_change_required`):

```php
// routes/api.php — in the /admin map group:
Route::get('map/zones', \App\Http\Controllers\Api\Admin\MapZonesController::class)->name('map.zones');
```

And the stub controller returning an empty collection (fleshed out next):

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MapZonesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['type' => 'FeatureCollection', 'features' => []]);
    }
}
```

> Confirm `must_change_password` is the column the `staff.password_change_required` middleware checks (grep the
> middleware); adjust the test's factory attribute if the flag differs.

- [ ] **Step 4: Run — the gate tests PASS.** **Step 5: Pint + commit** `git commit -m "feat(map): /admin/map/zones route + admin gate"`.

---

## Task 2: GeoJSON `FeatureCollection` assembly

**Files:** Modify `MapZonesController`; extend `MapZonesTest`.

- [ ] **Step 1: Failing test** — seed a service area, a region with an office, and a region **without** an
  office (nullable), plus an inactive zone; assert the collection shape:

```php
use Tests\Support\TestWorld;

it('returns service areas and regions as a GeoJSON FeatureCollection', function (): void {
    actingAsMapAdmin();

    // No factories exist for these models — TestWorld::create() seeds one active ServiceArea + Region
    // (with office, base_fee 10.00) + OfficeLocation, all with a valid 4326 boundary.
    $world = TestWorld::create();
    $region = $world['region'];       // name 'Test Region', active, has office
    $office = $world['office'];
    $boundary = $region->boundary;    // reuse the valid polygon for the extra region

    \App\Models\Region::create([
        'service_area_id' => $region->service_area_id,
        'office_id' => null,
        'name' => 'No Office',
        'boundary' => $boundary,
        'is_active' => false,
        'base_fee' => '2.00',
    ]);

    $res = $this->getJson('/api/admin/map/zones');
    expect($res->status())->toBe(200);
    expect($res->json('type'))->toBe('FeatureCollection');

    $features = collect($res->json('features'));
    $saFeature = $features->firstWhere('properties.kind', 'service_area');
    expect($saFeature['properties'])->toMatchArray(['kind' => 'service_area', 'is_active' => true]);
    expect($saFeature['geometry']['type'])->toBe('Polygon');

    $withOffice = $features->first(fn ($f) => ($f['properties']['name'] ?? null) === 'Test Region');
    expect($withOffice['properties']['office']['id'])->toBe($office->public_id);
    expect($withOffice['properties'])->toHaveKeys(['service_area_id', 'base_fee']);

    $noOffice = $features->first(fn ($f) => ($f['properties']['name'] ?? null) === 'No Office');
    expect($noOffice['properties']['office'])->toBeNull();       // nullable office
    expect($noOffice['properties']['is_active'])->toBeFalse();   // inactive included
});

it('emits office: null when a region\'s office is soft-deleted', function (): void {
    actingAsMapAdmin();
    $world = TestWorld::create();
    $world['office']->delete(); // OfficeLocation uses SoftDeletes

    $feature = collect($this->getJson('/api/admin/map/zones')->json('features'))
        ->first(fn ($f) => ($f['properties']['name'] ?? null) === 'Test Region');
    expect($feature['properties']['office'])->toBeNull();
});
```

- [ ] **Step 2: Run — expect FAIL. Step 3: Implement** the two raw queries + assembly in `__invoke`:

```php
$serviceAreas = \Illuminate\Support\Facades\DB::select(
    'SELECT id, name, is_active, ST_AsGeoJSON(boundary, 6) AS geometry FROM service_areas'
);
$regions = \Illuminate\Support\Facades\DB::select(
    'SELECT r.id, r.name, r.is_active, r.service_area_id, r.base_fee,
            o.public_id AS office_public_id, o.name AS office_name,
            ST_AsGeoJSON(r.boundary, 6) AS geometry
       FROM regions r
       LEFT JOIN office_locations o ON o.id = r.office_id AND o.deleted_at IS NULL'
);
// office_locations uses SoftDeletes — the `AND o.deleted_at IS NULL` makes a soft-deleted office yield
// office_public_id = NULL, so the region emits "office": null (never a stale/deleted office).

$features = [];
foreach ($serviceAreas as $sa) {
    $features[] = [
        'type' => 'Feature',
        'geometry' => json_decode((string) $sa->geometry, true),
        'properties' => [
            'kind' => 'service_area',
            'id' => (int) $sa->id,
            'name' => $sa->name,
            'is_active' => (bool) $sa->is_active,
        ],
    ];
}
foreach ($regions as $r) {
    $features[] = [
        'type' => 'Feature',
        'geometry' => json_decode((string) $r->geometry, true),
        'properties' => [
            'kind' => 'region',
            'id' => (int) $r->id,
            'name' => $r->name,
            'is_active' => (bool) $r->is_active,
            'service_area_id' => (int) $r->service_area_id,
            'base_fee' => $r->base_fee !== null ? (string) $r->base_fee : null,
            'office' => $r->office_public_id !== null
                ? ['id' => $r->office_public_id, 'name' => $r->office_name]
                : null,
        ],
    ];
}

return response()->json(['type' => 'FeatureCollection', 'features' => $features]);
```

- [ ] **Step 4: Run — PASS. Step 5: Pint + commit** `git commit -m "feat(map): assemble zones GeoJSON FeatureCollection"`.

---

## Task 3: ETag + `304` conditional caching

**Files:** Modify `MapZonesController`; extend `MapZonesTest`.

- [ ] **Step 1: Failing test** — ETag present; matching `If-None-Match` → 304 (no body); a zone mutation changes
  the ETag:

```php
use Tests\Support\TestWorld;

it('serves a weak ETag and honors If-None-Match with 304', function (): void {
    actingAsMapAdmin();
    TestWorld::create();

    $first = $this->getJson('/api/admin/map/zones');
    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeNull();

    $again = $this->getJson('/api/admin/map/zones', ['If-None-Match' => $etag]);
    expect($again->status())->toBe(304);
    expect($again->getContent())->toBe('');
});

it('changes the ETag when a zone is added, updated, or deleted', function (): void {
    actingAsMapAdmin();
    $world = TestWorld::create();
    $sa = \App\Models\ServiceArea::query()->firstOrFail();
    $boundary = $world['region']->boundary;

    $e1 = $this->getJson('/api/admin/map/zones')->headers->get('ETag');

    $sa->update(['name' => 'Renamed']);                                   // update → max(updated_at) moves
    $e2 = $this->getJson('/api/admin/map/zones')->headers->get('ETag');
    expect($e2)->not->toBe($e1);

    \App\Models\ServiceArea::create(['name' => 'Second', 'boundary' => $boundary, 'is_active' => true]); // add → count
    $e3 = $this->getJson('/api/admin/map/zones')->headers->get('ETag');
    expect($e3)->not->toBe($e2);

    $sa->delete();                                                        // delete → row drops from aggregate
    $e4 = $this->getJson('/api/admin/map/zones')->headers->get('ETag');
    expect($e4)->not->toBe($e3);
});

it('changes the ETag for same-second updates and for an office rename', function (): void {
    actingAsMapAdmin();
    $world = TestWorld::create();
    $sa = \App\Models\ServiceArea::query()->firstOrFail();

    $e1 = $this->getJson('/api/admin/map/zones')->headers->get('ETag');
    // Two updates in immediate succession (same wall-clock second) — xmin changes each time, so the ETag must
    // change even though updated_at (1s precision) may not distinguish them.
    $sa->update(['name' => 'A']);
    $e2 = $this->getJson('/api/admin/map/zones')->headers->get('ETag');
    $sa->update(['name' => 'B']);
    $e3 = $this->getJson('/api/admin/map/zones')->headers->get('ETag');
    expect($e2)->not->toBe($e1);
    expect($e3)->not->toBe($e2);

    // An office rename must change the ETag (office name is embedded in the region features).
    $world['office']->update(['name' => 'Renamed Office']);
    $e4 = $this->getJson('/api/admin/map/zones')->headers->get('ETag');
    expect($e4)->not->toBe($e3);
});
```

- [ ] **Step 2: Run — expect FAIL. Step 3: Implement** — compute the fingerprint across **all three** tables
  (service_areas, regions, **office_locations** — the response embeds office name/id), set a weak ETag via
  Symfony, short-circuit `304` **before** building the body, and attach `Cache-Control`:

```php
// Row-version fingerprint: Postgres `xmin` (the tuple's inserting/updating xid) changes on EVERY update,
// even two within the same second — unlike `updated_at`, which has 1-second precision and could miss them.
// The ordered aggregate of (id, xmin) per table captures every insert (new id), update (new xmin), and delete
// (id disappears), across the three tables the response reads.
$fingerprint = \Illuminate\Support\Facades\DB::selectOne(
    "SELECT
        (SELECT COALESCE(md5(string_agg(id::text || ':' || xmin::text, ',' ORDER BY id)), '') FROM service_areas) AS sa,
        (SELECT COALESCE(md5(string_agg(id::text || ':' || xmin::text, ',' ORDER BY id)), '') FROM regions) AS r,
        (SELECT COALESCE(md5(string_agg(id::text || ':' || xmin::text, ',' ORDER BY id)), '') FROM office_locations WHERE deleted_at IS NULL) AS o"
);
$hash = md5($fingerprint->sa.'|'.$fingerprint->r.'|'.$fingerprint->o);

// Let Symfony format the weak ETag (W/"<hash>") and compare If-None-Match — do NOT hand-build the header string.
$response = new \Illuminate\Http\JsonResponse();
$response->setEtag($hash, true);                         // weak ETag
$response->headers->set('Cache-Control', 'private, must-revalidate');
if ($response->isNotModified($request)) {
    return $response;                                     // 304, no body built
}

// Modified → build the collection and attach it to the same (ETag-bearing) response.
// … build $features …
$response->setData(['type' => 'FeatureCollection', 'features' => $features]);

return $response;
```

> The `xmin` row-version aggregate covers all three tables the response reads (service_areas, regions,
> office_locations), so **any** add/update/delete of a zone **or an office** — including two updates in the same
> second, or an office rename (office name is in the response) — changes the ETag. No reliance on `updated_at`
> precision. Soft-deleted offices drop out of the aggregate (`WHERE deleted_at IS NULL`).

- [ ] **Step 4: Run — PASS. Step 5: Pint + commit** `git commit -m "feat(map): ETag/304 conditional caching for zones"`.

---

## Task 4: Full gate + PR

- [ ] **Step 1: Gate** — `vendor/bin/pint`; `DB_DATABASE=delivary_app_testing_codex vendor/bin/pest tests/Feature/Admin/MapZonesTest.php` then the full suite; `php artisan route:list --path=api/admin/map`.
- [ ] **Step 2: Push `feat/map-zones`** and open the PR `feat(map): admin zones GeoJSON endpoint` (tag the
  milestone). Request Claude review.

## Verification

- [ ] `GET /admin/map/zones` returns a `FeatureCollection`: service-area + region features, correct properties,
  numeric ids, valid GeoJSON geometry, region `office` object **or `null`**, inactive zones included.
- [ ] `ETag` present (row-version `xmin` fingerprint); matching `If-None-Match` → `304` empty;
  add/update/delete of a zone, **two same-second updates**, and an **office rename** each change the ETag.
- [ ] Admin gate: non-admin → 403; must-change-password → 403; unauthenticated → 401.
- [ ] Pint + full Pest green; route registered.

## Self-review (spec coverage)

- Separate cacheable endpoint (not in `/map/overview`) → Tasks 1–3 ✓
- One `kind`-discriminated FeatureCollection; numeric id; nullable office; base_fee; inactive included → Task 2 ✓
- `ST_AsGeoJSON(…,6)` precision, SQL assembly (no object hydration, no ST_Simplify) → Task 2 ✓
- ETag/304 + changes on mutation → Task 3 ✓
- Admin + password-change gate → Task 1 ✓
