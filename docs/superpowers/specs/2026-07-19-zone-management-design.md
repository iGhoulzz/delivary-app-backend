# Zone Management — Design

**Date:** 2026-07-19 · **Status:** ✅ design — approved for implementation planning
**Repo:** `delivary-app` (backend) · **Consumer:** `delivary-dashboard` (admin console)
**Stack:** Laravel 13.7.0 + PostgreSQL/PostGIS
**Process:** `docs/WORKFLOW.md` · **Log:** `docs/CODEX.md` · **System spec:** `docs/SYSTEM_SPECIFICATION.md`

> Service areas, regions, and offices are **seed-only** — no admin write path exists, yet `regions.base_fee`
> prices every order and `regions.office_id` drives finance attribution and return routing. This milestone adds
> admin authoring, behind a deactivate-only lifecycle with full revision history.
>
> Reviewed twice against the live backend before approval; both rounds are folded in.

**Not additive-only.** Unlike §17.18–§17.20, this milestone deliberately changes existing schema and behaviour
(FK delete semantics, `ST_Contains` → `ST_Covers`, removal of `SoftDeletes` from `OfficeLocation`). Each
deviation is called out at its point of use.

---

## Slicing

The order is load-bearing, not cosmetic:

- **Slice 0 — Boundary-point fix + corrective backfill.** Must land **before** authoring (§6).
- **Slice 1 — Backend zone authoring** (safety, lifecycle, CRUD, revisions).
- **Slice 2 — Frontend MapLibre + Terra Draw editor** (select/draw/edit/undo, parent+sibling context,
  validation highlighting, no Delete UI, minimal snapping + gap warning).

Geometry scope: a single GeoJSON Polygon **without holes** for areas/regions; a Point for offices. Full
edge-tracing / shared-boundary topology editing is deferred.

---

## Verified codebase facts

Each was confirmed against source during design; re-verify before relying on any of them.

- `PricingService.php:110-137` — `resolveRegion()` uses `ST_Contains(...::geometry)`, **`LIMIT 1`, no
  service-area scoping**. Overlapping active regions resolve arbitrarily, and the ambiguity is **global**.
- **`ST_Contains` at five sites**, all excluding boundary points: `PricingService.php:118`,
  `PresenceService.php:210`, `ReturnOfficeResolver.php:26`, `Region.php:71`, `ServiceArea.php:46`, plus the
  §17.19 backfill migration. A boundary-point pickup is unpriced, blocks go-online, and fails return routing.
- `ReturnOfficeResolver.php:20-29` filters **only `o.is_active`** — not `r.is_active`, not
  `service_areas.is_active`. **A deactivated region still routes returns** while its office is active. It uses
  present geometry, ignores `orders.pickup_office_id`, and its `LIMIT 1` has no deterministic ordering.
  Fallback is nearest active office by `ST_Distance`.
- **Quotes are re-verified, never honoured.** `CreationService::assertQuotePriceStillCurrent:287-294` compares
  `region_id` **and** fee components (amounts 2dp, rates 4dp) and throws `QuoteMismatchException` → **409**.
  An inactive region fails earlier in `resolveRegion` → `pickup_out_of_service_area`.
- **Eight FKs reference `office_locations`:** `office_staff_assignments.office_id` **cascade**;
  `regions.office_id`, `driver_profiles.office_id`, `orders.return_office_id`, `orders.pickup_office_id`
  **nullOnDelete**; `settlements.office_id`, `seller_payouts.office_id`, `office_inventory.office_id`
  **already restrictOnDelete** — the codebase already treats offices as financially referenced and undeletable.
- **`OfficeLocation` uses `SoftDeletes`; `delete()` is an `UPDATE`, so none of those eight FKs fire.** An office
  can be soft-deleted today while settlements still reference it. FK hardening alone does not close this.
- `orders.pickup_region_id`/`pickup_office_id` are `nullOnDelete` — deleting a region blanks finance attribution
  on historical orders. `regions.service_area_id` and `driver_region.region_id` are `cascadeOnDelete`.
- **Driver regions are never enforced in assignment** — no `driver_region`/`regions()` usage in `app/Services`
  or `app/Jobs`. §7.3's "driver matching prefers same-region drivers" is **unimplemented**; `driver_region` rows
  are effectively decorative. Tracked separately from this milestone.
- **`DatabaseSeeder` uses `WithoutModelEvents`** (`DatabaseSeeder.php:10`); `DemoSeeder.php:47` already documents
  this muting `booted()` hooks. **Revision creation cannot rely on model hooks.**
