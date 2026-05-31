# Internal-ID Exposure Remediation — Design

**Date:** 2026-05-31
**Owner:** Claude (single worktree, branch `claude/id-exposure-remediation`)
**Origin:** Post-Staff-CRUD security & convention audit (see §0). Closes the gaps where the codebase violates Critical Rule 11 ("Never expose internal `id` in URLs or API responses — use `public_id`").
**Review:** Audit reviewed by Codex; this revision incorporates the confirmed additional response leaks, the inbound request-id contract, and a write-time metadata-summary approach.

---

## 0. Background — the audit

A codebase-wide audit (against `docs/CLAUDE.md` Critical Rule 11 and the "Auto-increment IDs in URLs" rejected decision) found that 12 models correctly carry a ULID `public_id`, but a cluster of routes, JsonResources, **and inbound FormRequests** still use internal auto-increment ids. Injection, mass-assignment, and authorization were all clean — the only systemic finding is internal-id exposure/coupling.

A follow-up review (Codex) confirmed two gaps the first pass missed:
- Additional **outbound** leaks in settlement/payout resources (nested `office?->id`), missed because the original sweep didn't match the `?->` null-safe accessor.
- The **inbound** side: several FormRequests *accept* internal ids (`office_id`, `driver_id`), which is the same coupling in reverse — a client can only submit an internal id if it was leaked to it. A contract is only consistent if both directions use `public_id`.

Severity tiers:

- **HIGH** — `DriverProfile`/`DriverDocument` addressed by internal `id` in URLs; `OrderResource` (consumer-facing) leaks an internal `office_id`.
- **MEDIUM** — staff/admin resources emit raw internal FK ids and a raw `metadata` blob carrying internal office ids; settlement/payout resources emit nested internal `office?->id`; inbound FormRequests accept internal `office_id`/`driver_id`.
- **Accepted exception** — reference/lookup tables (`regions`, `service_areas`) intentionally have no `public_id`.
- **Deferred (documented)** — websocket channel names (`user.{id}`, `driver.{id}`) use internal ids; handed to the Real-time milestone (§10).

## 1. Goals / non-goals

**Goals:**
- No internal auto-increment id appears in any URL or API response body (except the documented reference-table exception).
- No API endpoint *accepts* an internal id in a request param; inbound ids are `public_id`, resolved to internal server-side.
- Drivers and driver documents are addressed by stable, non-enumerable identifiers.
- Related entities are represented by their `public_id` (nested `{id, name}` where a display name is useful); audit attribution preserved.
- No N+1 regressions: every newly-nested relation is eager-loaded; no queries inside resources.

**Non-goals:**
- No new functional endpoints or business logic.
- No change to authorization *rules* (only how the bound/resolved object is obtained).
- No `public_id` added to reference/lookup tables.
- No websocket channel-name changes (deferred — §10).
- No broader refactoring beyond what these fixes touch.

## 2. Decisions (locked during brainstorming + review)

1. **Drivers are addressed by `User.public_id`** (no new `DriverProfile` ULID; no migration).
2. **Outbound FK exposures become nested `{id, name}`** using the related entity's `public_id`.
3. **Inbound request ids become `public_id`**, validated `exists:<table>,public_id` and resolved to the model/internal id in the FormRequest→DTO or service layer.
4. **Scope = HIGH + MEDIUM**, full remediation in **one cohesive plan/PR** (slicing would create a half-migrated window where outbound is public but inbound still internal).
5. **Audit-attribution fields kept**, reshaped to nullable `{id, name}`.
6. **`DriverProfilePolicy` keeps operating on `DriverProfile`**; controllers resolve the user, then authorize the profile.
7. **`DriverDocument` addressed by its `DriverDocumentType` enum** (natural key, unique per driver), always scoped to the driver in the query.
8. **Metadata: write-time public summaries + read-time allowlist** (§5) — no read-time DB lookups, fail-closed on unknown `*_id` keys.
9. **Realtime channel-name ids deferred** to the Real-time milestone with an explicit written requirement (§10).

## 3. Driver addressing (HIGH #1, #2)

### 3.1 Routes (9 parameterized driver routes)
Rebind `{driverProfile}` → `{driverUser:public_id}`:

