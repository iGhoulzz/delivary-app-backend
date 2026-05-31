# Internal-ID Exposure Remediation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate every internal auto-increment id from API URLs, response bodies, and inbound request params (per `docs/CLAUDE.md` Critical Rule 11), per the approved spec `docs/superpowers/specs/2026-05-31-id-exposure-remediation-design.md`.

**Architecture:** Public ULIDs at the API boundary; internal numeric ids stay inside services/DB. Drivers are addressed by `User.public_id`. Outbound FK ids become nested `{id, name}` (public_id). Inbound ids become `*_public_id`, resolved once at the request boundary. `order_status_logs.metadata` gets write-time public summaries + a read-time fail-closed allowlist.

**Tech Stack:** Laravel 13, PHP 8.3, PostgreSQL, Pest 4, Sanctum, Spatie Permission/Media.

**Branch:** `claude/id-exposure-remediation` (single worktree, single PR).

**Conventions (from `docs/CLAUDE.md`):** `declare(strict_types=1)`; `final` classes; FormRequests for validation; JsonResource for every response; expose `public_id` as `id`, never internal `id`; eager-load to kill N+1; `vendor/bin/pint` before every commit; Pest `it()` style; routes NOT versioned. Test DB `delivary_app_testing` (pgsql). Run a single test file: `vendor/bin/pest <path>`.

**Reference — models WITH `public_id`:** User, Order, OfficeLocation, Settlement, SellerPayout, SellerEarning, OfficeInventory, OfficeStaffAssignment, MerchantProfile, GuestRecipient, PaymentMethod, TopupRequest. **WITHOUT (by design):** DriverProfile, DriverDocument, DriverAccount, DriverAccountTransaction, Region, ServiceArea.

---

## File Structure

**New files:**
- `app/Http/Resources/Driver/DriverAccountTransactionResource.php` — wraps driver-account transactions, omits internal id.
- `app/Http/Requests/Driver/IndexDriverRequest.php` — validates admin driver-index filters (incl. `office_public_id`).
- `app/Http/Requests/Staff/IndexStaffRequest.php` — validates staff-index filters (incl. `office_public_id`).
- `app/Support/Resolvers/PublicIdResolver.php` — small helper to resolve a public_id → model/internal id for inbound requests.
- `app/Support/OrderStatusLogMetadata.php` — metadata key constants + read-time sanitizer (allowlist + drop unknown `*_id`).

**Modified — routes/relations:** `routes/api.php`, `app/Models/DriverProfile.php`.
**Modified — controllers:** `AdminDriverController`, `Office/DriverOnboardingController`, `Office/DriverDocumentController`, `Driver/RegionController`, `Driver/AccountController`, `Me/Settlement/ShowEarningsController`.
**Modified — resources:** `DriverProfileResource`, `DriverProfileFullResource`, `DriverDocumentResource`, `Order/OrderResource`, `Order/AdminOrderResource`, `Order/OfficeOrderResource`, `Order/OrderStatusLogResource`, `Settlement/SettlementResource`, `Settlement/SellerPayoutResource`, `Settlement/AdminSellerPayoutResource`, `Order/OfficeInventoryResource`.
**Modified — FormRequests:** `Staff/CreateStaffRequest`, `Staff/AttachOfficeAssignmentRequest`, `Driver/PreregisterDriverRequest`, `Order/AdminAssignOrderRequest`, `Order/RedirectReturnRequest`, `Settlement/ListSettlementsRequest`, `Settlement/ListSellerPayoutsRequest`.
**Modified — metadata writers:** every site writing `order_status_logs.metadata` with internal ids (enumerated in Task 9).
**Modified — docs:** `docs/CLAUDE.md`.

---

## Task 0: Branch setup

- [ ] **Step 1: Create the worktree/branch from up-to-date main**

```bash
cd /c/Users/User/Desktop/delivary-app
git checkout main && git pull origin main
git worktree add ../delivary-app-idfix -b claude/id-exposure-remediation
cd ../delivary-app-idfix
composer install
```

- [ ] **Step 2: Confirm green baseline**

Run: `vendor/bin/pest`
Expected: full suite passes (current main baseline).

---

## Task 1: `DriverProfile` relationships (documents + approvedBy)

**Files:**
- Modify: `app/Models/DriverProfile.php`
- Test: `tests/Unit/Models/DriverProfileRelationsTest.php`

DriverDocuments are keyed by `driver_id` = `users.id`; `DriverProfile.user_id` is that same user. So `documents` is a `hasMany` on `DriverProfile` via `driver_id` → `user_id`. `approvedBy` is the admin User.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes documents and approvedBy relations', function (): void {
    $user = User::factory()->create();
    $profile = DriverProfile::factory()->create(['user_id' => $user->id]);
    DriverDocument::factory()->create(['driver_id' => $user->id, 'document_type' => 'national_id_front']);

    expect($profile->documents()->count())->toBe(1);
    expect($profile->documents->first())->toBeInstanceOf(DriverDocument::class);
    expect($profile->approvedBy())->not->toBeNull();
});
```

- [ ] **Step 2: Run test — expect fail** (`Call to undefined method ...documents()`)

Run: `vendor/bin/pest tests/Unit/Models/DriverProfileRelationsTest.php`

- [ ] **Step 3: Add the relations to `DriverProfile`**

Add these methods (near the existing relations); ensure `use Illuminate\Database\Eloquent\Relations\HasMany;` and `BelongsTo` are imported:

```php
public function documents(): HasMany
{
    return $this->hasMany(DriverDocument::class, 'driver_id', 'user_id');
}

public function approvedBy(): BelongsTo
{
    return $this->belongsTo(User::class, 'approved_by_admin_id');
}
```

- [ ] **Step 4: Run test — expect pass**

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Models/DriverProfile.php tests/Unit/Models/DriverProfileRelationsTest.php
git add app/Models/DriverProfile.php tests/Unit/Models/DriverProfileRelationsTest.php
git commit -m "feat(driver): add DriverProfile documents + approvedBy relations"
```

---

## Task 2: `PublicIdResolver` helper (inbound resolution)

**Files:**
- Create: `app/Support/Resolvers/PublicIdResolver.php`
- Test: `tests/Unit/Support/PublicIdResolverTest.php`

A tiny, reusable resolver so inbound FormRequests/services turn a public_id into a model or internal id once. Throws `ModelNotFoundException` (→ 404) on miss; FormRequest validation (`exists:...,public_id`) is the first line, this is the resolution.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\OfficeLocation;
use App\Models\Region;
use App\Support\Resolvers\PublicIdResolver;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves an office public_id to its internal id', function (): void {
    $region = Region::factory()->create();
    $office = OfficeLocation::create([
        'region_id' => $region->id, 'name' => 'O1', 'address' => 'a',
        'location' => Point::makeGeodetic(32.0, 13.0), 'is_active' => true,
    ]);

    expect(PublicIdResolver::officeId($office->public_id))->toBe($office->id);
    expect(PublicIdResolver::officeId(null))->toBeNull();
});
```

- [ ] **Step 2: Run test — expect fail**

Run: `vendor/bin/pest tests/Unit/Support/PublicIdResolverTest.php`

- [ ] **Step 3: Implement the resolver**

```php
<?php