- `service_areas`/`regions`: `GEOGRAPHY(Polygon,4326)`, GIST-indexed, no `public_id`, no soft deletes, no unique name.
- `office_locations`: has `public_id` (ULID) + `softDeletes()`; geometry is `GEOGRAPHY(Point)` named `location`.
  **SYSTEM_SPECIFICATION §7.2's lat/lng description is stale** and should be corrected at closeout.
- §7.3's `region ⊆ service_area` containment is documented but **never enforced in code**.
- Critical Rule 1 + §17.19 order-time snapshots ⇒ editing a boundary or `base_fee` does not reprice **existing** orders.

---

## 1. Data model & lifecycle

- **`revision`** unsigned-int on all three entities, incremented on **every** mutation (create, attribute edit,
  geometry edit, activate, deactivate, restore). Doubles as the optimistic-concurrency token: writes send
  `expected_revision`; mismatch → **409**.
- **Office ownership.** `office_locations.service_area_id` is the office's **operational parent** — required,
  indexed, `restrictOnDelete`. `region_id` is renamed **`home_region_id`**: descriptive only, **no geometric
  constraint**. An **active region must reference an active office in the same service area**. Activation order
  is therefore acyclic — service area → office → region — which removes the region/office deactivation deadlock.
- **Offices become deactivate-only; `SoftDeletes` is removed** (§7). Deactivate-only is the *primary* control;
  FK hardening is defence-in-depth, since `delete()` bypasses every FK.
- **FK hardening → `restrictOnDelete`:** `orders.pickup_region_id`, `orders.pickup_office_id`,
  `regions.service_area_id`, `driver_region.region_id`, `regions.office_id`, `office_locations.home_region_id`,
  `office_staff_assignments.office_id`, `driver_profiles.office_id`, `orders.return_office_id`.
- **No DELETE endpoints, no Delete UI.** `is_active` is the only lifecycle control. **Created inactive by default.**

**Dependency rules.** Deactivation is **blocked with 422 `active_children`**, listing each blocker's kind/id/name.
**No implicit cascade of operational status.** A future explicit bulk-retirement command (preview + confirm) may
perform cascades deliberately.

| Parent | Active children that block deactivation |
|---|---|
| Service area | active regions **and** active offices in it |
| Office | active regions referencing it via `regions.office_id` |
| `home_region_id` | none — descriptive only |

An office may not be **deactivated or moved to another service area** while active regions reference it.
**Every update, activation, and restore re-runs the full current invariant set** — invariants are never assumed
to still hold from an earlier write.

### `zone_revisions`

A single table with **exclusive-arc FKs**. Polymorphic columns cannot carry real foreign keys, and the
soft-delete hole proves model-level guards are insufficient, so protection is enforced in the database.

| Column | Notes |
|---|---|
| `service_area_id` / `region_id` / `office_location_id` | three nullable **real** FKs, each `restrictOnDelete`; `CHECK` exactly one non-null |
| `revision` | partial uniques `(service_area_id, revision)`, `(region_id, revision)`, `(office_location_id, revision)` |
| `geometry_snapshot` | native `geography(Geometry,4326)` — **not GeoJSON**, so restore round-trips exactly. `CHECK`: `GeometryType` = `POINT` when `office_location_id` is set, else `POLYGON` |
| `attributes` | `jsonb` — name, `base_fee`, links, `is_active`, etc. |
| `action` | `create` \| `update` \| `activate` \| `deactivate` \| `restore` |
| `restored_from_revision_id` | nullable self-FK; a `BEFORE INSERT` trigger enforces it references a revision of the **same entity** (a plain `CHECK` cannot reference another row) |
| `actor_id`, `reason`, `created_at` | — |

Revision 1 is the creation state. **History is append-only at the database level:** a trigger raises on `UPDATE`
or `DELETE` of any `zone_revisions` row.

**Restore never activates.** Restore applies geometry and editable attributes only, **preserving the row's
current `is_active`**, and writes a new revision with `action = restore` and `restored_from_revision_id`.
Activation remains a separate, explicitly authorised operation. Six-decimal map GeoJSON is display-only and is
never a restoration source.

---

## 2. Geometry validation

Because creation is inactive-by-default, every zone reaches `is_active = true` through a separate activation
call. Topology checks therefore run **on geometry write AND again on activation** — activation is the primary
gate. A region drawn today against a clean map can otherwise be activated later into an overlap that did not
exist at draw time.

**Tier 1 — FormRequest, no DB.**
*Polygons (areas/regions):* valid GeoJSON; `type = Polygon`; exactly one linear ring (a second ring means a hole
→ reject); ring closed; ≥4 positions; lng/lat in range; vertex-count ceiling.
*Points (offices):* `type = Point`; exactly two finite coordinates; lng ∈ [-180,180], lat ∈ [-90,90].

