# Driver Onboarding — Design Spec

**Date:** 2026-05-07
**Status:** ✅ Implemented (2026-05-10)
**Scope:** Full driver lifecycle from pre-registration through admin approval to active operation. Includes office staff endpoints, admin endpoints, and driver self-service endpoints. Document upload via Spatie Media. The `driver_account` (3 buckets) auto-creates on approval; the `driver` Spatie role auto-attaches.
**Out of scope:** Driver onboarding mobile-app UI; the dashboard web SPA; driver-actively-doing-deliveries (going online, accepting orders, location tracking — those land in the orders milestone); user-level account moderation (ban/suspend with reason history) — deferred to its own milestone; staff CRUD endpoints (admin creates new staff) — deferred to its own milestone; driver self-uploaded documents (face-to-face is the trust model at MVP).

**Predecessors:** Auth milestone (`docs/superpowers/specs/2026-05-05-auth-design.md`) — the user-account, login, OTP, and Sanctum infrastructure already exists.

---

## 1. Goals

1. **Face-to-face is the trust model.** Drivers cannot self-onboard. Office staff is physically present, sees the human, sees the original documents, photographs them, uploads them. (Spec rule 13 + §2.2.)
2. **One unified flow** that handles both pre-registered users (filled the online form first) AND cold walk-ins (showed up with no prior account). The branch is at user-resolution; downstream is identical.
3. **Phone is verified before driver is approved.** Either via prior OTP at registration, or via in-office OTP during the walk-in (driver shows phone, staff types the SMS code).
4. **Admin reviews the staff's submission.** Admin approves or rejects. On approval, the system auto-creates the driver's 3-bucket account, assigns the `driver` Spatie role, and transitions to `active`.
5. **Driver picks their own region preferences post-approval.** Within their assigned office's service area. Picking zero = open to all regions.
6. **API namespaces are clean** so a future split into a dedicated driver mobile app costs nothing on the backend.
7. **All actions audit-trailed via existing schema columns** (`approved_by_admin_id`, `verified_by_admin_id`, etc.).

## 2. Non-Goals

- Auto-OCR of national ID or driver's license. Future v2 if it ever proves to be a bottleneck. Admin reviews documents visually.
- Auto face-match between the selfie and the national ID photo. Future v2.
- Driver self-uploaded documents from the mobile app. Office staff uploads only.
- A "rejection reason" field shown to the rejected driver. Per Q5: rejection is a status flip; admin tooling to delete rejected records can be added later if needed.
- User-level account ban / suspend with history + reason logging. **Separate dedicated milestone** for cross-cutting account moderation.
- Cross-office driving (a driver assigned to Tripoli office accepting trips in Misrata). Defer; one office per driver at MVP.
- Staff CRUD endpoints (admin creates new staff via dashboard). **Separate dedicated milestone.** For dev/test, a `TestStaffSeeder` provides one office_staff and one admin user.
- The driver-going-online flow (location pings, order broadcasts, accept/reject). Lives in the orders milestone.

## 3. Locked Decisions (from brainstorm 2026-05-07)

| # | Question | Decision |
|---|---|---|
| 1 | Onboarding entry point | **Unified.** Single downstream flow; user-resolution branches between (a) existing user with pre-registered profile, (b) existing user without driver profile, (c) cold walk-in (staff creates user inline with in-office OTP). |
| 2 | Office staff authentication | **Same `/api/auth/login`. Role-gated namespaces.** Backend stays unified; the dashboard is a separate frontend codebase consuming `/api/office/*` and `/api/admin/*`. |
| 3 | Document upload responsibility | **Office staff only** (via dashboard). Driver shows physical document; staff photographs and uploads. Single source of truth, single camera. |
| 4 | Approval authority | **Admin-only at MVP.** Office managers (existing `office_staff_assignments.is_manager` flag) can be granted the `admin` Spatie role explicitly if they should approve. |
| 5 | Rejection handling | **Status flip; no rejection_reason field used.** Rejected drivers stay rejected; admin can delete the driver_profile manually if they want to allow re-application. Bans/suspends at user level (with reason + history) deferred to a separate moderation milestone. |
| 6 | Region assignment | **Driver-flexible, post-approval.** Approval doesn't require regions. Driver picks via the app; picking zero defaults to "all regions in my office's service area". Stored in `driver_region` pivot. |
| 7 | Driver mobile app architecture | **Single app codebase**, conditional driver UI based on role + status. API namespace separation (`/api/driver/*`) ensures a future split into a dedicated driver app is a frontend-only refactor. |