declare(strict_types=1);

namespace App\Support\Resolvers;

use App\Models\OfficeLocation;
use App\Models\User;

final class PublicIdResolver
{
    /** Resolve an office public_id to its internal id (null in, null out). */
    public static function officeId(?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return (int) OfficeLocation::query()->where('public_id', $publicId)->valueOrFail('id');
    }

    /** Resolve a user public_id to its internal id (null in, null out). */
    public static function userId(?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return (int) User::query()->where('public_id', $publicId)->valueOrFail('id');
    }
}
```

> Note: `valueOrFail()` (Laravel 11+) returns the column value or throws `RecordsNotFoundException`. Since each inbound FormRequest validates `exists:...,public_id` first, the throw is a defensive backstop.

- [ ] **Step 4: Run test — expect pass**

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Support/Resolvers/PublicIdResolver.php tests/Unit/Support/PublicIdResolverTest.php
git add app/Support/Resolvers/PublicIdResolver.php tests/Unit/Support/PublicIdResolverTest.php
git commit -m "feat(support): PublicIdResolver for inbound public_id -> internal id"
```

---

## Task 3: Rebind driver routes to `{driverUser:public_id}`

**Files:**
- Modify: `routes/api.php`

This task only changes route params + the bound type; controllers are refactored in Tasks 4–5. After this task the driver feature tests will fail (URLs/signatures mismatch) until Tasks 4–5 land — that is expected; do not run the full suite mid-refactor, run it after Task 5.

- [ ] **Step 1: Update the office driver routes**

In the `/api/office/drivers` group, change the 4 parameterized routes from `{driverProfile}` to `{driverUser:public_id}`, and the document delete to use `{documentType}`:

```php
Route::post('{driverUser:public_id}/verify-phone', [DriverOnboardingController::class, 'verifyPhone']);
Route::post('{driverUser:public_id}/submit', [DriverOnboardingController::class, 'submit']);
Route::post('{driverUser:public_id}/documents', [DriverDocumentController::class, 'store']);
Route::delete('{driverUser:public_id}/documents/{documentType}', [DriverDocumentController::class, 'destroy']);
```

- [ ] **Step 2: Update the admin driver routes**

In `/api/admin/drivers`, change the 5 parameterized routes:

```php
Route::get('{driverUser:public_id}', [AdminDriverController::class, 'show']);
Route::post('{driverUser:public_id}/approve', [AdminDriverController::class, 'approve']);
Route::post('{driverUser:public_id}/reject', [AdminDriverController::class, 'reject']);
Route::post('{driverUser:public_id}/suspend', [AdminDriverController::class, 'suspend']);
Route::post('{driverUser:public_id}/reinstate', [AdminDriverController::class, 'reinstate']);
```

- [ ] **Step 3: Verify route names resolve**

Run: `php artisan route:list --path=drivers`
Expected: routes show `{driverUser}` param; no errors.

- [ ] **Step 4: Commit** (no test run yet — controllers follow)

```bash
vendor/bin/pint routes/api.php
git add routes/api.php
git commit -m "refactor(driver): bind driver routes by User.public_id + document by type"
```

---

## Task 4: Refactor `AdminDriverController` (User binding)

**Files:**
- Modify: `app/Http/Controllers/Api/Admin/DriverController.php`
- Test: `tests/Feature/Admin/AdminDriverLifecycleTest.php` (existing — update assertions)

- [ ] **Step 1: Update the feature test to the new URL identity**

Change every request URL in the admin-driver test from the profile id to the driver's `user->public_id`, e.g.:

```php
// before: "/api/admin/drivers/{$profile->id}/approve"
$response = $this->postJson("/api/admin/drivers/{$profile->user->public_id}/approve");
// show:
$response = $this->getJson("/api/admin/drivers/{$profile->user->public_id}");
```

(Adjust all 5 lifecycle calls + show. If the test references `DriverProfileResource` `id`, expect `user->public_id` now — see Task 6.)

- [ ] **Step 2: Run test — expect fail** (route-model binding/signature mismatch)

Run: `vendor/bin/pest tests/Feature/Admin/AdminDriverLifecycleTest.php`

- [ ] **Step 3: Refactor controller method signatures**

Change `show/approve/reject/suspend/reinstate` from `DriverProfile $driverProfile` to `User $driverUser`, resolving the profile (admin has no policy call — `role:admin` gates the route):

```php
use App\Models\User;

public function show(Request $request, User $driverUser): JsonResponse
{
    $driverProfile = $driverUser->driverProfile;
    abort_unless($driverProfile !== null, 404);
    // ... unchanged body using $driverProfile
}

public function suspend(Request $request, User $driverUser): JsonResponse
{
    $driverProfile = $driverUser->driverProfile;
    abort_unless($driverProfile !== null, 404);
    // ... unchanged body using $driverProfile
}
```

Apply the same `$driverUser → $driverProfile` resolution to `approve`, `reject`, `reinstate`. The internal method bodies (service calls, `respondWithTransition`) are unchanged.

- [ ] **Step 4: Run test — expect pass**

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Http/Controllers/Api/Admin/DriverController.php tests/Feature/Admin/AdminDriverLifecycleTest.php
git add app/Http/Controllers/Api/Admin/DriverController.php tests/Feature/Admin/AdminDriverLifecycleTest.php
git commit -m "refactor(driver): AdminDriverController binds User, resolves profile"
```

---

## Task 5: Refactor office controllers (onboarding + documents)

**Files:**
- Modify: `app/Http/Controllers/Api/Office/DriverOnboardingController.php` (`verifyPhone`, `submit`)
- Modify: `app/Http/Controllers/Api/Office/DriverDocumentController.php` (`store`, `destroy`)
- Test: `tests/Feature/Office/DriverOnboardingTest.php`, `tests/Feature/Office/DriverDocumentTest.php` (existing — update)

- [ ] **Step 1: Update both feature tests to new URLs**

Driver document + onboarding tests: replace `{$profile->id}` with `{$profile->user->public_id}`; for document delete replace the document id with the type value, e.g. `"/api/office/drivers/{$profile->user->public_id}/documents/national_id_front"`.

- [ ] **Step 2: Run tests — expect fail**

Run: `vendor/bin/pest tests/Feature/Office/DriverOnboardingTest.php tests/Feature/Office/DriverDocumentTest.php`

- [ ] **Step 3: Refactor `DriverOnboardingController::verifyPhone` + `submit`**

Signature `DriverProfile $driverProfile` → `User $driverUser`; resolve + preserve the existing `WRONG_OFFICE` structured response:

```php
public function verifyPhone(VerifyDriverPhoneRequest $request, User $driverUser, OtpService $otp): Response|JsonResponse
{
    $driverProfile = $driverUser->driverProfile;
    abort_unless($driverProfile !== null, 404);

    $staff = $request->user();
    if (! $staff->can('manageInOffice', $driverProfile)) {
        return response()->json([
            'error' => DriverErrorCode::WrongOffice->value,
            'message' => 'Driver belongs to a different office.',
        ], DriverErrorCode::WrongOffice->httpStatus());
    }
    // ... unchanged body using $driverProfile
}
```

Apply the identical resolution + WRONG_OFFICE block to `submit`.

- [ ] **Step 4: Refactor `DriverDocumentController::store` + `destroy`**

`store`: signature `UploadDriverDocumentRequest $request, User $driverUser`; resolve profile + WRONG_OFFICE block as above; body unchanged.

`destroy`: bind the type enum and scope the lookup to the driver:

```php
use App\Enums\DriverDocumentType;
use App\Models\DriverDocument;

