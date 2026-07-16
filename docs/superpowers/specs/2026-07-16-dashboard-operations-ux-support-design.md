# Dashboard Operations UX Support — Design

**Date:** 2026-07-16 · **Status:** 📝 design — pending review
**Repo:** `delivary-app` (backend) · **Consumer:** `delivary-dashboard` (admin console)
**Process:** `docs/WORKFLOW.md` · **Log:** `docs/CODEX.md` · **System spec:** `docs/SYSTEM_SPECIFICATION.md`

> A small, additive backend milestone that unblocks two dashboard UX improvements. Two independent slices,
> each its own plan + PR + branch, merged in either order:
>
> - **Slice A — Map Zone GeoJSON:** a cacheable `GET /admin/map/zones` returning service-area + region
>   polygons as one GeoJSON `FeatureCollection`, separate from the 60s-polled `/map/overview`.
> - **Slice B — Human-Readable Order Numbers:** an immutable, unique, alphanumeric `order_number`
>   (`ORD-XXXX-XXXX-C`) surfaced beside the ULID `id` in **every** order-bearing API payload. The ULID
>   `id`/`public_id` remains the key for all routes, authorization, realtime channels, and actions.

**Additive-only:** no existing route keys, auth, or response fields change. Existing tests must stay green with
zero changes to their expectations (per WORKFLOW §7); new fields/endpoints are purely additions.

---

## Slice A — Map Zone GeoJSON

### Goal

Give the dashboard a static, cacheable source of the operational zone polygons (service areas + regions) so a
future "Zones" map overlay can draw them, **without** re-transferring polygons on every 60s `/map/overview`
poll. (The frontend Zones overlay is not yet designed; this ships the backend so it is ready when that lands.)

### Data source (verified)

- `service_areas`: `id`, `name`, `boundary` (`GEOGRAPHY(Polygon,4326)`, GIST-indexed), `is_active`, timestamps.
- `regions`: `id`, `service_area_id` (FK), `office_id` (nullable), `name`, `boundary` (`GEOGRAPHY(Polygon,4326)`,
  GIST-indexed), `is_active`, `base_fee` (decimal), timestamps. `region.office` → `office_locations.public_id`.

Hierarchy: **ServiceArea → many Regions → each Region tied to an Office.**

### Endpoint

`GET /api/admin/map/zones` — `auth:sanctum` + `role:admin` + `staff.password_change_required` (same route group
as `map/overview`, named `map.zones`). Invokable controller `MapZonesController`.

### Response — one GeoJSON `FeatureCollection`

```jsonc
{
  "type": "FeatureCollection",
  "features": [
    { "type": "Feature",
      "geometry": { "type": "Polygon", "coordinates": [[[lng,lat], …]] },
      "properties": { "kind": "service_area", "id": 1, "name": "Tripoli Service Area", "is_active": true } },
    { "type": "Feature",
      "geometry": { "type": "Polygon", "coordinates": [[[lng,lat], …]] },
      "properties": { "kind": "region", "id": 12, "name": "Tripoli Center", "is_active": true,
                      "service_area_id": 1, "base_fee": "3.00",
                      "office": { "id": "<office public_id>", "name": "Tripoli Central" } } }
  ]
}
```

- **`kind`** discriminates the two layers in one collection (one fetch; the frontend filters/layers by kind).
- **Numeric `id`** — reference-data Rule 11 exception (consistent with `/admin/reference.regions[]`). The **only**
  public id in the payload is the region's `office.id` (office `public_id`).
- **All rows emitted** (active + inactive) with `is_active`; the set is small (dozens), and admins may want to
  see draft/inactive zones. The frontend decides what to render.
- Regions carry `service_area_id`, `base_fee`, and the public `office` reference; service areas carry none of these.
- **`office` is nullable** — `regions.office_id` is nullable, so a region with no office emits `"office": null`
  (the property is always present; its value is the `{id,name}` object or `null`).

### Efficiency (locked)