**Tier 2 — `ZoneGeometryValidator`, inside the write transaction.**

| Rule | Check |
|---|---|
| Validity | `ST_IsValidDetail` — returns reason **and location**, so the editor can highlight the offending vertex |
| Non-degenerate | `ST_Area > 0` (polygons) |
| Containment | `ST_CoveredBy(region, parent_service_area)` |
| Non-overlap | `boundary && :candidate::geography` (GiST prefilter on the existing geography index) **then** `ST_Relate(boundary::geometry, :candidate::geometry, 'T********')`, against **all active regions globally**, excluding self |
| Office placement | office point `ST_CoveredBy` its **service area** |
| Active parent | verified under the locks in §3 |

- **`ST_CoveredBy` not `ST_Within`** — a region whose edge coincides with the service-area edge is legitimate;
  `ST_Within` would reject it.
- **`ST_Relate(...,'T********')` not `ST_Overlaps`** — tests whether *interiors* intersect, so adjacent regions
  may share an edge (that is how you tile a city) while any real area overlap **including full containment** is
  caught, which plain `ST_Overlaps` would miss.
- Columns are `GEOGRAPHY`, so topology predicates need explicit `::geometry` casts, matching existing code. The
  `&&` prefilter operand stays `::geography` to hit the existing GiST index.
- **Service areas may overlap each other — deliberate.** `PresenceService` is a boolean `EXISTS` that overlap
  cannot corrupt, and `resolveRegion` filters on region containment.

**Reverse validation.** Shrinking or restoring a **service-area** boundary is blocked if it would leave any
active region or active office outside it (`active_dependents_outside_parent`). Region boundary edits need no
office revalidation, since `home_region_id` carries no geometry.

**Name uniqueness** — case-insensitive, whitespace-trimmed, enforced across **active and inactive** rows:

| Entity | Unique index |
|---|---|
| `service_areas` | `lower(name)` — global |
| `regions` | `(service_area_id, lower(name))` — two cities may each have a "Center" |
| `office_locations` | `(service_area_id, lower(name))` |