public function destroy(Request $request, User $driverUser, DriverDocumentType $documentType): Response|JsonResponse
{
    $driverProfile = $driverUser->driverProfile;
    abort_unless($driverProfile !== null, 404);

    $staff = $request->user();
    if (! $staff->can('manageInOffice', $driverProfile)) {
        return response()->json([
            'error' => DriverErrorCode::WrongOffice->value,
            'message' => 'Driver belongs to a different office.',
        ], DriverErrorCode::WrongOffice->httpStatus());
    }

    $document = DriverDocument::query()
        ->where('driver_id', $driverUser->id)
        ->where('document_type', $documentType->value)
        ->firstOrFail();

    // ... unchanged removal logic operating on $document
    return response()->noContent();
}
```

- [ ] **Step 5: Run tests — expect pass**

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Http/Controllers/Api/Office tests/Feature/Office
git add app/Http/Controllers/Api/Office tests/Feature/Office
git commit -m "refactor(driver): office controllers bind User, document delete by scoped type"
```

---

## Task 6: Driver resources (public identity, no in-resource query)

**Files:**
- Modify: `app/Http/Resources/DriverProfileResource.php`
- Modify: `app/Http/Resources/DriverProfileFullResource.php`
- Modify: `app/Http/Resources/DriverDocumentResource.php`
- Modify: controllers feeding them — eager-load (`AdminDriverController::index/show`)
- Test: `tests/Feature/Admin/AdminDriverResourceShapeTest.php` (new)

- [ ] **Step 1: Write the failing shape test**

```php
<?php

declare(strict_types=1);

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('driver index exposes user public_id as id and nested office, no internal ids', function (): void {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $profile = DriverProfile::factory()->create();

    $response = $this->getJson('/api/admin/drivers');
    $response->assertStatus(200);

    $row = $response->json('data.0');
    expect($row['id'])->toBe($profile->user->public_id);
    expect($row)->not->toHaveKey('user_id');
    expect($row)->not->toHaveKey('office_id');
});
```

- [ ] **Step 2: Run test — expect fail**

Run: `vendor/bin/pest tests/Feature/Admin/AdminDriverResourceShapeTest.php`

- [ ] **Step 3: Rewrite `DriverProfileResource::toArray`**

```php
return [
    'id' => $this->user?->public_id,
    'status' => $this->status->value,
    'user' => $this->relationLoaded('user') && $this->user !== null
        ? ['id' => $this->user->public_id, 'name' => $this->user->fullName()]
        : null,
    'office' => $this->relationLoaded('office') && $this->office !== null
        ? ['id' => $this->office->public_id, 'name' => $this->office->name]
        : null,
    // ... keep any remaining non-id fields already present
];
```

- [ ] **Step 4: Rewrite `DriverProfileFullResource::toArray`**

Remove the in-resource query (line 18). Use the eager-loaded `documents` relation. Fix `office` to public_id, drop top-level `office_id`, add `approved_by`:

```php
return [
    'id' => $this->user?->public_id,
    'status' => $this->status->value,
    'activity_status' => $this->activity_status->value,
    'office' => $this->relationLoaded('office') && $this->office !== null
        ? ['id' => $this->office->public_id, 'name' => $this->office->name]
        : null,
    'user' => $this->relationLoaded('user') && $this->user !== null ? [
        'id' => $this->user->public_id,
        'first_name' => $this->user->first_name,
        'last_name' => $this->user->last_name,
        'phone_number' => $this->user->phone_number,
        'phone_verified' => $this->user->phone_verified_at !== null,
        'email' => $this->user->email,
        'email_verified' => $this->user->email_verified_at !== null,
        'account_status' => $this->user->account_status->value,
    ] : null,
    'vehicle' => [
        'type' => $this->vehicle_type->value,
        'plate' => $this->vehicle_plate,
        'color' => $this->vehicle_color,
        'model' => $this->vehicle_model,
    ],
    'documents' => DriverDocumentResource::collection($this->whenLoaded('documents')),
    'audit' => [
        'created_at' => $this->created_at?->toIso8601String(),
        'approved_at' => $this->approved_at?->toIso8601String(),
        'approved_by' => $this->relationLoaded('approvedBy') && $this->approvedBy !== null
            ? ['id' => $this->approvedBy->public_id, 'name' => $this->approvedBy->fullName()]
            : null,
        'rejected_at' => $this->rejected_at?->toIso8601String(),
    ],
    'lifetime_deliveries' => $this->lifetime_deliveries,
    'rating_average' => $this->rating_average,
    'notes' => $this->notes,
];
```

- [ ] **Step 5: `DriverDocumentResource` — drop internal `id`**

Remove `'id' => $this->id,` from the returned array (keep `document_type` as the identifier). Leave the media-url logic intact.

- [ ] **Step 6: Eager-load in `AdminDriverController`**

- `index`: `->with(['user', 'office'])` (already present — confirm).
- `show`: load `['user', 'office', 'approvedBy', 'documents.driver.media']` on the resolved profile before returning `DriverProfileFullResource`:

```php
$driverProfile->load(['user', 'office', 'approvedBy', 'documents.driver.media']);
return response()->json((new DriverProfileFullResource($driverProfile))->resolve($request));
```

(Match the existing show response wrapper.)

- [ ] **Step 7: Run test + the driver feature tests — expect pass**