| Group | Routes |
|---|---|
| `/api/office/drivers` (4) | `{driverUser}/verify-phone`, `{driverUser}/submit`, `{driverUser}/documents` (POST), `{driverUser}/documents/{documentType}` (DELETE) |
| `/api/admin/drivers` (5) | `{driverUser}` (GET show), `{driverUser}/approve`, `{driverUser}/reject`, `{driverUser}/suspend`, `{driverUser}/reinstate` |

Non-parameterized routes (`index`, `lookup`, `onboard`) are unaffected.

### 3.2 Controllers (9 methods)
Signatures change `DriverProfile $driverProfile` → `User $driverUser`; resolve the profile inside, then apply the **existing** authorization to the resolved `$profile` (policy ability + `DriverProfile` argument unchanged — only the bound URL object changes). The two controllers authorize differently today; both mechanisms are preserved:

- **Office** (`DriverOnboardingController::verifyPhone/submit`, `DriverDocumentController::store/destroy`) already call `$request->user()->can('manageInOffice', $driverProfile)` and return a **structured `WRONG_OFFICE` JSON** on failure (NOT a bare `abort(403)`) — preserve that exact shape:
  ```php
  public function submit(Request $request, User $driverUser): JsonResponse
  {
      $profile = $driverUser->driverProfile;
      abort_unless($profile !== null, 404);
      if (! $request->user()->can('manageInOffice', $profile)) {
          return response()->json([
              'error' => DriverErrorCode::WrongOffice->value,
              'message' => 'Driver belongs to a different office.',
          ], DriverErrorCode::WrongOffice->httpStatus());   // existing shape, unchanged
      }
      // ... unchanged body operating on $profile
  }
  ```
- **Admin** (`AdminDriverController::show/approve/reject/suspend/reinstate`) has **no** policy call today — `role:admin` route middleware gates it. That stays; only binding/resolution changes:
  ```php
  public function suspend(Request $request, User $driverUser): JsonResponse
  {
      $profile = $driverUser->driverProfile;
      abort_unless($profile !== null, 404);
      // ... unchanged body operating on $profile
  }
  ```

### 3.3 Document deletion — scoped natural key
`DriverDocument` is bound by `DriverDocumentType` (implicit enum route binding), but the lookup is **explicitly scoped to the driver**:

```php
public function destroy(Request $request, User $driverUser, DriverDocumentType $documentType): Response
{
    $profile = $driverUser->driverProfile;
    abort_unless($profile !== null, 404);
    if (! $request->user()->can('manageInOffice', $profile)) {
        return response()->json([
            'error' => DriverErrorCode::WrongOffice->value,
            'message' => 'Driver belongs to a different office.',
        ], DriverErrorCode::WrongOffice->httpStatus());   // existing shape, unchanged
    }
    $document = DriverDocument::query()
        ->where('driver_id', $driverUser->id)
        ->where('document_type', $documentType->value)
        ->firstOrFail();
    // ... unchanged removal
}
```
The `store` route (POST `/documents`) carries the type in the body and needs no document param.

## 4. Outbound resource reshaping

### 4.1 Driver resources
- **`DriverProfileResource`**: `'id' => $this->user->public_id`; `user_id`/`office_id` → nested `user:{id,name}` / `office:{id,name}` (office id = `public_id`).
- **`DriverProfileFullResource`**:
  - `'id' => $this->user->public_id`.
  - **Remove the in-resource query** `DriverDocument::where('driver_id', $this->user_id)->get()`. Add a `documents` relationship on `DriverProfile` (driver documents keyed by `driver_id` = the user id) and **eager-load** it; render via `DriverDocumentResource::collection($this->whenLoaded('documents'))`.
  - Fix nested `office` to expose `public_id` (currently leaks `office->id`); drop top-level `office_id`.
  - `approved_by_admin_id` → `approved_by:{id,name}` (nullable, user `public_id`).
- **`DriverDocumentResource`**: drop internal `id`; keyed by `document_type`.

### 4.2 Client-facing order leak (HIGH #3)
- **`OrderResource`**: replace `'office_id' => $o->return_office_id` with `'return_office' => $o->returnOffice ? ['id' => $o->returnOffice->public_id, 'name' => $o->returnOffice->name] : null`. Eager-load `returnOffice`.

### 4.3 Staff/admin order resources
- **`AdminOrderResource`**: sender/receiver/driver `user_id` → `{id,name}` (user `public_id`); `guest_id` → guest `public_id`.
- **`OfficeOrderResource`**: `sender` `user_id` → `{id,name}`; return `office_id` → `office:{id,name}`; `driver_id` → `{id,name}`.