**`ZoneErrorCode`** (matching the existing `OrderErrorCode`/`DriverErrorCode` pattern): `invalid_geometry`
(carries `ST_IsValidDetail` reason + location), `geometry_has_holes`, `zero_area`, `too_many_vertices`,
`region_outside_service_area`, `region_overlaps_active_region` (carries the conflicting region's id + name),
`parent_inactive`, `active_children`, `active_dependents_outside_parent`, `office_not_in_service_area`,
`duplicate_name`, `office_has_active_regions`, `revision_conflict`. Every failure carries enough structure for
the editor to highlight rather than print a string.

**IDs:** zones keep **numeric** ids — the documented Rule 5 reference-data exception, consistent with
`/admin/reference` and the shipped `/admin/map/zones`. Offices are keyed by their existing `public_id`.

---

## 3. Concurrency & serialization

Parent row locks close parent/child races but **not** concurrent region writes under different service areas —
and non-overlap is a **set-level** invariant, so two transactions can each pass validation against a pre-commit
snapshot and commit a mutual overlap.

**Every topology-changing transaction takes one fixed-key `pg_advisory_xact_lock()` before validating** —
covering create, geometry update, activation, restore, and parent geometry changes. It releases automatically at
commit/rollback. Zone edits are rare, low-volume admin operations (dozens of rows), so global serialization costs
nothing operationally. Parent `lockForUpdate()` is retained for parent/child ordering and is taken **after** the
advisory lock, giving one consistent lock order everywhere.

---

## 4. Deactivation drain semantics

| Concern | Behaviour |
|---|---|
| Historical orders | Untouched — snapshot rule + §17.19 order-time snapshots. |
| In-flight / awaiting orders | Continue on their snapshotted region/office; deactivation never rewrites a live order. |
| Drivers already online | **Not** forced offline. `PresenceService` gates *going* online only; nothing re-checks an already-online driver. Accepted and documented, not silently ignored. |
| `driver_region` rows | Retained; assignment never consulted them. |
| Quotes already issued | **Not honoured.** Re-verified at creation: a changed region or fee → **409 `QuoteMismatch`**; an inactive region → `pickup_out_of_service_area`. |
| Return routing | Today a deactivated region **still routes returns** (only `o.is_active` is filtered) — fixed in §6. |
| New orders | Cannot price into an inactive region → `pickup_out_of_service_area`. |

---

## 5. Frontend editor (Slice 2)

MapLibre + Terra Draw: select / draw / edit / undo, parent + sibling context, validation highlighting driven by
`ZoneErrorCode` plus the `ST_IsValidDetail` location, and **no Delete UI**.

**Minimal snapping + gap warning.** Terra Draw vertex/edge snapping within a pixel tolerance, plus a
**non-blocking warning when a new region leaves a gap against its neighbours**. Rationale: strict non-overlap
means an admin who cannot click coincident vertices at map zoom will leave slivers — and gaps are *silently
accepted* by validation while creating unpriced dead zones. Overlap is the loud failure and is safe; gaps are the
quiet one and are not.

**Authoring map endpoint** returns **all office points including inactive ones**, each with `service_area_id`,
optional `home_region_id`, `is_active`, and `revision` — distinct from the display-oriented `/admin/map/zones`.

---

## 6. Slice 0 — Boundary-point fix (must precede authoring)

`ST_Contains` excludes points exactly on a boundary. Replace with **`ST_Covers`** plus **deterministic ordering**
— interior match first, then lowest stable id for an exact shared edge — applied consistently across all five
sites.

`ReturnOfficeResolver` additionally gains the missing `r.is_active` and `service_areas.is_active` filters, and
prefers the snapshotted `orders.pickup_office_id` **while that office remains active**, falling back to
deterministic current routing, then nearest active office.

**Keep the two concepts distinct:** `pickup_office_id` is an immutable **financial-attribution snapshot**;
return routing is an **operational** decision that merely uses it as a preference. Routing must never be treated
as authoritative for finance, nor finance re-derived from routing.

**Corrective backfill — now or never.** The §17.19 backfill also used `ST_Contains`, so boundary-point pickups
were left NULL (`unassigned`). Re-resolve those NULL snapshots with `ST_Covers`. **This is only possible before
boundaries become editable** — afterwards, present geometry no longer reflects order-time geometry and the
correction is unrecoverable. It shifts historical finance attribution from "unassigned" to real offices, so it
runs as an explicit, reviewed migration reporting before/after counts.

Scoped as its own slice/PR because it changes existing behaviour in pricing, driver presence, and return routing.
Bundling it into zone CRUD would breach the "zero changes to existing expectations" norm invisibly and make the
change hard to review or revert.

---

## 7. Migration & seeding

- `is_active` database default becomes **false** (new rows inactive). **Backfill preserves each existing row's
  current `is_active`** — "inactive by default" governs new rows only, so live seeded zones are not silently
  switched off.
- Every existing entity gets a **baseline `zone_revisions` row at revision 1**, written **directly in SQL by the
  migration**, not via model hooks.
- Because `DatabaseSeeder` uses `WithoutModelEvents`, revisions are written by an explicit `ZoneRevisionRecorder`
  in the service layer, never relying on `booted()` hooks alone. **Seeders and factories must activate zones
  explicitly**; anything assuming seeded-active must be updated.

**Deterministic office `service_area_id` backfill — never guess spatially** (service areas may overlap):

1. Derive from `office.region_id` → that region's `service_area_id`.
2. Else derive from regions referencing the office via `regions.office_id` — **only if they all resolve to a
   single service area**.
3. Else **fail the migration** and require an explicit operator-supplied mapping. Conflicting or unresolved
   **active** offices must be resolved before the column becomes `NOT NULL`.

**Soft-deleted office normalization:** convert existing soft-deleted rows to `is_active = false`, clear
`deleted_at`, **preserve ids and all references**, remove the `SoftDeletes` trait, and remove now-obsolete
`deleted_at` filters — including the `AND o.deleted_at IS NULL` in `MapZonesController`. If `deleted_at` is to be
dropped, use a **staged deployment**: normalize and stop writing first, drop the column in a later migration.

FK hardening must verify no existing rows violate the new constraints and handle ordering around the circular
`regions.office_id` ↔ `office_locations.home_region_id` pair.

---

## Open questions (carry into planning)

1. Are the trigger-based `zone_revisions` guarantees (same-entity `restored_from_revision_id`, append-only
   `UPDATE`/`DELETE` block, per-entity `GeometryType` check) the right mechanism, or is there a cleaner
   declarative form?
2. Is advisory-lock-then-`lockForUpdate()` the correct lock order, and does it fully close the set-level
   non-overlap window without `SERIALIZABLE`?
3. Is the office-backfill fallback chain exhaustive, and is failing the migration right for unresolved
   **inactive** offices, versus leaving them null until activation?
4. Does the §6 corrective backfill risk changing **already-reported** finance figures, and should it therefore be
   gated behind explicit operator confirmation rather than running automatically?

---

## Out of scope

Full edge-tracing / shared-boundary topology editing; polygons with holes; multi-polygon zones; implementing
§7.3's unimplemented same-region driver matching (tracked separately); admin-created orders.