Run: `vendor/bin/pest tests/Feature/Admin tests/Feature/Office`

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint app/Http/Resources/DriverProfileResource.php app/Http/Resources/DriverProfileFullResource.php app/Http/Resources/DriverDocumentResource.php app/Http/Controllers/Api/Admin/DriverController.php tests/Feature/Admin/AdminDriverResourceShapeTest.php
git add -A
git commit -m "refactor(driver): resources expose user public_id, nested office, eager-loaded documents"
```

---

## Task 7: `OrderResource` return office (client-facing leak)

**Files:**
- Modify: `app/Http/Resources/Order/OrderResource.php` (~line 100)
- Modify: controller(s) feeding it — eager-load `returnOffice`
- Test: `tests/Feature/Order/OrderResourceReturnOfficeTest.php` (new)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('order return block exposes return_office public_id, not internal office_id', function (): void {
    $sender = User::factory()->create();
    Sanctum::actingAs($sender);
    $order = Order::factory()->returnedToOffice()->create(['sender_user_id' => $sender->id]);

    $response = $this->getJson("/api/me/orders/{$order->public_id}");
    $response->assertStatus(200);

    $ret = $response->json('data.return') ?? $response->json('return');
    expect($ret)->not->toBeNull();
    expect($ret)->not->toHaveKey('office_id');
    expect($ret['return_office']['id'])->toBe($order->returnOffice->public_id);
});
```

> If no `returnedToOffice` factory state exists, set the order's `status` + `return_office_id` inline to satisfy the resource's return-block guard.

- [ ] **Step 2: Run test — expect fail**

Run: `vendor/bin/pest tests/Feature/Order/OrderResourceReturnOfficeTest.php`

- [ ] **Step 3: Replace the leak in `OrderResource`**

In the return block (`$base`), replace `'office_id' => $o->return_office_id,` with:

```php
'return_office' => $o->relationLoaded('returnOffice') && $o->returnOffice !== null
    ? ['id' => $o->returnOffice->public_id, 'name' => $o->returnOffice->name]
    : null,
```

- [ ] **Step 4: Eager-load `returnOffice`**

In the controllers that return `OrderResource` for a single order / list (e.g. `Api/Order/OrderController::show`/`index`, `Api/Me/Order/*`), add `returnOffice` to the `->load(...)`/`->with(...)` set.

- [ ] **Step 5: Run test — expect pass**

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Http/Resources/Order/OrderResource.php tests/Feature/Order/OrderResourceReturnOfficeTest.php
git add -A
git commit -m "fix(order): expose return_office public_id to clients, not internal office_id"
```

---

## Task 8: Admin + office order resources

**Files:**
- Modify: `app/Http/Resources/Order/AdminOrderResource.php`
- Modify: `app/Http/Resources/Order/OfficeOrderResource.php`
- Modify: feeding controllers — eager-load `sender`, `receiverUser`, `receiverGuest`, `driver`, `returnOffice`
- Test: `tests/Feature/Order/AdminOrderResourceShapeTest.php` (new)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('admin order resource exposes public ids, no internal user/driver ids', function (): void {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $sender = User::factory()->create();
    $order = Order::factory()->create(['sender_user_id' => $sender->id]);

    $row = $this->getJson("/api/admin/orders/{$order->public_id}")->json('data')
        ?? $this->getJson("/api/admin/orders/{$order->public_id}")->json();

    expect(data_get($row, 'sender.id'))->toBe($sender->public_id);
    expect($row['sender'] ?? [])->not->toHaveKey('user_id');
});
```

- [ ] **Step 2: Run test — expect fail**

Run: `vendor/bin/pest tests/Feature/Order/AdminOrderResourceShapeTest.php`

- [ ] **Step 3: Reshape `AdminOrderResource`**

Replace the internal-id fields with nested public objects:
- sender block: `'user_id' => $o->sender_user_id` → `'id' => $o->sender?->public_id, 'name' => $o->sender?->fullName()` (guard with `relationLoaded`).
- receiver block: `'user_id' => $o->receiver_user_id` → `'id' => $o->receiverUser?->public_id, 'name' => $o->receiverUser?->fullName()`; `'guest_id' => $o->receiver_guest_id` → `'id' => $o->receiverGuest?->public_id`.
- driver block: `'user_id' => $o->driver_id` → `'id' => $o->driver?->public_id, 'name' => $o->driver?->fullName()`.

- [ ] **Step 4: Reshape `OfficeOrderResource`**

- sender `user_id` → `{id,name}` (public_id) via `$order->sender`.
- return `office_id` → `'office' => ['id' => $order->returnOffice?->public_id, 'name' => $order->returnOffice?->name]`.
- `driver_id` → `{id,name}` via `$order->driver`.

- [ ] **Step 5: Eager-load in feeding controllers**

`Api/Admin/OrderController` and `Api/Office/Order/OrderController` (index/show): add `['sender', 'receiverUser', 'receiverGuest', 'driver', 'returnOffice']` to the eager-load set.

- [ ] **Step 6: Run test + order suites — expect pass**

Run: `vendor/bin/pest tests/Feature/Order tests/Feature/Admin tests/Feature/Office`

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint app/Http/Resources/Order tests/Feature/Order
git add -A
git commit -m "refactor(order): admin+office order resources use nested public ids"
```

---

## Task 9: Metadata write-time public summaries

**Files:**
- Create: `app/Support/OrderStatusLogMetadata.php` (key constants + sanitizer; sanitizer used in Task 10)
- Modify: every site writing `order_status_logs.metadata` with internal ids
- Test: `tests/Unit/Support/OrderStatusLogMetadataTest.php`

- [ ] **Step 1: Enumerate metadata writers**

Run: `grep -rn "metadata" app/Services app/Http/Controllers | grep -i "status_log\|OrderStatusLog\|'metadata'"`
Also: `grep -rn "OrderStatusLog::create\|statusLogs()->create\|->logs()->create" app`
Record each site that puts an internal id into metadata (known: redirect-return writes `previous_office_id` / `new_office_id`). List them in the commit message.

- [ ] **Step 2: Write the failing test for the key constants + sanitizer**

```php
<?php

declare(strict_types=1);

use App\Support\OrderStatusLogMetadata;

it('drops unknown *_id keys and keeps allowlisted keys', function (): void {
    $clean = OrderStatusLogMetadata::sanitize([
        'previous_office_public_id' => '01ABC',
        'new_office_public_id' => '01DEF',
        'reason_note' => 'redirected',
        'previous_office_id' => 42,        // internal — must be dropped
        'some_other_id' => 7,              // unknown *_id — must be dropped
    ]);

    expect($clean)->toBe([
        'previous_office_public_id' => '01ABC',
        'new_office_public_id' => '01DEF',
        'reason_note' => 'redirected',
    ]);
});
```

- [ ] **Step 3: Run test — expect fail**

Run: `vendor/bin/pest tests/Unit/Support/OrderStatusLogMetadataTest.php`

- [ ] **Step 4: Implement the support class**

```php
<?php

declare(strict_types=1);

namespace App\Support;

final class OrderStatusLogMetadata
{
    /**
     * Keys explicitly allowed through to the API. Public-id summaries and
     * known-safe descriptive keys. Extend this list when a new metadata
     * writer adds a key (see Task 9 enumeration).
     *
     * @var array<int, string>
     */
    public const ALLOWLIST = [
        'previous_office_public_id',
        'new_office_public_id',
        'reason_note',
        'fault',
        'waiver_amount',
        'bypass_reason',
    ];