- **Coordinate precision:** geometry via `ST_AsGeoJSON(boundary, 6)` (~0.1 m) — meaningfully smaller payloads.
  **No `ST_Simplify`** (topology simplification would open gaps/overlaps between adjacent polygons).
- **Build in SQL, not by hydrating `Polygon` objects:** one raw query per table
  (`service_areas`; `regions` LEFT JOIN `office_locations`) selecting `ST_AsGeoJSON(boundary, 6)` + the property
  columns; assemble the `FeatureCollection` in PHP (`json_decode` each geometry string into the feature).
- **HTTP conditional caching:** compute a weak **`ETag`** = hash of `count(*)` + `max(updated_at)` across both
  tables; send `ETag` + `Cache-Control: private, must-revalidate`. On `If-None-Match` match → **`304 Not
  Modified`** with an empty body. The frontend uses TanStack Query with a long `staleTime` and gets cheap
  revalidation instead of re-downloading polygons.

### States & errors

Standard admin JSON. No domain errors (read-only). Empty tables → an empty `features` array (still `200` + ETag).

### Testing (Pest)

- Returns a `FeatureCollection`; a service-area feature (`kind`, numeric `id`, `name`, `is_active`) and a region
  feature (adds `service_area_id`, `base_fee`, `office.{id,name}`); geometry is valid GeoJSON Polygon coords.
- A region with `office_id = null` emits `"office": null`.
- Inactive zones are included with `is_active: false`.
- `ETag` present; a matching `If-None-Match` returns `304` and no body; a stale `If-None-Match` returns `200` +
  the collection. **Adding, updating, or deleting a zone changes the `ETag`** (assert the ETag differs after each
  mutation).
- `role:admin` gate: non-admin → `403`; unauthenticated → `401`; **an admin with `must_change_password` →
  `403`** (the `staff.password_change_required` middleware).

### Out of scope (A)

The frontend Zones overlay UI (separate dashboard slice); driver/order geometry; editing zones; clustering.

---

## Slice B — Human-Readable Order Numbers

### Goal

Add an immutable, unique, human-friendly `order_number` that staff can read aloud, type, and search — displayed
across every order surface — while the ULID `public_id` stays the machine key for routes, auth, realtime
channels, and actions. `tracking_token` is itself a ULID (opaque public-tracking token) and is unsuitable as a
readable reference; a truncated ULID would not be guaranteed unique — hence a dedicated field.

### Format — `ORD-XXXX-XXXX-C`

- **Prefix** `ORD` (fixed) — identifies the entity type (keeps future `SET`/`PAY` references unambiguous).
- **Body** = **8 random Crockford Base32 symbols** in two dash-separated groups of 4. Crockford's alphabet omits
  `I L O U` (and, on read, maps them to `1/1/0/…`), so the body avoids the characters humans confuse.
  `32^8 ≈ 1.1 × 10^12` combinations — large, but **not** a substitute for a uniqueness check: the generator
  verifies-and-retries (see Generation) and a UNIQUE index is the hard guarantee.
- **Check character `C`** = **ISO 7064 MOD 37,36** over the 8 body characters (base-36 values: `0–9`→`0–9`,
  `A–Z`→`10–35`; modulus `M = 37`, radix `r = 36`). The check character is always one of `0–9 A–Z` (never a
  37th symbol), so the whole value stays alphanumeric. It catches single-character transcription errors when
  staff type the number into search. The `ORD-` prefix and dashes are **formatting only** — excluded from the
  checksum input.
- **Alphabet & parsing asymmetry (important):** Crockford aliasing applies **only to the 8 body characters** —
  on input, normalize the body with `I/L → 1`, `O → 0`, and **reject `U`** (and any other non-Crockford symbol)
  in a body position. The **check character** is a literal ISO 7064 MOD 37,36 output over `0–9 A–Z` and **may
  itself be `I`, `L`, `O`, or `U`** — do **not** Crockford-normalize the check character; compare it literally.