## 4. Lifecycle State Machine

The `driver_profiles.status` column is a `DriverStatus` enum (already exists). Transitions used in this milestone:

```
                         ┌──────────────────┐
[user opts in online] →  │  pre_registered  │  ← (cold walk-ins skip this state)
                         └──────────────────┘
                                  │
                                  │ (office staff completes walk-in:
                                  │  identity verified, all required docs uploaded,
                                  │  staff submits)
                                  ▼
                         ┌──────────────────┐
                         │ pending_approval │
                         └──────────────────┘
                            │            │
       ┌────────────────────┘            └─────────────┐
       ▼ (admin approves)                              ▼ (admin rejects)
┌──────────────┐                                   ┌──────────┐
│    active    │                                   │ rejected │  ← terminal-ish
└──────────────┘                                   └──────────┘
   │      ▲
   │      │ (admin reinstates)
   ▼      │
┌────────────────┐
│   suspended    │  ← admin pauses driving (driver-role-scoped, not user-account-scoped)
└────────────────┘
```

**Side effects of `pending_approval → active` (the approval transition):**
1. `driver_profiles.approved_at = now()`, `approved_by_admin_id = admin.id`
2. **Auto-create `driver_accounts` row** with `max_cash_liability = platform_settings.new_driver_max_liability` (default 100 LYD), all three buckets at 0
3. **Auto-assign Spatie role `driver`** to the user
4. Mark all `driver_documents` rows as `verified = true`, `verified_by_admin_id = admin.id`, `verified_at = now()` (the admin reviewed the whole submission, so all the docs are vouched for)

**Enum cases retained but unused in this milestone:** `approved`, `banned`. Reserved for future use:
- `approved` could become a transitional state if we later want to require driver to do "first-time setup" before being operational.
- `banned` will be wired in the global account-moderation milestone.

**Allowed transitions (`DriverStatus::allowedTransitions()`-style mapping for THIS milestone):**

| From | To | Initiator |
|---|---|---|
| `pre_registered` | `pending_approval` | office staff (after walk-in + docs) |
| `pending_approval` | `active` | admin (approve) |
| `pending_approval` | `rejected` | admin (reject) |
| `active` | `suspended` | admin |
| `suspended` | `active` | admin (reinstate) |

A `pre_registered` driver who never showed up at the office stays in that state indefinitely. Admin can delete the `driver_profile` row directly if cleanup is wanted; no state-machine path for it.

## 5. Public API Surface

All endpoints under one of four namespaces. Sanctum bearer tokens. JSON in/out. FormRequests validate; JsonResources shape responses; services handle logic.

### 5.1 `/api/me/driver/*` — pre-registration self-service for existing users

```
POST /api/me/driver/preregister   (auth:sanctum, role:user, account_status:active, phone_verified)

Body:
  office_id        ulid       required, must reference active office
  vehicle_type     enum       required: car | motorcycle
  vehicle_plate    string     required, 1..32
  vehicle_color    string     optional, 1..32
  vehicle_model    string     optional, 1..64

Side effects:
  - Creates driver_profiles row in pre_registered state
  - office_id is locked from this point — driver belongs to that office for settlements

Response 201: { driver_profile: DriverProfileResource }
Failure 409: driver_profile_exists (user already pre-registered or further along)
Failure 422: validation_failed (bad office_id / vehicle_type)
```

```
GET /api/me/driver   (auth:sanctum)

Returns the authenticated user's driver_profile, or null if they haven't pre-registered.
Always 200 — the absence of a profile is not an error condition.
Response 200: { driver_profile: DriverProfileResource | null }
```

### 5.2 `/api/office/drivers/*` — office staff endpoints

All endpoints: `auth:sanctum` + `role:office_staff` + scoped via policy to the staff's assigned office (cannot act on drivers tied to a different office). Staff member's office is read from `office_staff_assignments` for the authenticated user.