    /**
     * Read-time guard: keep allowlisted keys; drop everything else, and
     * defensively drop ANY remaining key ending in `_id` (fail-closed) so a
     * future writer cannot silently leak an internal id. No DB queries.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    public static function sanitize(?array $metadata): array
    {
        if ($metadata === null) {
            return [];
        }

        $clean = [];
        foreach ($metadata as $key => $value) {
            if (! in_array($key, self::ALLOWLIST, true)) {
                continue;
            }
            if (str_ends_with($key, '_id') && ! str_ends_with($key, '_public_id')) {
                continue; // fail-closed on internal-id-shaped keys
            }
            $clean[$key] = $value;
        }

        return $clean;
    }
}
```

- [ ] **Step 5: Run test — expect pass**

- [ ] **Step 6: Add write-time public summaries at each enumerated writer**

For each writer that stores an internal office/user/driver id in metadata, **also** store the `public_id` summary key. Example for the redirect-return writer (replace the internal-id keys with public summaries):

```php
// before: 'metadata' => ['previous_office_id' => $old->id, 'new_office_id' => $new->id]
'metadata' => [
    'previous_office_public_id' => $old->public_id,
    'new_office_public_id' => $new->public_id,
    // ...any existing non-id keys preserved
],
```

Apply the analogous change at every site found in Step 1. If a site stores a driver/user internal id, add `*_public_id` equivalents and extend `ALLOWLIST`.

- [ ] **Step 7: Run the order/admin suites — expect pass**

Run: `vendor/bin/pest tests/Feature/Order tests/Feature/Admin`

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint app/Support/OrderStatusLogMetadata.php app/Services app/Http/Controllers tests/Unit/Support/OrderStatusLogMetadataTest.php
git add -A
git commit -m "feat(order): write-time public-id summaries in status-log metadata + sanitizer"
```

---

## Task 10: `OrderStatusLogResource` — actor + sanitized metadata

**Files:**
- Modify: `app/Http/Resources/Order/OrderStatusLogResource.php`
- Modify: feeding controller — eager-load `actor` (if a relation exists) or resolve actor name safely
- Test: `tests/Feature/Order/OrderStatusLogMetadataLeakTest.php` (new)

- [ ] **Step 1: Write the failing regression test**

```php
<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('status log never emits internal *_id keys in metadata or actor', function (): void {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $order = Order::factory()->create();
    OrderStatusLog::factory()->create([
        'order_id' => $order->id,
        'actor_id' => $admin->id,
        'metadata' => ['previous_office_public_id' => '01ABC', 'previous_office_id' => 42],
    ]);

    $body = $this->getJson("/api/admin/orders/{$order->public_id}")->json();
    $log = data_get($body, 'data.status_logs.0') ?? data_get($body, 'status_logs.0');

    expect($log['metadata'] ?? [])->not->toHaveKey('previous_office_id');
    expect($log)->not->toHaveKey('actor_id');
    expect(data_get($log, 'actor.id'))->toBe($admin->public_id);
});
```

> Adjust the JSON path (`status_logs`) to wherever `OrderStatusLogResource` is embedded in the admin order detail. If logs aren't embedded there, target the endpoint that renders them.

- [ ] **Step 2: Run test — expect fail**

Run: `vendor/bin/pest tests/Feature/Order/OrderStatusLogMetadataLeakTest.php`

- [ ] **Step 3: Rewrite `OrderStatusLogResource::toArray`**

```php
use App\Support\OrderStatusLogMetadata;

return [
    'from_status' => $l->from_status?->value,
    'to_status' => $l->to_status->value,
    'actor_type' => $l->actor_type->value,
    'actor' => $l->relationLoaded('actor') && $l->actor !== null
        ? ['id' => $l->actor->public_id, 'name' => $l->actor->fullName()]
        : null,
    'reason' => $l->reason,
    'metadata' => OrderStatusLogMetadata::sanitize($l->metadata),
    'actor_location' => $l->actor_location
        ? ['lat' => (float) $l->actor_location->getLatitude(), 'lng' => (float) $l->actor_location->getLongitude()]
        : null,
    'created_at' => $l->created_at?->toIso8601String(),
];
```

> If `OrderStatusLog` has no `actor()` relation, add `public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }` to the model (actor_type is always a user here) and eager-load it in Step 4.

- [ ] **Step 4: Eager-load `actor`** on the controller/query that loads status logs (e.g. `->load('statusLogs.actor')`).

- [ ] **Step 5: Run test — expect pass**

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Http/Resources/Order/OrderStatusLogResource.php app/Models/OrderStatusLog.php tests/Feature/Order/OrderStatusLogMetadataLeakTest.php
git add -A
git commit -m "fix(order): status-log resource sanitizes metadata + nested actor public id"
```

---

## Task 11: Settlement / payout resources (nested office public_id)

**Files:**
- Modify: `app/Http/Resources/Settlement/SettlementResource.php` (~22)
- Modify: `app/Http/Resources/Settlement/SellerPayoutResource.php` (~18)
- Modify: `app/Http/Resources/Settlement/AdminSellerPayoutResource.php` (~23)
- Test: `tests/Feature/Settlement/SettlementResourceShapeTest.php` (new)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Settlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('admin settlement resource exposes office public_id', function (): void {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $settlement = Settlement::factory()->create();

    $row = $this->getJson("/api/admin/settlements/{$settlement->public_id}")->json('data')
        ?? $this->getJson("/api/admin/settlements/{$settlement->public_id}")->json();

    expect(data_get($row, 'office.id'))->toBe($settlement->office->public_id);
});
```

- [ ] **Step 2: Run test — expect fail**

Run: `vendor/bin/pest tests/Feature/Settlement/SettlementResourceShapeTest.php`

- [ ] **Step 3: Fix the nested office id in all three resources**

In each, change `'id' => $this->office?->id,` → `'id' => $this->office?->public_id,` (keep the `name`). Confirm `SettlementPreviewResource` / `SellerEarningResource` need no change (already `order_id => public_id`).

- [ ] **Step 4: Confirm `office` eager-loaded** on the settlement/payout controllers (add `'office'` to `with`/`load` if missing).

- [ ] **Step 5: Run test + settlement suite — expect pass**

Run: `vendor/bin/pest tests/Feature/Settlement`

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Http/Resources/Settlement tests/Feature/Settlement
git add -A
git commit -m "fix(settlement): nested office uses public_id in settlement/payout resources"
```

---

## Task 12: `OfficeInventoryResource` (office + audit attribution)

**Files:**
- Modify: `app/Http/Resources/Order/OfficeInventoryResource.php`
- Modify: `app/Http/Controllers/Api/Office/Order/OrderController` — eager-load the inventory + its relations (the resource is **embedded** in `OfficeOrderResource` at line ~65 as the `inventory` key, rendered via `->toArray()` on a single `$inventory`)
- Test: `tests/Feature/Office/OfficeInventoryResourceShapeTest.php` (new)

> `OfficeInventoryResource` has no endpoint of its own — it is embedded inside `OfficeOrderResource` (`'inventory' => (new OfficeInventoryResource($inventory))->toArray($request)`). So the shape is asserted through the office-order detail endpoint (`GET /api/office/orders/{order:public_id}`), under `inventory`.

- [ ] **Step 1: Write the failing test** (assert via the office-order endpoint's `inventory` block)

```php
<?php

declare(strict_types=1);

use App\Models\OfficeInventory;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('embedded office inventory uses public ids + nested audit attribution', function (): void {
    Role::findOrCreate('office_staff', 'web');
    $staff = User::factory()->create();
    $staff->assignRole('office_staff');

    // An order returned to the staff's office, with an inventory row received by them.
    $inv = OfficeInventory::factory()->create(['received_by_staff_id' => $staff->id]);
    $order = $inv->order;
    // Ensure $staff is actively assigned to $inv->office so the office policy allows view.
    $staff->officeStaffAssignments()->create([
        'office_id' => $inv->office_id, 'is_manager' => false, 'assigned_at' => now(),
    ]);
    Sanctum::actingAs($staff);

    $body = $this->getJson("/api/office/orders/{$order->public_id}")->json();
    $row = data_get($body, 'data.inventory') ?? data_get($body, 'inventory');

    expect($row)->not->toBeNull();
    expect($row)->not->toHaveKey('office_id');
    expect($row)->not->toHaveKey('received_by_staff_id');
    expect(data_get($row, 'office.id'))->toBe($inv->office->public_id);
    expect(data_get($row, 'received_by.id'))->toBe($staff->public_id);
});
```

> If the factory wiring (`OfficeInventory::factory()` → order/office) differs, set `order_id`/`office_id` explicitly so the office-order endpoint returns the embedded inventory. The assertion shape is the contract.

- [ ] **Step 2: Run test — expect fail**

Run: `vendor/bin/pest tests/Feature/Office/OfficeInventoryResourceShapeTest.php`

- [ ] **Step 3: Reshape `OfficeInventoryResource`**

- `office_id` → `'office' => ['id' => $inventory->office?->public_id, 'name' => $inventory->office?->name]`.
- `received_by_staff_id` → `'received_by' => $inventory->receivedByStaff ? ['id' => $inventory->receivedByStaff->public_id, 'name' => $inventory->receivedByStaff->fullName()] : null`.
- `retrieved_by_staff_id` → `'retrieved_by' => ...` (same pattern via `retrievedByStaff`).
- `abandoned_by_admin_id` → `'abandoned_by' => ...` (via `abandonedByAdmin`).

- [ ] **Step 4: Eager-load** the inventory chain on the office-order show query in `Api/Office/Order/OrderController`: add `'inventory.office'`, `'inventory.receivedByStaff'`, `'inventory.retrievedByStaff'`, `'inventory.abandonedByAdmin'` to the `->load(...)` set (whatever relation name the order uses for its inventory — confirm via the model; likely `officeInventory` or `inventory`).

- [ ] **Step 5: Run test — expect pass**

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Http/Resources/Order/OfficeInventoryResource.php tests/Feature/Office/OfficeInventoryResourceShapeTest.php
git add -A
git commit -m "refactor(inventory): office + audit attribution as nested public ids"
```

---

## Task 13: `RegionController` inline office object

**Files:**
- Modify: `app/Http/Controllers/Api/Driver/RegionController.php` (~34)
- Test: `tests/Feature/Driver/DriverRegionsResponseTest.php` (new or existing)

- [ ] **Step 1: Write/extend the failing test** — assert the regions response has `office.id` = office public_id and no top-level `office_id`.

```php
<?php

declare(strict_types=1);

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('driver regions response exposes office public id', function (): void {
    Role::findOrCreate('driver', 'web');
    $profile = DriverProfile::factory()->create();
    $user = $profile->user;
    $user->assignRole('driver');
    Sanctum::actingAs($user);

    $body = $this->getJson('/api/driver/regions')->json();
    expect($body)->not->toHaveKey('office_id');
    expect(data_get($body, 'office.id'))->toBe($profile->office->public_id);
});
```

- [ ] **Step 2: Run test — expect fail**

Run: `vendor/bin/pest tests/Feature/Driver/DriverRegionsResponseTest.php`

- [ ] **Step 3: Replace the inline office fields**

In `index()`, replace `'office_id' => $profile->office_id,` and `'office_name' => $profile->office?->name,` with:

```php
'office' => $profile->office !== null
    ? ['id' => $profile->office->public_id, 'name' => $profile->office->name]
    : null,
```

- [ ] **Step 4: Run test — expect pass**

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Http/Controllers/Api/Driver/RegionController.php tests/Feature/Driver/DriverRegionsResponseTest.php
git add -A
git commit -m "fix(driver): regions response exposes office public id"
```

---

## Task 14: `AccountController` + `DriverAccountTransactionResource`

**Files:**
- Create: `app/Http/Resources/Driver/DriverAccountTransactionResource.php`
- Modify: `app/Http/Controllers/Api/Driver/AccountController.php`
- Test: `tests/Feature/Driver/DriverAccountResponseTest.php` (new)

- [ ] **Step 1: Write the failing test** — assert transactions don't expose an `id`.

```php
<?php

declare(strict_types=1);

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('driver account transactions expose no internal id', function (): void {
    Role::findOrCreate('driver', 'web');
    $profile = DriverProfile::factory()->create();
    $user = $profile->user;
    $user->assignRole('driver');
    // ensure a driver account + a transaction exist (factory/helper as available)
    Sanctum::actingAs($user);

    $txns = $this->getJson('/api/driver/account')->json('transactions');
    if (! empty($txns)) {
        expect($txns[0])->not->toHaveKey('id');
        expect($txns[0])->toHaveKeys(['bucket', 'amount', 'reason', 'balance_after', 'created_at']);
    } else {
        expect($txns)->toBeArray();
    }
});
```

- [ ] **Step 2: Run test — expect fail** (currently exposes `id`)

Run: `vendor/bin/pest tests/Feature/Driver/DriverAccountResponseTest.php`

- [ ] **Step 3: Create the resource**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Driver;

use App\Models\DriverAccountTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DriverAccountTransaction */
final class DriverAccountTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'bucket' => $this->bucket instanceof \BackedEnum ? $this->bucket->value : $this->bucket,
            'amount' => (string) $this->amount,
            'reason' => $this->reason instanceof \BackedEnum ? $this->reason->value : $this->reason,
            'balance_after' => (string) $this->balance_after,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

> Check the actual casts for `bucket`/`reason` in `DriverAccountTransaction`; if they are plain strings, drop the `instanceof` guard. Keep the output stable.

- [ ] **Step 4: Use it in `AccountController`**

```php
use App\Http\Resources\Driver\DriverAccountTransactionResource;

$transactions = $user->driverAccountTransactions()
    ->latest()
    ->limit(30)
    ->get();   // no longer selecting 'id' explicitly

return response()->json([
    'account' => (new DriverAccountResource($account))->resolve($request),
    'transactions' => DriverAccountTransactionResource::collection($transactions)->resolve($request),
]);
```

- [ ] **Step 5: Run test — expect pass**

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Http/Resources/Driver app/Http/Controllers/Api/Driver/AccountController.php tests/Feature/Driver/DriverAccountResponseTest.php
git add -A
git commit -m "fix(driver): account transactions via resource, no internal id leak"
```

---

## Task 15: `ShowEarningsController` seller_id

**Files:**
- Modify: `app/Http/Controllers/Api/Me/Settlement/ShowEarningsController.php` (~27)
- Test: `tests/Feature/Settlement/ShowEarningsTest.php` (new or existing)

- [ ] **Step 1: Write/extend the failing test** — assert the earnings response has no internal `seller_id`.

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('earnings response does not leak internal seller_id', function (): void {
    $seller = User::factory()->create();
    Sanctum::actingAs($seller);

    $body = $this->getJson('/api/me/earnings')->json();
    expect($body)->not->toHaveKey('seller_id');
});
```

- [ ] **Step 2: Run test — expect fail**

Run: `vendor/bin/pest tests/Feature/Settlement/ShowEarningsTest.php`

- [ ] **Step 3: Remove the leak**

Delete the `'seller_id' => $user->id,` line (the caller is the authenticated seller; the field is redundant). If a value is needed downstream, use `$user->public_id` instead.

- [ ] **Step 4: Run test — expect pass**

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Http/Controllers/Api/Me/Settlement/ShowEarningsController.php tests/Feature/Settlement/ShowEarningsTest.php
git add -A
git commit -m "fix(earnings): drop internal seller_id from response"
```

---

## Task 16: Inbound — staff + office assignment requests

**Files:**
- Modify: `app/Http/Requests/Staff/CreateStaffRequest.php`
- Modify: `app/Http/Requests/Staff/AttachOfficeAssignmentRequest.php`
- Modify: `app/Http/Controllers/Api/Admin/Staff/OfficeAssignmentController.php` + `StaffService`/`OfficeAssignmentService` resolution
- Test: `tests/Feature/Staff/StaffOfficeAssignmentInboundTest.php` (new)

- [ ] **Step 1: Write the failing test** — creating office_staff / attaching by `office_public_id` works; a bare internal id is rejected 422.

```php
<?php

declare(strict_types=1);

use App\Models\OfficeLocation;
use App\Models\Region;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('attaches an office assignment by office_public_id, rejects internal id', function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $region = Region::factory()->create();
    $office = OfficeLocation::create([
        'region_id' => $region->id, 'name' => 'O', 'address' => 'a',
        'location' => Point::makeGeodetic(32.0, 13.0), 'is_active' => true,
    ]);
    $staff = User::factory()->create();
    $staff->assignRole('office_staff');

    $ok = $this->postJson("/api/admin/staff/{$staff->public_id}/office-assignments", [
        'office_public_id' => $office->public_id,
        'is_manager' => false,
    ]);
    $ok->assertStatus(201);

    $bad = $this->postJson("/api/admin/staff/{$staff->public_id}/office-assignments", [
        'office_public_id' => (string) $office->id,
    ]);
    $bad->assertStatus(422);
});
```

- [ ] **Step 2: Run test — expect fail**

Run: `vendor/bin/pest tests/Feature/Staff/StaffOfficeAssignmentInboundTest.php`

- [ ] **Step 3: `AttachOfficeAssignmentRequest` — accept public id**

```php
public function rules(): array
{
    return [
        'office_public_id' => ['required', 'string', 'exists:office_locations,public_id'],
        'is_manager' => ['sometimes', 'boolean'],
    ];
}

