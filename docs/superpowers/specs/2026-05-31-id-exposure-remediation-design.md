# Internal-ID Exposure Remediation — Design

**Date:** 2026-05-31
**Owner:** Claude (single worktree, branch `claude/id-exposure-remediation`)
**Origin:** Post-Staff-CRUD security & convention audit (see §0). Closes the gaps where the codebase violates Critical Rule 11 ("Never expose internal `id` in URLs or API responses — use `public_id`").

---

## 0. Background — the audit

A codebase-wide audit (against `docs/CLAUDE.md` Critical Rule 11 and the "Auto-increment IDs in URLs" rejected decision) found that 12 models correctly carry a ULID `public_id`, but a cluster of routes and JsonResources still leak internal auto-increment ids. Injection, mass-assignment, and authorization were all clean — the only systemic finding is internal-id exposure, concentrated in the driver domain plus a client-facing order leak and several staff/admin resources.

Severity tiers from the audit:

- **HIGH** — `DriverProfile` and `DriverDocument` are addressed by internal `id` in URLs (enumeration surface); `OrderResource` (consumer-facing) leaks an internal `office_id`.
- **MEDIUM** — staff/admin resources emit raw internal FK ids (`user_id`, `office_id`, `driver_id`, `actor_id`, `*_by_*_id`) and a raw `metadata` blob that carries internal office ids.
- **Accepted exception** — reference/lookup tables (`regions`, `service_areas`) intentionally have no `public_id`.

## 1. Goals / non-goals

**Goals:**
- No internal auto-increment id appears in any URL or API response body (except the documented reference-table exception).
- Drivers and driver documents are addressed by stable, non-enumerable identifiers.
- Related entities are represented by their `public_id` (nested `{id, name}` where a display name is useful), preserving audit attribution.
- No N+1 regressions: every newly-nested relation is eager-loaded.

**Non-goals:**
- No new functional endpoints or business logic.
- No change to authorization *rules* (only to how the bound object is resolved).
- No `public_id` added to reference/lookup tables.
- No broader refactoring beyond what these fixes touch.

## 2. Decisions (locked during brainstorming)

1. **Drivers are addressed by `User.public_id`** (Option A), not a new `DriverProfile` ULID. A driver *is* a `User` with a `DriverProfile`; the User ULID is the driver's API identity. No migration.
2. **FK exposures become nested `{id, name}` objects** using the related entity's `public_id` (Option 1).
3. **Scope = HIGH + MEDIUM** (full remediation now, while there is no production client and the change is free).
4. **Audit-attribution fields are kept**, reshaped to nullable `{id, name}` (not omitted) — attribution matters for dispute resolution.
5. **`DriverProfilePolicy` keeps operating on `DriverProfile`** — changing the URL identifier does not change the policy domain object.
6. **`DriverDocument` is addressed by its `DriverDocumentType` enum** (natural key, unique per driver), always scoped to the driver in the query.

## 3. Driver addressing (HIGH #1, #2)

### 3.1 Routes (9 parameterized driver routes)
Rebind `{driverProfile}` → `{driverUser:public_id}`:

| Group | Routes |
|---|---|
| `/api/office/drivers` (4) | `{driverUser}/verify-phone`, `{driverUser}/submit`, `{driverUser}/documents` (POST), `{driverUser}/documents/{documentType}` (DELETE) |
| `/api/admin/drivers` (5) | `{driverUser}` (GET show), `{driverUser}/approve`, `{driverUser}/reject`, `{driverUser}/suspend`, `{driverUser}/reinstate` |

Non-parameterized routes (`index`, `lookup`, `onboard`) are unaffected.

### 3.2 Controllers (9 methods)
Signatures change `DriverProfile $driverProfile` → `User $driverUser`; the profile is resolved inside the method, then the **existing** authorization is applied to the resolved `$profile` (the policy ability and its `DriverProfile` argument are unchanged — only the bound URL object changes).

The two controllers authorize differently today, and both mechanisms are preserved as-is:
- **Office** (`DriverOnboardingController::verifyPhone/submit`, `DriverDocumentController::store/destroy`) already call `$request->user()->can('manageInOffice', $driverProfile)` for office-scoped access → keep that call, now passing the resolved `$profile`:
  ```php
  public function submit(Request $request, User $driverUser): JsonResponse
  {
      $profile = $driverUser->driverProfile;
      abort_unless($profile !== null, 404);

      if (! $request->user()->can('manageInOffice', $profile)) {
          abort(403);   // existing behavior, unchanged
      }
      // ... unchanged body operating on $profile
  }
  ```
- **Admin** (`AdminDriverController::show/approve/reject/suspend/reinstate`) has **no** policy call today — it relies on the `role:admin` route middleware (admins are global). That stays the same; only the binding/resolution changes:
  ```php
  public function suspend(Request $request, User $driverUser): JsonResponse
  {
      $profile = $driverUser->driverProfile;
      abort_unless($profile !== null, 404);
      // ... unchanged body operating on $profile (no policy call, role:admin gates the route)
  }
  ```

Affected: `AdminDriverController` (show, approve, reject, suspend, reinstate), `DriverOnboardingController` (verifyPhone, submit), `DriverDocumentController` (store, destroy).

### 3.3 Document deletion — scoped natural key
`DriverDocument` is bound by `DriverDocumentType` (implicit enum route binding), but the lookup is **explicitly scoped to the driver** so a mismatched binding cannot reach another driver's document:

```php
public function destroy(Request $request, User $driverUser, DriverDocumentType $documentType): Response
{
    $profile = $driverUser->driverProfile;
    abort_unless($profile !== null, 404);

    if (! $request->user()->can('manageInOffice', $profile)) {
        abort(403);   // existing behavior, unchanged
    }

    $document = DriverDocument::query()
        ->where('driver_id', $driverUser->id)
        ->where('document_type', $documentType->value)
        ->firstOrFail();
    // ... unchanged removal
}
```

The `store` route (POST `/documents`) carries the type in the request body and needs no document param.

## 4. Resource reshaping

### 4.1 Driver resources
- **`DriverProfileResource`**: `'id' => $this->user->public_id`; replace `user_id` and `office_id` with nested `user:{id,name}` and `office:{id,name}` (office id = `public_id`).
- **`DriverProfileFullResource`**:
  - `'id' => $this->user->public_id`.
  - **Remove the in-resource query** `DriverDocument::where('driver_id', $this->user_id)->get()`. Add a `documents` relationship on `DriverProfile` (or use the existing `User::driverDocuments`) and **eager-load** it; render via `DriverDocumentResource::collection($this->whenLoaded(...))`.
  - Fix the nested `office` to expose `public_id` (currently leaks `office->id`); drop the top-level `office_id`.
  - `approved_by_admin_id` → `approved_by:{id,name}` (nullable, user `public_id`).
- **`DriverDocumentResource`**: drop internal `id`; the document is keyed by `document_type` (already present). No internal id in output.

### 4.2 Client-facing order leak
- **`OrderResource`**: replace `'office_id' => $o->return_office_id` with `'return_office' => $office ? ['id' => $office->public_id, 'name' => $office->name] : null`. Eager-load the return office relation on the feeding query.

### 4.3 Staff/admin order resources
- **`AdminOrderResource`**: sender/receiver/driver `user_id` → nested `{id,name}` (user `public_id`); `guest_id` → guest `public_id`.
- **`OfficeOrderResource`**: `sender` `user_id` → `{id,name}`; return `office_id` → `office:{id,name}`; `driver_id` → `{id,name}`.

### 4.4 Office inventory + audit attribution
- **`OfficeInventoryResource`**: `office_id` → `office:{id,name}`; `received_by` / `retrieved_by` / `abandoned_by` → nullable `{id,name}` (user `public_id`). (Reshaped, not omitted — audit attribution retained.)

### 4.5 `OrderStatusLogResource` — actor + metadata sanitization (HIGH)
- `actor_id` → `actor:{id,name}` nullable (user `public_id`).
- **`metadata` must NOT be emitted verbatim.** Redirect-return logs carry internal `previous_office_id` / `new_office_id`; other log types may carry driver/user/order internal ids. Introduce a **metadata sanitizer** that:
  1. Translates known internal-id keys to public equivalents (e.g., `previous_office_id` → `previous_office:{id,name}`, `new_office_id` → `new_office:{id,name}`, any `driver_id`/`user_id`/`order_id` → the corresponding `public_id`).
  2. Passes through known-safe non-id keys.
  3. **Drops unrecognized `*_id` keys by default** (fail-closed), so a future writer can't silently leak a new internal id.
- **Planning task:** enumerate every site that writes `order_status_logs.metadata` (grep `OrderStatusLog::create` / `metadata =>`) to build the complete key inventory before implementing the sanitizer.

## 5. Region / ServiceArea — documented exception (no code)
Add a note to `docs/CLAUDE.md` (Key Conventions → Public IDs) and reference it here: **reference/lookup tables (`regions`, `service_areas`) intentionally have no `public_id`**; their `id` is stable, non-sensitive geographic reference data (not user/business records), so exposing it is an accepted, deliberate exception to Critical Rule 11. This prevents a future contributor from "fixing" it needlessly.

## 6. Performance — N+1 prevention
Every newly-nested relation must be eager-loaded at the query/controller layer feeding the resource:
- driver listings/detail → `user`, `office`, `documents`
- order resources → `sender`, `receiver`, `driver`, `returnOffice`
- office inventory → `office`, `receivedByStaff`, `retrievedByStaff`, `abandonedByAdmin`
- status log → `actor` (+ entities referenced by sanitized metadata, resolved in a batch where feasible)

Resources use `whenLoaded()`/`relationLoaded()` guards; no queries inside `toArray()`.

## 7. Testing
TDD per affected area — adjust assertions to expect ULID URL params and nested `{id,name}` shapes, confirm RED, implement, confirm GREEN:
- Driver onboarding feature tests (office routes by `{driverUser:public_id}`, document delete by type).
- Admin driver lifecycle feature tests (show/approve/reject/suspend/reinstate by user public_id).
- Resource-shape assertions for `OrderResource`, `AdminOrderResource`, `OfficeOrderResource`, `OfficeInventoryResource`, `OrderStatusLogResource`, `DriverProfileResource`, `DriverProfileFullResource`, `DriverDocumentResource`.
- A focused test that `OrderStatusLogResource` metadata contains **no** internal `*_id` keys for a redirect-return log (regression guard for the sanitizer).
- Full Pest suite + `scripts/orders-e2e.php` (32 scenarios) green at the end.

## 8. Process
- Single worktree (Claude), branch `claude/id-exposure-remediation` from `main`.
- Subagent-driven execution per the implementation plan; normal PR flow.
- No parallel split — one cohesive refactor, smaller surface than Staff CRUD.

## 9. Out of scope
- Phone-number masking (Critical Rule 12) — separate future pass.
- Promoting Tinker smoke scripts to Pest (separate "test infrastructure" item).
- Any new endpoints, fields, or business logic.

---

**End of design.**