```
GET /api/office/drivers   (paginated)

Query:
  status   enum    optional filter (default: pre_registered + pending_approval)
  search   string  optional — phone/name fuzzy

Returns drivers tied to staff's office. Default lists the actionable queue
(pre_registered awaiting walk-in completion + pending_approval awaiting admin).

Response 200: { data: DriverProfileResource[], meta: ... }
```

```
POST /api/office/drivers/lookup

Body:
  phone_number  string  required, E.164 (regex: ^\+218\d{9}$)

Logic:
  - Looks up user by phone
  - Returns enough info for staff to decide what to do next:
    - User exists?
    - User has driver_profile?
    - If yes: status, office_id, vehicle info
    - If user is for a different office: error (cannot poach)

Response 200: {
  user_exists: bool,
  user_phone_verified: bool,
  driver_profile: DriverProfileResource | null,
  can_onboard: bool,        // true if no profile, or pre_registered for this office
  reason_if_not: string | null  // e.g. "belongs to another office", "already approved"
}
```

```
POST /api/office/drivers/onboard

Body:
  phone_number      string  required (E.164)
  first_name        string  required-when-creating-user, 1..100
  last_name         string  optional, 1..100
  vehicle_type      enum    required (car | motorcycle)
  vehicle_plate     string  required, 1..32
  vehicle_color     string  optional, 1..32
  vehicle_model     string  optional, 1..64

Logic (resolved by service in a single DB::transaction):
  1. Look up user by phone:
     a. User exists with pre_registered profile for this office → use it
     b. User exists with no driver_profile → create one in pre_registered
        (locked to this staff member's office)
     c. User does not exist:
        - Create user with phone + name (account_status=active, phone_verified_at=null)
        - Create driver_profile in pre_registered (locked to staff's office)
        - Trigger OtpService::issue(phone, OtpPurpose::Registration)
        - Return otp_required=true; staff prompts driver to read the SMS code aloud
        - Staff calls POST /api/office/drivers/{driver_profile}/verify-phone with the code
        - On verify success: user.phone_verified_at = now()
        - The driver_profile EXISTS during the OTP-pending window in pre_registered
          state but submit() will reject due to phone_not_verified
  2. Vehicle data is saved on driver_profiles regardless of path
  3. otp_required = true ONLY for path (c); paths (a) and (b) have already-verified phones

Response 201: {
  driver_profile: DriverProfileResource,
  otp_required: bool,
  otp_expires_at: iso8601 | null
}
```

```
POST /api/office/drivers/{driver_profile}/verify-phone

Body:
  code  string  required, 6-digit numeric

Used only after the cold-walk-in path that triggered an OTP. Verifies the OTP against
the same OtpService machinery as registration; on success, sets phone_verified_at = now()
on the user. Same anti-enum & rate-limit rules as the existing OTP endpoints.

Response 204 on success
Failure 422: otp_invalid
```

```
POST /api/office/drivers/{driver_profile}/documents   (multipart/form-data)

Body:
  type      enum                required (DriverDocumentType case)
  file      uploaded file       required, image/* or application/pdf, max 10 MB
  expires_at  date              optional (license, insurance, etc.)
  notes     string              optional

Logic:
  - Stores file via Spatie Media on the User model
  - Custom collection: 'driver_document_' . type    (e.g. 'driver_document_drivers_license')
  - Single-file collection: replacing same-type re-uploads the file & overwrites
  - Creates or updates the corresponding driver_documents row with metadata
  - Document is unverified at upload (admin verifies during approval review)

Response 201: { driver_document: DriverDocumentResource }
Failure 422: validation_failed (bad type, bad file mime, file too large)
```

```
DELETE /api/office/drivers/{driver_profile}/documents/{driver_document}

Removes both the Spatie media file and the driver_documents row. Only allowed before
submission (status = pre_registered). After submission, only admin can manage.

Response 204
Failure 403: locked_post_submission
```