public function officeId(): int
{
    return \App\Support\Resolvers\PublicIdResolver::officeId($this->string('office_public_id')->toString());
}
```

Update `OfficeAssignmentController::store` to call `$request->officeId()` instead of `$request->integer('office_id')`.

- [ ] **Step 4: `CreateStaffRequest` — accept public id in office_assignments**

```php
'office_assignments.*.office_public_id' => ['required', 'string', 'exists:office_locations,public_id'],
'office_assignments.*.is_manager' => ['required', 'boolean'],
```

In `toInput()`, resolve each entry's `office_public_id` → internal `office_id` via `PublicIdResolver::officeId(...)` so `CreateStaffInput.officeAssignments` keeps the `['office_id' => int, 'is_manager' => bool]` shape the service expects. (No service change needed.)

- [ ] **Step 5: Run test + staff suite — expect pass**

Run: `vendor/bin/pest tests/Feature/Staff`

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Http/Requests/Staff app/Http/Controllers/Api/Admin/Staff tests/Feature/Staff/StaffOfficeAssignmentInboundTest.php
git add -A
git commit -m "refactor(staff): inbound office ids are public_id (create + attach)"
```

---

## Task 17: Inbound — preregister, redirect-return, admin-assign

**Files:**
- Modify: `app/Http/Requests/Driver/PreregisterDriverRequest.php`
- Modify: `app/Http/Requests/Order/RedirectReturnRequest.php`
- Modify: `app/Http/Requests/Order/AdminAssignOrderRequest.php`
- Modify: the controllers/services consuming those fields
- Test: extend the relevant existing feature tests

