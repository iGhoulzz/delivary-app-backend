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
it('returns service areas and regions as a GeoJSON FeatureCollection', function (): void {
    actingAsMapAdmin();

    $office = \App\Models\OfficeLocation::factory()->create();
    $sa = \App\Models\ServiceArea::factory()->create(['name' => 'Tripoli SA', 'is_active' => true]);
    \App\Models\Region::factory()->create([
        'service_area_id' => $sa->id, 'office_id' => $office->id, 'name' => 'Center', 'is_active' => true,
    ]);
    \App\Models\Region::factory()->create([
        'service_area_id' => $sa->id, 'office_id' => null, 'name' => 'No Office', 'is_active' => false,
    ]);

    $res = $this->getJson('/api/admin/map/zones');
    expect($res->status())->toBe(200);
    expect($res->json('type'))->toBe('FeatureCollection');

    $features = collect($res->json('features'));
    $saFeature = $features->firstWhere('properties.kind', 'service_area');
    expect($saFeature['properties'])->toMatchArray(['kind' => 'service_area', 'name' => 'Tripoli SA', 'is_active' => true]);
    expect($saFeature['geometry']['type'])->toBe('Polygon');

    $withOffice = $features->first(fn ($f) => ($f['properties']['kind'] ?? null) === 'region' && ($f['properties']['name'] ?? null) === 'Center');
    expect($withOffice['properties']['office']['id'])->toBe($office->public_id);
    expect($withOffice['properties'])->toHaveKeys(['service_area_id', 'base_fee']);

    $noOffice = $features->first(fn ($f) => ($f['properties']['name'] ?? null) === 'No Office');
    expect($noOffice['properties']['office'])->toBeNull();       // nullable office
    expect($noOffice['properties']['is_active'])->toBeFalse();   // inactive included
});
```

> `Region`/`ServiceArea`/`OfficeLocation` factories must set a valid `boundary` Polygon (4326). If a factory
> doesn't exist or lacks a boundary, add a minimal factory / `boundary` state (a small square polygon via
> `clickbar/laravel-magellan`) as part of this step — the plan requires seeded polygons.

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
       LEFT JOIN office_locations o ON o.id = r.office_id'
);

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
it('serves a weak ETag and honors If-None-Match with 304', function (): void {
    actingAsMapAdmin();
    \App\Models\ServiceArea::factory()->create();

    $first = $this->getJson('/api/admin/map/zones');
    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeNull();

    $again = $this->getJson('/api/admin/map/zones', ['If-None-Match' => $etag]);
    expect($again->status())->toBe(304);
    expect($again->getContent())->toBe('');
});

it('changes the ETag when a zone is added, updated, or deleted', function (): void {
    actingAsMapAdmin();
    $sa = \App\Models\ServiceArea::factory()->create();
    $e1 = $this->getJson('/api/admin/map/zones')->headers->get('ETag');

    $sa->update(['name' => 'Renamed']);
    $e2 = $this->getJson('/api/admin/map/zones')->headers->get('ETag');
    expect($e2)->not->toBe($e1);

    \App\Models\ServiceArea::factory()->create();
    $e3 = $this->getJson('/api/admin/map/zones')->headers->get('ETag');
    expect($e3)->not->toBe($e2);

    $sa->delete();
    $e4 = $this->getJson('/api/admin/map/zones')->headers->get('ETag');
    expect($e4)->not->toBe($e3);
});
```

- [ ] **Step 2: Run — expect FAIL. Step 3: Implement** — compute the ETag from counts + `max(updated_at)` across
  both tables **before** building the body; short-circuit on `If-None-Match`; attach `ETag` + `Cache-Control`:

```php
$fingerprint = \Illuminate\Support\Facades\DB::selectOne(
    "SELECT (SELECT count(*) FROM service_areas) AS sa_c,
            (SELECT COALESCE(max(updated_at)::text,'') FROM service_areas) AS sa_m,
            (SELECT count(*) FROM regions) AS r_c,
            (SELECT COALESCE(max(updated_at)::text,'') FROM regions) AS r_m"
);
$etag = 'W/\"'.md5($fingerprint->sa_c.'|'.$fingerprint->sa_m.'|'.$fingerprint->r_c.'|'.$fingerprint->r_m).'\"';

if (trim((string) $request->headers->get('If-None-Match')) === $etag) {
    return response()->json(null, 304)->withHeaders(['ETag' => $etag, 'Cache-Control' => 'private, must-revalidate']);
}

// … build $features … then:
return response()->json(['type' => 'FeatureCollection', 'features' => $features])
    ->withHeaders(['ETag' => $etag, 'Cache-Control' => 'private, must-revalidate']);
```

> `updated_at::text` in the fingerprint means add/update/delete all move the count or the max timestamp, so the
> ETag changes on any mutation. (A delete lowers the count; an add/update raises count or max.) Note a hard
> delete that lowers `max(updated_at)` back to an older value is still caught by the count change.

- [ ] **Step 4: Run — PASS. Step 5: Pint + commit** `git commit -m "feat(map): ETag/304 conditional caching for zones"`.

---

## Task 4: Full gate + PR

- [ ] **Step 1: Gate** — `vendor/bin/pint`; `DB_DATABASE=delivary_app_testing_codex vendor/bin/pest tests/Feature/Admin/MapZonesTest.php` then the full suite; `php artisan route:list --path=api/admin/map`.
- [ ] **Step 2: Push `feat/map-zones`** and open the PR `feat(map): admin zones GeoJSON endpoint` (tag the
  milestone). Request Claude review.

## Verification

- [ ] `GET /admin/map/zones` returns a `FeatureCollection`: service-area + region features, correct properties,
  numeric ids, valid GeoJSON geometry, region `office` object **or `null`**, inactive zones included.
- [ ] `ETag` present; matching `If-None-Match` → `304` empty; add/update/delete changes the ETag.
- [ ] Admin gate: non-admin → 403; must-change-password → 403; unauthenticated → 401.
- [ ] Pint + full Pest green; route registered.

## Self-review (spec coverage)

- Separate cacheable endpoint (not in `/map/overview`) → Tasks 1–3 ✓
- One `kind`-discriminated FeatureCollection; numeric id; nullable office; base_fee; inactive included → Task 2 ✓
- `ST_AsGeoJSON(…,6)` precision, SQL assembly (no object hydration, no ST_Simplify) → Task 2 ✓
- ETag/304 + changes on mutation → Task 3 ✓
- Admin + password-change gate → Task 1 ✓