```
POST /api/office/drivers/{driver_profile}/submit

Validation:
  - driver_profile.status MUST be pre_registered
  - User's phone_verified_at MUST be set
  - All REQUIRED documents must be uploaded:
      Universal:    national_id_front, national_id_back, drivers_license, selfie
      Vehicle:      vehicle_registration, vehicle_photo_front, vehicle_photo_back

Side effects:
  - driver_profile.status → pending_approval

Response 200: { driver_profile: DriverProfileResource }
Failure 422: missing_documents (with `missing: [...]` array)
Failure 422: phone_not_verified
Failure 409: invalid_state
```

### 5.3 `/api/admin/drivers/*` — admin endpoints

All endpoints: `auth:sanctum` + `role:admin`. Admin sees all drivers regardless of office.

```
GET /api/admin/drivers   (paginated)

Query:
  status     enum     optional filter
  office_id  ulid     optional filter
  search     string   optional — phone/name/plate fuzzy

Default sort: pending_approval drivers first (oldest first — FIFO queue).
Response 200: { data: DriverProfileResource[], meta: ... }
```

```
GET /api/admin/drivers/{driver_profile}

Full detail: profile + user + office + all documents (with signed media URLs) +
audit fields (approved_at, approved_by, rejected_at, etc.).

Response 200: { driver_profile: DriverProfileFullResource }
```

```
POST /api/admin/drivers/{driver_profile}/approve

No body required.

Validation:
  - status MUST be pending_approval

Side effects (atomic, in DB::transaction):
  1. driver_profile.status → active
  2. driver_profile.approved_at = now(), approved_by_admin_id = admin.id
  3. Auto-create driver_accounts row:
       cash_to_deposit=0, earnings_balance=0, debt_balance=0,
       max_cash_liability = platform_settings.new_driver_max_liability
  4. Auto-assign Spatie role 'driver' to the user
  5. All driver_documents rows: verified=true, verified_by_admin_id=admin.id,
     verified_at=now()

Response 200: { driver_profile: DriverProfileFullResource }
Failure 409: invalid_state
```

```
POST /api/admin/drivers/{driver_profile}/reject

No body required.

Validation:
  - status MUST be pending_approval

Side effects:
  - driver_profile.status → rejected
  - rejected_at = now()

Response 200: { driver_profile: DriverProfileResource }
Failure 409: invalid_state
```

```
POST /api/admin/drivers/{driver_profile}/suspend

No body required.

Validation:
  - status MUST be active

Side effects:
  - driver_profile.status → suspended

Response 200: { driver_profile: DriverProfileResource }
Failure 409: invalid_state
```

```
POST /api/admin/drivers/{driver_profile}/reinstate

No body required.

Validation:
  - status MUST be suspended

Side effects:
  - driver_profile.status → active

Response 200: { driver_profile: DriverProfileResource }
Failure 409: invalid_state
```

### 5.4 `/api/driver/*` — driver self-service endpoints

All: `auth:sanctum` + `role:driver` + driver_profile.status must be `active` (or `suspended` for read-only views).

```
GET /api/driver/profile    Returns own DriverProfileResource (status, office, vehicle,
                            performance counters)

GET /api/driver/regions     Returns: {
                              office_id, office_name,
                              available: RegionResource[],   // all regions in office's service area
                              selected: RegionResource[],    // ones I've picked
                              effective: RegionResource[],   // selected if non-empty, else all
                            }

PATCH /api/driver/regions   Body: { region_ids: ulid[] } — empty array = "all"
                            All region_ids must belong to my office's service area.
                            Updates driver_region pivot (sync).
                            Response 200: same shape as GET

GET /api/driver/account     Read-only view of own driver_accounts row + recent
                            transactions (paginated, last 30).
                            Response 200: { account: DriverAccountResource, transactions: ... }
```

## 6. Component Architecture