- **Canonical stored form:** upper-case, dash-formatted `ORD-XXXX-XXXX-C` — 12 alphanumeric chars (`ORD` + 8
  body + 1 check) plus 3 dashes = 15-char string. Example: `ORD-7K3M-9Q2D-8`.

> The exact ISO 7064 MOD 37,36 recurrence + a table of test vectors is pinned in the implementation plan with a
> reference implementation and unit tests (generate → validate round-trip; a known-good vector; a single-char
> corruption is rejected).

### Generation

- A small pure service **`OrderNumberGenerator`**:
  - `generate(): string` — random 8-symbol Crockford body → compute check char → format → **loop with an
    existence check** (`Order::where('order_number', …)->exists()`), cap ~5 attempts.
  - `isValidOrderNumber(string): bool` — validates a **complete** `ORD-XXXX-XXXX-C`: format + body alphabet
    (reject `U`) + recompute the ISO 7064 check character and compare. **Distinct from search normalization**
    (see Search) — this is whole-number validation, not partial-term matching.
- Called from `Order::booted()` `creating` beside `public_id`/`tracking_token` (only when empty).
- **Uniqueness — two layers, no false assumptions:** (1) the generator's existence-check loop is the primary
  mechanism; (2) a **UNIQUE index** on `order_number` is the hard guarantee. `DB::transaction()` retries only on
  **deadlocks**, *not* unique-constraint violations — so the create path adds an **explicit** retry: catch the
  `order_number` unique-violation `QueryException` at insert, regenerate, retry (cap ~3). The generation space
  is large but this check-and-retry is required at scale, not optional.
- **Random, not sequential** — opaque and non-enumerable; a sequential number would leak order volume to anyone
  who sees one (customers, competitors).

### Schema / migration

1. Add `order_number` `string(20)` **nullable** (no constraint yet).
2. **Backfill** existing orders: chunk through all rows, assign a unique `order_number` via the generator
   (collision-safe within the run), save without touching `updated_at` semantics that other logic depends on
   (use a targeted update).
3. Add **`UNIQUE` index** on `order_number` and set the column **`NOT NULL`**.

(Two migrations if cleaner: add-nullable + backfill data migration, then constrain — avoids a long lock on a
large table. Chunked backfill inside one migration is acceptable at current volume; the plan picks one.)

### Immutability

`order_number` is generated once and never updated: excluded from any `fillable`/update path; no service writes
it after creation. (No DB trigger needed — enforced in the model + code review.)

### Search

Add `order_number` to admin order search (`AdminListOrdersRequest` / `OrderController@index`) via a dedicated
**`normalizeSearchTerm(string): string`** — **separate from `isValidOrderNumber`**. It handles **partial** input:
strip dashes/non-alphanumerics and upper-case, and accept body-only or missing-check terms. It does **not**
validate the checksum (a partial term has none). Match the normalized term against the normalized stored value
(dash-stripped, upper-cased) via `ILIKE`/prefix, so `ord7k3m9q2d`, `7K3M-9Q2D`, `ORD-7K3M-9Q2D-8`, and a bare
`7K3M` all find the order. **The exact SQL (a normalized generated column/expression index vs an `ILIKE` on a
`replace(...)` expression) is pinned in the plan.** Existing search terms (public_id, tracking_token, names,
phones) are unchanged and still work.

### Surfaced everywhere — the rule

> **Every human-facing order representation or summary — a resource that renders an order, or a nested/summary
> order reference a person reads — MUST emit `order_number` beside the order `id`.** **Machine-only correlation
> payloads** (internal event envelopes carrying an order id purely to correlate, never displayed) are excluded.
> Frontends display `order_number` but continue to use `id` for all routes and actions.

Concretely (audited), add `order_number` to:

**Order-representing resources**
- `Order/AdminOrderResource` (admin list + detail)
- `Order/OrderResource` (customer)
- `Order/DriverOrderResource` (driver)
- `Order/OfficeOrderResource` (office)
- `Order/BroadcastOrderResource` (realtime)
- `Order/GuestTrackingResource` (guest tracking-token lookup)
- `Broadcast/OrderForPartiesResource` (realtime parties view)