- [ ] **Step 1: Update the three feature tests** to submit `office_public_id` / `driver_public_id` (and a negative case rejecting a bare internal id) for: preregistration, admin order redirect-return, admin order assign.

- [ ] **Step 2: Run them — expect fail**

Run: `vendor/bin/pest tests/Feature/Driver tests/Feature/Admin`

- [ ] **Step 3: `PreregisterDriverRequest`**

```php
'office_public_id' => ['required', 'string', Rule::exists('office_locations', 'public_id')],
```
Resolve to internal id where the request feeds the service (add an `officeId()` accessor using `PublicIdResolver::officeId(...)`, update the consuming controller/service call). Keep the existing `is_active` intent by validating with a closure or resolving then checking `is_active` in the service (preserve current behavior: only active offices). Simplest: keep `Rule::exists('office_locations','public_id')->where('is_active', true)`.

- [ ] **Step 4: `RedirectReturnRequest`**

```php
'office_public_id' => ['required', 'string', 'exists:office_locations,public_id'],
```
Add `officeId(): int` accessor; update the admin redirect-return controller/service to use it.

- [ ] **Step 5: `AdminAssignOrderRequest`**

```php
'driver_public_id' => ['required', 'string', 'exists:users,public_id'],
```
Add `driverUserId(): int` accessor via `PublicIdResolver::userId(...)`; update `AdminAssignmentService` call site to pass the resolved user/id (the service still works with the internal id/user). Confirm the assigned user has the `driver` role as the service already validates.