```
app/Http/Controllers/Api/Me/Driver/
  PreregistrationController.php           single action: __invoke (POST /me/driver/preregister)
  DriverProfileController.php             single action: show       (GET  /me/driver)

app/Http/Controllers/Api/Office/
  DriverOnboardingController.php          actions: index, lookup, onboard, verifyPhone, submit
  DriverDocumentController.php            actions: store, destroy

app/Http/Controllers/Api/Admin/
  DriverController.php                    actions: index, show, approve, reject, suspend, reinstate

app/Http/Controllers/Api/Driver/
  ProfileController.php                   single action: show       (GET  /driver/profile)
  RegionController.php                    actions: index, update    (GET/PATCH /driver/regions)
  AccountController.php                   single action: show       (GET  /driver/account)

app/Http/Requests/Driver/
  PreregisterDriverRequest.php
  OnboardDriverRequest.php
  LookupDriverRequest.php
  VerifyDriverPhoneRequest.php
  UploadDriverDocumentRequest.php
  UpdateRegionsRequest.php
  (admin endpoints are no-body; no FormRequests needed)

app/Http/Resources/
  DriverProfileResource.php          summary view (used in lists + simple responses)
  DriverProfileFullResource.php      full detail with documents + audit (admin-only)
  DriverDocumentResource.php
  DriverAccountResource.php          (3 buckets + lifetime stats)
  RegionResource.php                 (id, name, office_id)

app/Services/Driver/
  DriverPreregistrationService.php    creates driver_profile from /me/driver/preregister
  DriverOnboardingService.php         resolves user, creates user if needed, creates driver_profile,
                                      issues OTP for cold walk-ins. Used by office endpoints.
  DriverDocumentService.php           handles Spatie Media upload + driver_documents row sync
  DriverApprovalService.php           atomic approval: state, account creation, role assignment, docs verified
  DriverStatusTransitionService.php   reject / suspend / reinstate transitions (validates allowed transitions)
  DriverRegionService.php             validates region_ids belong to driver's office service area + syncs pivot

app/Policies/
  DriverProfilePolicy.php             office staff: profile must belong to staff's office
                                      admin: any
                                      driver: only own profile (for /api/driver/* read endpoints)

app/Enums/  (no new enums for this milestone — DriverStatus, VehicleType, DriverDocumentType all exist)

database/seeders/
  TestStaffSeeder.php                 dev-only seed: creates 1 office_staff user + 1 admin user
                                      with known phone numbers + passwords for tinker testing.
                                      Registered in DatabaseSeeder behind APP_ENV check.
```

### Why services-not-controllers

Same as auth: controllers are 5–10 lines, all orchestration in services. Each service has one cohesive responsibility:

- `DriverOnboardingService::onboard($staffOffice, $data)` — the most complex one. Handles all three user-resolution branches (existing-pre-registered, existing-no-profile, no-user) in one method with clear branches. Returns `{ driver_profile, otp_required, otp_expires_at }`.
- `DriverApprovalService::approve($driverProfile, $admin)` — wraps everything in `DB::transaction()`: state flip, driver_account create, role assign, docs verified. Single atomic step.
- `DriverStatusTransitionService` covers the simpler reject/suspend/reinstate transitions with shared "validate allowed transition" logic.

This keeps the complex logic (onboarding multi-branch resolution, approval side-effects) testable in isolation without HTTP context.

## 7. Data Model Touchpoints

**No new tables.** All schema requirements are satisfied by existing tables:

| Table | Touchpoint |
|---|---|
| `users` | Read for lookup; create for cold walk-ins (with phone_verified_at gated by in-office OTP) |
| `driver_profiles` | Read/write for status transitions; vehicle data + office_id captured on create |
| `driver_documents` | One row per uploaded document, paired to a Spatie media row |
| `driver_accounts` | Auto-created on approval (3 buckets, max_cash_liability from platform_settings) |
| `driver_region` (pivot) | Driver self-service updates via PATCH /api/driver/regions |
| `media` (Spatie) | Stores actual document files, custom-collection per document type |
| Spatie roles: `driver` | Auto-assigned on approval |

**Platform settings already seeded** that this milestone references:
- `new_driver_max_liability` (100 LYD default) — set as `driver_accounts.max_cash_liability` on creation

**No new platform_settings rows needed.**

## 8. Document Storage Strategy

Files live in **Spatie Media** against the User model. Metadata lives in `driver_documents`. The two are joined **by convention** (no FK column needed): the existing `driver_documents` table already has a `UNIQUE(driver_id, document_type)` constraint, and Spatie media collections are named `driver_document_{type}`. Given a `driver_documents` row, the matching Spatie media is `User::find($driverId)->getFirstMedia("driver_document_{$documentType}")`.

**No new migration needed.** Existing schema is sufficient.