**Payloads that reference an order (nested)**
- `Driver/DriverStrikeResource` — the `order` reference (add `order_number` beside the order id)
- `Admin/UserDetailResource` — the user's order summaries
- `Settlement/SellerEarningResource`, `Settlement/SellerPayoutResource`,
  `Settlement/SettlementPreviewResource`, `Settlement/SettlementResource` — each order line/reference

_(Excluded: `Broadcast/DriverForOrderResource` — it carries **no** order reference, only driver fields.
`QuoteResource` — no order exists yet. `OfficeInventoryResource` / `OrderStatusLogResource` — reviewed during
implementation; add only where an order reference is actually surfaced to a human.)_

**Activity / reporting services**
- `Reporting/OverviewMetricsService` — the overview activity feed (beside `order_public_id`)
- `Reporting/StaffActivityService` — staff activity entries referencing an order
- `Reporting/FinanceReportService` — finance activity/report lines referencing an order

Each addition places `order_number` next to the existing order id; where a resource loads the order relation,
ensure `order_number` is on the selected columns / eager load so it is never emitted as `null` for a present
order. (`OfficeInventoryResource` and `OrderStatusLogResource` are reviewed during implementation — add
`order_number` only if/where they surface an order reference; `QuoteResource` has no order yet, so it is excluded.)

### States & errors

Additive fields only; no new error codes. Backfill runs offline (migration). Search additions never break
existing queries.

### Testing (Pest)

- **Generator:** `generate()` returns `ORD-XXXX-XXXX-C` matching the format; the check char validates
  (ISO 7064 MOD 37,36) against a known vector; a single-character corruption fails `isValidOrderNumber`; a `U`
  in a body position is rejected; a check char of `I/L/O/U` is accepted literally (not Crockford-normalized);
  two generations differ; the collision-retry path is exercised (seam a pre-seeded value); the explicit
  unique-violation retry regenerates on a simulated insert collision.
- **Creation:** creating an order sets a unique, valid `order_number`; it is stable across updates (immutable).
- **Backfill:** after migrating, every existing order has a unique valid `order_number`.
- **Search:** `?search=<order_number>` finds the order with and without dashes and case-insensitively; existing
  search terms still work.
- **Resources:** a representative assertion per resource/service in the list above emits `order_number` beside
  the order id (primary + at least one nested reference, e.g. `DriverStrikeResource`, and the overview activity
  feed).
- Full existing suite stays green with **no changes to existing expectations** (additive proof).

### Out of scope (B)

Changing any route key/channel to `order_number`; customer-facing tracking-URL redesign; rendering/formatting in
the dashboard (frontend follow-up); numbering non-order entities (settlements/payouts) — the `ORD` prefix simply
leaves room for them later.

---

## Slice boundaries & sequencing

- **Ownership:** **Claude → Slice B** (order numbers), **Codex → Slice A** (map zones). Independent, disjoint
  files → parallel branches/PRs, merge in either order. Recommended order: **B first** (broad, immediate value
  across every order surface), then **A**.
- **Slice A** = `MapZonesController` + route + tests. **Slice B** = migration + `OrderNumberGenerator` + model
  hook + search + the resource/service edits + tests.
- Both follow WORKFLOW: branch off `main`, TDD with Pint + Pest, cross-review, merge to backend `main`, then
  the dashboard consumes the new fields/endpoint in a separate frontend milestone.

## Open items for the plan

- Pin the exact ISO 7064 MOD 37,36 recurrence + test vectors (reference implementation).
- Decide one-migration vs two-migration backfill (lock duration at current table size).
- Confirm, file-by-file, which nested/settlement/reporting payloads currently emit an order reference (add
  `order_number` only where an order id is actually surfaced).
- Choose the `ST_AsGeoJSON` assembly (raw `DB::select` vs query builder) and the exact `ETag` hash inputs.