- [ ] **Step 6: Run tests — expect pass**

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint app/Http/Requests/Driver app/Http/Requests/Order app/Http/Controllers app/Services tests/Feature/Driver tests/Feature/Admin
git add -A
git commit -m "refactor(inbound): preregister/redirect/assign accept public ids"
```

---

## Task 18: Inbound — index filters (settlements, payouts, drivers, staff)

**Files:**
- Modify: `app/Http/Requests/Settlement/ListSettlementsRequest.php`, `ListSellerPayoutsRequest.php`
- Create: `app/Http/Requests/Driver/IndexDriverRequest.php`, `app/Http/Requests/Staff/IndexStaffRequest.php`
- Modify: `AdminDriverController::index`, `StaffController::index`, and the settlement/payout list controllers
- Test: `tests/Feature/*` filter tests (new/extended)

- [ ] **Step 1: Write failing filter tests** — each index endpoint filters by `office_public_id` and returns only that office's rows; a bare internal id yields 422.

```php
// Example for staff index:
it('staff index filters by office_public_id', function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');
    $admin = User::factory()->create(); $admin->assignRole('admin');
    Sanctum::actingAs($admin);
    $region = Region::factory()->create();
    $office = OfficeLocation::create(['region_id'=>$region->id,'name'=>'O','address'=>'a','location'=>Point::makeGeodetic(32.0,13.0),'is_active'=>true]);

    $this->getJson("/api/admin/staff?office_public_id={$office->public_id}")->assertStatus(200);
    $this->getJson('/api/admin/staff?office_public_id=999')->assertStatus(422);
});
```

- [ ] **Step 2: Run — expect fail**

- [ ] **Step 3: `ListSettlementsRequest` / `ListSellerPayoutsRequest`**

Replace `'office_id' => ['nullable','integer','min:1']` with:
```php
'office_public_id' => ['nullable', 'string', 'exists:office_locations,public_id'],
```
Add `officeId(): ?int { return \App\Support\Resolvers\PublicIdResolver::officeId($this->input('office_public_id')); }`. Update the list controllers to filter by the resolved id.

- [ ] **Step 4: Create `IndexDriverRequest`** (admin driver index)

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Support\Resolvers\PublicIdResolver;
use Illuminate\Foundation\Http\FormRequest;

final class IndexDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string'],
            'search' => ['sometimes', 'string', 'max:120'],
            'office_public_id' => ['sometimes', 'string', 'exists:office_locations,public_id'],
        ];
    }

    public function officeId(): ?int
    {
        return PublicIdResolver::officeId($this->input('office_public_id'));
    }
}
```

Change `AdminDriverController::index(Request $request)` → `index(IndexDriverRequest $request)`, and replace the `->when($request->input('office_id'), ...)` clause with `->when($request->officeId(), fn ($q, $id) => $q->where('office_id', $id))`.

- [ ] **Step 5: Create `IndexStaffRequest`** (staff index) — same pattern, `authorize()` returns admin, rules allow `role`, `account_status`, `per_page`, `office_public_id`. Change `StaffController::index()` to type-hint it and use `$request->officeId()` in the `activeOfficeAssignments` filter; replace `request('office_id')` usage.

- [ ] **Step 6: Run tests + full staff/driver/settlement suites — expect pass**

Run: `vendor/bin/pest tests/Feature/Staff tests/Feature/Admin tests/Feature/Settlement`

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint app/Http/Requests app/Http/Controllers tests/Feature
git add -A
git commit -m "refactor(inbound): index office filters accept public_id via FormRequests"
```

---

## Task 19: Document the Region/ServiceArea exception

**Files:**
- Modify: `docs/CLAUDE.md` (Key Conventions → Public IDs)

- [ ] **Step 1: Add the exception note**

Under the "Public IDs" convention block, append:

```markdown
**Exception — reference/lookup tables:** `regions` and `service_areas` intentionally have **no** `public_id`. They are stable, non-sensitive geographic reference data (not user/business records), so exposing or accepting their numeric `id` is an accepted, deliberate exception to Critical Rule 11. Do not add `public_id` to these tables.
```

- [ ] **Step 2: Commit**

```bash
git add docs/CLAUDE.md
git commit -m "docs: record regions/service_areas public_id exemption"
```

---

## Task 20: Final verification + PR

- [ ] **Step 1: Full Pest suite**

Run: `vendor/bin/pest`
Expected: all green.

- [ ] **Step 2: Grep regression sweep — no internal ids remain in the API surface**

```bash
grep -rn "?->id\b" app/Http/Resources                       # expect: none
grep -rnE "'[a-z_]*_id'\s*=>|=>\s*\\\$[a-z_]+->id\b" app/Http/Controllers   # expect: none (except documented)
grep -rnE "exists:office_locations,id|exists:users,id" app/Http/Requests    # expect: none
```
Expected: empty (or only the documented `regions` exception).

- [ ] **Step 3: Orders e2e regression**

Run: `php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"`
Expected: ALL scenarios pass (32).

- [ ] **Step 4: Staff e2e regression**

Run: `php artisan tinker --execute="require base_path('scripts/staff-e2e.php');"`
Expected: all scenarios pass.

- [ ] **Step 5: Final Pint**

Run: `vendor/bin/pint`
Expected: clean (ignore pre-existing unrelated files outside this milestone, as in prior milestones).

- [ ] **Step 6: Push + PR**

```bash
git push -u origin claude/id-exposure-remediation
gh pr create --title "refactor(api): internal-id exposure remediation" --body "Implements docs/superpowers/specs/2026-05-31-id-exposure-remediation-design.md. Drivers addressed by User.public_id; outbound FK ids -> nested {id,name} public_id; inbound ids -> *_public_id; status-log metadata sanitized; Region/ServiceArea documented exception. Breaking request-contract changes (no production client). Full Pest + orders-e2e + staff-e2e green."
```
(If `gh` is unavailable, push and open via the GitHub UI.)

- [ ] **Step 7: Update docs post-merge** — after merge, add a `SYSTEM_SPECIFICATION.md` §17.x entry + `CLAUDE.md` "Current Project State" note summarizing the remediation (per the doc-progress convention).

---

## Notes for the executor
- The driver-addressing refactor (Tasks 3–6) leaves the suite red between Task 3 and the end of Task 6 — do not run the full suite mid-refactor; run the targeted driver tests as each task instructs, and the full suite at Task 20.
- Several tasks say "locate the endpoint rendering X" — use `grep -rn <ResourceName> app/Http/Controllers` to find the exact controller/JSON path before writing the assertion; the resource shape is the contract, the endpoint path is incidental.
- Keep services receiving internal ids/models — all public→internal resolution happens at the FormRequest/DTO boundary (Tasks 2, 16–18).