Why convention-based linking is fine:
- The `UNIQUE(driver_id, document_type)` constraint guarantees there's exactly one driver_documents row per (driver, type) pair.
- Single-file Spatie collection guarantees there's exactly one file per (driver, collection-name=type) pair.
- Lookup is O(1) — collection_name + model_id are both indexed in the media table.

**Custom collection naming convention:** `driver_document_{type}` (e.g. `driver_document_national_id_front`).

**Single-file collections:** Spatie's `singleFile()` modifier on the collection registration ensures each collection holds at most one file. Re-uploading the same type replaces the file (Spatie deletes the old before storing the new).

**File constraints (enforced by FormRequest + Spatie media collection rules):**
- Mimetypes: `image/jpeg`, `image/png`, `image/webp`, `application/pdf`
- Max size: 10 MB per file
- Image dimensions: no constraint (uploads from staff cameras vary)

**Conversions (Spatie media):** auto-generate a thumbnail (e.g. 400x400) for fast display in the admin review UI.

## 9. Authorization

Spatie role middleware does the coarse-grained gate. Policies do the fine-grained scope check.

| Route group | Middleware | Policy gate |
|---|---|---|
| `/api/me/driver/*` | `auth:sanctum` | n/a (route-bound to auth user) |
| `/api/office/drivers/*` | `auth:sanctum`, `role:office_staff` | `DriverProfilePolicy::manageInOffice` — checks `driver_profile.office_id` is in the staff's `office_staff_assignments` |
| `/api/admin/drivers/*` | `auth:sanctum`, `role:admin` | n/a (admin sees all) |
| `/api/driver/*` | `auth:sanctum`, `role:driver` | `DriverProfilePolicy::viewOwn` — driver_profile.user_id must equal auth user id |

**Edge case — staff with multiple office assignments:** the `office_staff_assignments` table allows one staff member at multiple offices. Policy checks ANY of their assignments. If there are zero (e.g. recently revoked staff), all office endpoints return 403.

**Edge case — staff trying to onboard for a different office:** the `onboard` controller hard-codes `office_id = staff's office_id` (taken from their assignment). The request body cannot override this. Lookup of an existing user with a profile in a different office returns `can_onboard=false, reason_if_not="belongs_to_other_office"` — staff is told to redirect the driver to the correct office.

## 10. Testing Strategy

Same shape as auth milestone:
- **Smoke tests via tinker** for each major flow (cold walk-in, pre-registered walk-in, document upload, submit, approve, reject, suspend, reinstate, region picking). Files written to `storage/app/smoke_*.php`, removed after the run lands green.
- **Pest tests deferred** until the test-DB pre-flight milestone (separate Postgres test database).
- `TestStaffSeeder` provides known accounts for smoke tests:
  - `+218910000001` — office_staff at the test office
  - `+218910000002` — admin
  - Both with password `password123`

**Smoke scenarios that must pass:**
1. Cold walk-in: user doesn't exist → staff creates → OTP via FakeSmsDriver → staff submits OTP → user created with phone_verified=true → driver_profile in `pre_registered`
2. Pre-registered: user pre-registers via /me/driver/preregister → walks in → staff completes
3. Document upload + replacement (re-upload same type)
4. Submission rejected for missing required documents
5. Approval transitions to `active`, creates `driver_account`, assigns `driver` role, marks all documents verified — all in one transaction
6. Rejection transitions to `rejected`
7. Suspend → reinstate cycle
8. Driver picks regions; empty array = "all" effective; non-empty = restricted; cannot pick regions outside their office's service area
9. Office staff at office A cannot see/act on drivers tied to office B (403)
10. Admin can see and act on drivers from any office

## 11. Error Handling

Use the existing `AuthErrorCode` enum where applicable; add new cases sparingly. Driver-specific errors:

| Error code | HTTP | Trigger |
|---|---|---|
| `driver_profile_exists` | 409 | User already has a driver_profile (pre-register or onboard called twice) |
| `wrong_office` | 403 | Office staff tried to act on a driver tied to a different office |
| `invalid_state` | 409 | Status transition not allowed from current state (e.g. approve a `pre_registered` profile) |
| `missing_documents` | 422 | Submit called without all required documents; response includes `missing: [...]` array of types |
| `phone_not_verified` | 422 | Submit called when user hasn't verified phone yet |
| `locked_post_submission` | 403 | Trying to delete a document after status moved to pending_approval |