### 4.4 Office inventory + audit attribution
- **`OfficeInventoryResource`**: `office_id` → `office:{id,name}`; `received_by` / `retrieved_by` / `abandoned_by` → nullable `{id,name}` (user `public_id`). Reshaped, not omitted.

### 4.5 Settlement / payout resources (Codex-confirmed leaks)
Nested office objects currently use the internal id (`'id' => $this->office?->id`). Change to `public_id`:
- **`SettlementResource`** (line ~22), **`SellerPayoutResource`** (line ~18), **`AdminSellerPayoutResource`** (line ~23): nested `office` → `['id' => $this->office?->public_id, 'name' => $this->office?->name]`.
- Confirm no other internal id leaks in `SettlementPreviewResource` / `SellerEarningResource` (audit showed only `order_id => public_id`, already correct).

### 4.6 `OrderStatusLogResource` — actor + metadata (HIGH)
- `actor_id` → `actor:{id,name}` nullable (user `public_id`).
- `metadata` handled per §5.

### 4.7 Controller-built JSON leaks (Codex-confirmed)
Two responses are built inline in controllers (not via a Resource) and leak internal ids:
- **`Api/Driver/RegionController::index`** (line ~34): returns `'office_id' => $profile->office_id` (+ separate `office_name`). Consolidate to `'office' => ['id' => $profile->office?->public_id, 'name' => $profile->office?->name]`. Client-facing (driver's own regions).
- **`Api/Driver/AccountController`** (line ~26): returns **raw transaction models** via `->get(['id', ...])` — leaks the internal transaction `id` and violates the "always use a JsonResource" rule. Add **`DriverAccountTransactionResource`** that **omits `id`** (the `driver_account_transactions` table has no `public_id`; transactions are not individually addressable) and exposes `bucket`, `amount`, `reason`, `balance_after`, `created_at`. Wrap the response with it.
- **`Api/Me/Settlement/ShowEarningsController`** (line ~27): returns `'seller_id' => $user->id` (internal). The caller *is* the seller (`$request->user()`), so **omit `seller_id`** (redundant) — or expose `$user->public_id` if a value is wanted. (Found in the controller-inline-JSON completeness sweep; missed by both the original audit and the first review.)

## 5. Metadata sanitization (write-time summaries + read-time allowlist)
Raw `order_status_logs.metadata` must never be emitted verbatim (redirect-return logs carry `previous_office_id`/`new_office_id`; other log types may carry driver/user/order internal ids). Approach, in two layers:

1. **Write-time public summaries (going forward):** at each site that writes `metadata`, also store `public_id`-based summary keys (e.g., `previous_office_public_id`, `new_office_public_id`). This is the source of truth for the API; no read-time lookup needed.
2. **Read-time allowlist (the security boundary, covers legacy rows too):** `OrderStatusLogResource` passes `metadata` through a sanitizer that
   - keeps an **allowlist** of known-safe keys (the public summaries + non-id keys),
   - **drops any unrecognized `*_id` key** (fail-closed), so a future writer cannot silently leak a new internal id,
   - performs **no database queries** (relies solely on the stored summaries — legacy rows simply lose the dropped internal-id keys from the API, data remains in DB for forensics).

**Planning task:** enumerate every site writing `order_status_logs.metadata` (grep `OrderStatusLog::create` / `'metadata' =>`) to build the allowlist + add the public-summary keys.

## 6. Inbound request-id contract (Codex-confirmed)
Endpoints must accept `public_id`, not internal ids. For each, the FormRequest validates `exists:<table>,public_id` and the value is resolved to the model/internal id in the request's `toInput()`/DTO or the service:

| FormRequest | Field today | Change to |
|---|---|---|
| `CreateStaffRequest` | `office_assignments.*.office_id` (int, exists id) | `office_public_id` (exists `office_locations,public_id`) |
| `AttachOfficeAssignmentRequest` | `office_id` (int) | `office_public_id` |
| `PreregisterDriverRequest` | `office_id` (int) | `office_public_id` |
| `AdminAssignOrderRequest` | `driver_id` (exists `users,id`) | `driver_public_id` (exists `users,public_id`) |
| `RedirectReturnRequest` | `office_id` (int) | `office_public_id` |
| `ListSettlementsRequest` | `office_id` filter (int) | `office_public_id` (resolve, then filter by internal id) |
| `ListSellerPayoutsRequest` | `office_id` filter (int) | `office_public_id` |
| **Admin driver index** (`AdminDriverController::index`, line ~31) | `office_id` query filter (int, read via `request()`) | `office_public_id` via a **dedicated `IndexDriverRequest`** FormRequest; resolve once, then filter |
| **Staff index** (`StaffController::index`, line ~39) | `office_id` query filter (int, read via `request()`) | `office_public_id` via a **dedicated `IndexStaffRequest`** FormRequest; resolve once, then filter |

- Both index endpoints currently read filters straight off `request()` with no FormRequest. Introduce dedicated index FormRequests so the `office_public_id` filter is validated (`exists:office_locations,public_id`) and resolved once before the query (also aligns them with the "FormRequest per endpoint" rule).
- Resolution helper pattern: `OfficeLocation::where('public_id', $publicId)->firstOrFail()` (or `value('id')`), done once at the request/DTO boundary so services keep receiving typed models/ints.
- **`UpdateRegionsRequest.region_ids` stays internal** — `regions` is a documented `public_id`-exempt lookup table.
- These are **breaking request-contract changes** — acceptable now (no production client); note them in the PR description.

## 7. Region / ServiceArea — documented exception (no code)
Add a note to `docs/CLAUDE.md` (Key Conventions → Public IDs): reference/lookup tables (`regions`, `service_areas`) intentionally have no `public_id`; their `id` is stable, non-sensitive geographic reference data, so exposing/accepting it is an accepted, deliberate exception to Critical Rule 11.

## 8. Performance — N+1 prevention
Eager-load every newly-nested relation at the query/controller layer (actual relationship names):
- driver listing (`AdminDriverController::index`) → `user`, `office`
- driver full detail (`DriverProfileFullResource`) → `user`, `office`, **`approvedBy`** (the admin who approved → `approved_by:{id,name}`), and **`documents.driver.media`** — `DriverDocumentResource` reads `$this->driver` and `$user->getFirstMedia(...)` (Spatie), so the document's driver **and** that driver's media must be eager-loaded to avoid N+1 when rendering the document collection
- order resources → `sender`, `receiverUser`, `receiverGuest`, `driver`, `returnOffice`
- office inventory → `office`, `receivedByStaff`, `retrievedByStaff`, `abandonedByAdmin` (confirm exact names in planning)
- settlement/payout → `office` (+ existing `orders`)
- driver regions (`RegionController`) → `office`
- status log → `actor` (metadata uses stored public summaries, no relation load)

Resources use `whenLoaded()`/`relationLoaded()` guards; no queries inside `toArray()`.

## 9. Testing
TDD per affected area — assert new URL params, nested `{id,name}` shapes, and public-id request acceptance; RED → implement → GREEN:
- Driver onboarding + admin driver lifecycle feature tests (URLs by `{driverUser:public_id}`, document delete by type).
- Inbound contract tests: each changed endpoint accepts `*_public_id` and 422s on a bare internal id.
- Resource-shape assertions for every resource in §4.
- A focused regression test: `OrderStatusLogResource` metadata for a redirect-return log contains **no** internal `*_id` key.
- Full Pest suite + `scripts/orders-e2e.php` (32 scenarios) green at the end.

## 10. Deferred to Real-time milestone (documented requirement)
`routes/channels.php` defines `user.{userId}` and `driver.{driverId}` using internal user ids (the `order.{publicId}` channel already uses `public_id`). Renaming these to `public_id` requires changing every broadcast emission site, and the Real-time milestone will redesign/expand channels holistically. **Requirement carried into the Real-time milestone:** all private channels use `public_id` in their names (no internal ids), and channel-authorization callbacks resolve accordingly. Recorded here so it is not lost. Not in scope for this remediation.

## 11. Process
- Single worktree (Claude), branch `claude/id-exposure-remediation` from `main`; one cohesive PR.
- Subagent-driven execution per the implementation plan; normal PR flow.

## 12. Out of scope
- Phone-number masking (Critical Rule 12) — separate future pass.
- Promoting Tinker smoke scripts to Pest.
- Realtime channel renaming (§10 — owned by the Real-time milestone).
- Any new endpoints, fields, or business logic.

---

**End of design.**