We'll add a new enum `DriverErrorCode` (or extend `AuthErrorCode` — choosing the former for clarity since these are driver-domain errors).

## 12. Security & Audit Notes

1. **Office scoping is enforced at the policy layer**, not just on listing. Any single-driver action (lookup, document upload, submit, etc.) is authorized via `DriverProfilePolicy`. A staff member crafting `POST /api/office/drivers/{other_office_driver_id}/submit` gets 403.
2. **In-office OTP for cold walk-ins** uses the same `OtpService` machinery, namespaced under `OtpPurpose::Registration`. Staff enters the code into the dashboard; same throttling as public OTP verify (10/15min/phone).
3. **Document mime-type whitelist enforced server-side.** No JS or executable uploads.
4. **Spatie media files served behind signed URLs** when listed in admin detail responses. Direct media URLs are not permanent / not enumerable.
5. **Approval is a single atomic transaction.** Either everything happens (state, account, role, docs verified) or nothing does. No partial state where a driver has a `driver` role but no `driver_account`.
6. **Audit columns populated:** `approved_by_admin_id`, `verified_by_admin_id`, `rejected_at`, etc. The "who did what" is visible in the existing schema columns.

## 13. Open Items / Deferred

- **Driver self-uploaded documents from mobile app** — pure feature add; can ship later if office throughput proves to be a bottleneck. Schema already supports it (driver_documents.driver_id is the user; `verified_by_admin_id` stays null until admin reviews).
- **Cross-office drivers** — driver in Tripoli accepting trips in Misrata. Requires settlement-flow rethink (which office do they settle at?). Defer.
- **Driver re-application flow after rejection** — for now, admin manually deletes the rejected `driver_profile` row, then driver can pre-register again. If this proves common, add a "reset to pre_registered" admin action later.
- **Document expiry monitoring** — a scheduled job that emails admin "driver X's license expires in 14 days" or auto-suspends drivers with expired licenses. Future feature.
- **Driver role management** — what happens to the `driver` Spatie role on suspension/rejection? **For this milestone:** role stays attached on suspension (so they can view their account in `suspended` state), is removed on rejection. Banning (future moderation milestone) handles role detachment too.
- **Driver onboarding mobile UI** — out of scope (frontend concern).
- **Dashboard web UI** — out of scope (frontend concern).
- **Staff CRUD endpoints** — separate dedicated milestone.
- **User-level account moderation** (ban/suspend with reason history) — separate dedicated milestone.

## 14. Implementation Order (preview for the plan)

The implementation plan (next document) will sequence these. Approximate order:

1. `TestStaffSeeder` — so smoke tests have authenticated office_staff + admin to act as
2. `DriverErrorCode` enum + `DriverProfilePolicy`
3. `DriverProfileResource`, `DriverProfileFullResource`, `DriverDocumentResource`, `DriverAccountResource`, `RegionResource`
4. `DriverPreregistrationService` + `PreregistrationController` + `PreregisterDriverRequest` + smoke test
5. `DriverOnboardingService` (the complex multi-branch resolver) — heaviest piece — followed by `DriverOnboardingController`'s `lookup` and `onboard` actions + smoke tests
6. `DriverDocumentService` + `DriverDocumentController` + Spatie media collection registration on User model (`registerMediaCollections()` declares one single-file collection per `DriverDocumentType`) + smoke test
7. `DriverOnboardingController::submit` + validation of required documents
8. `DriverApprovalService` + admin's `approve` action — heaviest admin piece (atomic side effects) + smoke test
9. Admin's `reject`, `suspend`, `reinstate` (via `DriverStatusTransitionService`) + smoke tests
10. Admin's `index` + `show` + filter/search
11. `DriverRegionService` + `/api/driver/regions` endpoints + smoke test
12. `/api/driver/profile` and `/api/driver/account` (read-only) + smoke test
13. Office staff `index` (queue view)
14. End-to-end full flow smoke test (cold walk-in → docs → submit → approve → driver picks regions)
15. Pint pass + docs update

Each step is a vertical slice with smoke test coverage.

---

**End of design spec.**
