# Staff CRUD — Slice B (Codex) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the office-assignment side of Staff CRUD: a service that manages the `office_staff_assignments` pivot, an admin controller exposing attach/detach endpoints, an e2e smoke script. Then integrate with Slice A after it merges by adding the three Slice B error codes to the enum, wiring the two new routes into the existing group, and replacing Slice A's `LogicException("slice-B")` stub in `StaffService::create()`.

**Architecture:** One service (`OfficeAssignmentService`) owns the pivot writes. Two HTTP endpoints under `POST/DELETE /api/admin/staff/{staff}/office-assignments`. All writes wrapped in `DB::transaction()`. Service throws `StaffDomainException` (file owned by Slice A) for the three business invariants: role mismatch, duplicate assignment, last-assignment-required. Integration tasks are clearly marked as **post-Slice-A-merge** — they must wait.

**Tech Stack:** Laravel 13 · PostgreSQL · Spatie Permission · Pest 4

**Prerequisites:**
- Spec at `docs/superpowers/specs/2026-05-20-staff-crud-design.md` is locked.
- Working in worktree `C:\Users\User\Desktop\delivary-app-codex\`, branch `codex/staff-crud-office-assignments` from `main`.
- Per-worktree git identity already configured (`Codex <codex@delivary.local>`).
- Postgres test DB `delivary_app_testing` is running.
- **Read the spec's §10 (Work division) before starting** — especially §10.5 (merge order) and §10.7 (coordination protocol).

---

## Two phases

This plan is in **two phases**:

- **Phase 1 (tasks 1–8)** — runs in parallel with Claude's Slice A. Builds Slice B components using stub error strings and raw `RuntimeException` because `StaffDomainException` + `StaffErrorCode` enum don't exist on this branch yet. All Slice B unit tests + feature tests + e2e smoke complete in this phase. PR is NOT opened yet.
- **Phase 2 (tasks 9–13)** — runs ONLY after Claude's Slice A merges to main. Rebase, add the three Slice B enum cases, swap `RuntimeException` for `StaffDomainException`, replace Claude's stub in `StaffService::create()`, wire routes, open PR.

If you try to run Phase 2 before Slice A merges, you will hit "class not found" errors on `StaffErrorCode` and `StaffDomainException`. **Do not skip ahead.**

---

## File structure (Slice B creates/modifies)

**Phase 1 (Codex builds in parallel with Slice A):**

- `database/migrations/2026_05_20_000010_add_public_id_to_office_staff_assignments.php` (only if column missing — Task 1 checks)
- `app/Models/OfficeStaffAssignment.php` (only modified if migration added — Task 1)
- `app/Services/Staff/OfficeAssignmentService.php` (uses RuntimeException stubs)
- `app/Http/Requests/Staff/AttachOfficeAssignmentRequest.php`
- `app/Http/Resources/Staff/OfficeAssignmentResource.php`
- `app/Http/Controllers/Api/Admin/Staff/OfficeAssignmentController.php` (uses RuntimeException stubs)
- All Slice B tests under `tests/Unit/Services/Staff` and `tests/Feature/Staff`
- `scripts/staff-e2e.php`

**Phase 2 (after Slice A merges):**

- `app/Enums/StaffErrorCode.php` — add 3 cases (`RoleMismatchForOfficeAssign`, `OfficeAssignmentDuplicate`, `OfficeAssignmentLastRequired`)
- `app/Services/Staff/OfficeAssignmentService.php` — swap `RuntimeException` for `StaffDomainException`
- `app/Services/Staff/StaffService.php` — replace `LogicException("slice-B")` stub with `$this->officeAssignments->attachMany(...)` call
- `app/Http/Resources/Staff/StaffResource.php` — replace the inline array with `OfficeAssignmentResource::collection(...)`
- `routes/api.php` — add 2 office-assignment routes inside Claude's `admin.staff.*` group
- Slice B's test files — update exception assertions from `RuntimeException` to `StaffDomainException`

---

# PHASE 1 — Build in parallel with Slice A

## Task 1: Verify or add `public_id` on `office_staff_assignments`

**Files:**
- Possibly create: `database/migrations/2026_05_20_000010_add_public_id_to_office_staff_assignments.php`
- Possibly modify: `app/Models/OfficeStaffAssignment.php`

- [ ] **Step 1: Check whether the column exists**

```bash
php artisan tinker --execute="echo \Schema::hasColumn('office_staff_assignments', 'public_id') ? 'YES' : 'NO';"
```

- [ ] **Step 2a: If output is YES**

Skip the migration. Just check `OfficeStaffAssignment.php` and confirm:
- `public_id` is in `$fillable`
- A `getRouteKeyName()` returns `'public_id'`
- The model has a `static::creating(...)` boot hook generating a ULID

If any of those is missing, add them. Otherwise skip to Task 2.

- [ ] **Step 2b: If output is NO**

Create the migration. This migration does THREE things in one file:
1. Add `public_id` ULID column (was missing).
2. **Drop the existing `unique(['user_id', 'office_id'])` constraint** — it blocks re-attach after soft-removal.
3. **Add a partial unique index** `(user_id, office_id) WHERE removed_at IS NULL` — uniqueness applies only to active assignments.

Reason: spec §13 item 4 — Codex's pre-implementation review confirmed the existing unique conflicts with the soft-removal design. Soft-removed rows must not count for uniqueness.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add public_id column (nullable for backfill)
        Schema::table('office_staff_assignments', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable()->after('id');
        });

        // 2. Backfill existing rows
        \App\Models\OfficeStaffAssignment::query()
            ->whereNull('public_id')
            ->orderBy('id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    $row->public_id = (string) \Illuminate\Support\Str::ulid();
                    $row->save();
                }
            });

        // 3. Add unique constraint on public_id + make non-nullable
        Schema::table('office_staff_assignments', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable(false)->unique()->change();
        });

        // 4. Drop the old unique(user_id, office_id) constraint — conflicts with soft-removal
        Schema::table('office_staff_assignments', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'office_id']);
        });

        // 5. Create the partial unique index — only active rows count toward uniqueness
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX office_staff_assignments_active_unique
                ON office_staff_assignments (user_id, office_id)
                WHERE removed_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS office_staff_assignments_active_unique');

        Schema::table('office_staff_assignments', function (Blueprint $table): void {
            $table->unique(['user_id', 'office_id']);
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
```

Run:

```bash
php artisan migrate
```

Then update `app/Models/OfficeStaffAssignment.php`:

```php
// Add to $fillable:
'public_id',

// Add boot hook:
protected static function booted(): void
{
    static::creating(function (self $assignment): void {
        if ($assignment->public_id === null || $assignment->public_id === '') {
            $assignment->public_id = (string) \Illuminate\Support\Str::ulid();
        }
    });
}

// Add route key:
public function getRouteKeyName(): string
{
    return 'public_id';
}
```

- [ ] **Step 3: Pint + commit**

```bash
vendor/bin/pint database/migrations app/Models/OfficeStaffAssignment.php
git add database/migrations app/Models/OfficeStaffAssignment.php
git commit -m "feat(staff): add public_id to office_staff_assignments + route key"
```

(If 2a fired, this commit is empty — skip the commit.)

---

## Task 2: `OfficeAssignmentService` with RuntimeException stubs

**Files:**
- Create: `app/Services/Staff/OfficeAssignmentService.php`
- Create: `tests/Unit/Services/Staff/OfficeAssignmentServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\OfficeLocation;
use App\Models\OfficeStaffAssignment;
use App\Models\Region;
use App\Models\User;
use App\Services\Staff\OfficeAssignmentService;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('office_staff', 'web');
    Role::findOrCreate('admin', 'web');
});

function makeOffice(): OfficeLocation
{
    $region = Region::query()->firstOrFail();

    return OfficeLocation::create([
        'region_id' => $region->id,
        'name' => 'Test Office '.uniqid(),
        'address' => 'Test address',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);
}

function makeOfficeStaffUser(): User
{
    $user = User::factory()->create();
    $user->assignRole('office_staff');

    return $user;
}

it('attaches an office assignment to an office_staff user', function (): void {
    $user = makeOfficeStaffUser();
    $office = makeOffice();

    $assignment = app(OfficeAssignmentService::class)->attach($user, $office->id, true);

    expect($assignment)->toBeInstanceOf(OfficeStaffAssignment::class);
    expect($assignment->user_id)->toBe($user->id);
    expect($assignment->office_id)->toBe($office->id);
    expect((bool) $assignment->is_manager)->toBeTrue();
    expect($assignment->removed_at)->toBeNull();
    expect($assignment->assigned_at)->not->toBeNull();
});

it('refuses to attach when user is not office_staff', function (): void {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $office = makeOffice();

    expect(fn () => app(OfficeAssignmentService::class)->attach($user, $office->id, false))
        ->toThrow(\RuntimeException::class, 'ROLE_MISMATCH_FOR_OFFICE_ASSIGN');
});

it('refuses duplicate active assignments', function (): void {
    $user = makeOfficeStaffUser();
    $office = makeOffice();

    app(OfficeAssignmentService::class)->attach($user, $office->id, false);

    expect(fn () => app(OfficeAssignmentService::class)->attach($user, $office->id, false))
        ->toThrow(\RuntimeException::class, 'OFFICE_ASSIGNMENT_DUPLICATE');
});

it('detaches an assignment by soft-removal', function (): void {
    $user = makeOfficeStaffUser();
    $o1 = makeOffice();
    $o2 = makeOffice();

    $a1 = app(OfficeAssignmentService::class)->attach($user, $o1->id, false);
    app(OfficeAssignmentService::class)->attach($user, $o2->id, false);

    app(OfficeAssignmentService::class)->detach($user, $a1);

    expect($a1->fresh()->removed_at)->not->toBeNull();
});

it('refuses to detach when it would leave zero active assignments', function (): void {
    $user = makeOfficeStaffUser();
    $office = makeOffice();
    $assignment = app(OfficeAssignmentService::class)->attach($user, $office->id, false);

    expect(fn () => app(OfficeAssignmentService::class)->detach($user, $assignment))
        ->toThrow(\RuntimeException::class, 'OFFICE_ASSIGNMENT_LAST_REQUIRED');
});

it('attachMany attaches multiple assignments in one call', function (): void {
    $user = makeOfficeStaffUser();
    $o1 = makeOffice();
    $o2 = makeOffice();

    $result = app(OfficeAssignmentService::class)->attachMany($user, [
        ['office_id' => $o1->id, 'is_manager' => false],
        ['office_id' => $o2->id, 'is_manager' => true],
    ]);

    expect($result)->toHaveCount(2);
    expect($user->activeOfficeAssignments()->count())->toBe(2);
});
```

NOTE on `activeOfficeAssignments()`: this relation is added by Slice A in Claude's StaffResource task. It may not exist on the model yet during Phase 1. If you get "Call to undefined method activeOfficeAssignments" while running this test, add the relation to `app/Models/User.php` locally on Codex's branch (Slice A will add the same method on Claude's branch — the merge will be a no-op because the methods are identical). Use the same code as in the spec §7.3.

- [ ] **Step 2: Run test — expect failure**

```bash
vendor/bin/pest tests/Unit/Services/Staff/OfficeAssignmentServiceTest.php
```

Expected: class not found.

- [ ] **Step 3: Create the service**

```php
<?php

declare(strict_types=1);

namespace App\Services\Staff;

use App\Models\OfficeStaffAssignment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SLICE B NOTE: This service throws RuntimeException with the error-code string
 * as the message during Phase 1. Phase 2 (after Slice A merges) swaps these for
 * App\Exceptions\Staff\StaffDomainException with App\Enums\StaffErrorCode cases.
 */
final class OfficeAssignmentService
{
    public function attach(User $staff, int $officeId, bool $isManager): OfficeStaffAssignment
    {
        if (! $staff->hasRole('office_staff')) {
            throw new RuntimeException('ROLE_MISMATCH_FOR_OFFICE_ASSIGN: user is not office_staff');
        }

        return DB::transaction(function () use ($staff, $officeId, $isManager): OfficeStaffAssignment {
            $existing = OfficeStaffAssignment::query()
                ->where('user_id', $staff->id)
                ->where('office_id', $officeId)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new RuntimeException('OFFICE_ASSIGNMENT_DUPLICATE: assignment already active');
            }

            return OfficeStaffAssignment::create([
                'user_id' => $staff->id,
                'office_id' => $officeId,
                'is_manager' => $isManager,
                'assigned_at' => now(),
                'removed_at' => null,
            ]);
        });
    }

    public function detach(User $staff, OfficeStaffAssignment $assignment): void
    {
        DB::transaction(function () use ($staff, $assignment): void {
            $assignment->refresh();

            $activeCount = OfficeStaffAssignment::query()
                ->where('user_id', $staff->id)
                ->whereNull('removed_at')
                ->count();

            if ($activeCount <= 1) {
                throw new RuntimeException('OFFICE_ASSIGNMENT_LAST_REQUIRED: cannot remove last active assignment');
            }

            $assignment->forceFill(['removed_at' => now()])->save();
        });
    }

    /**
     * @param  array<int, array{office_id: int, is_manager: bool}>  $assignments
     * @return Collection<int, OfficeStaffAssignment>
     */
    public function attachMany(User $staff, array $assignments): Collection
    {
        // Caller is responsible for ensuring $staff has the office_staff role.
        // This method is called by StaffService::create() inside the same transaction,
        // immediately after assignRole('office_staff') — so the role IS present.

        return collect($assignments)->map(
            fn (array $a) => OfficeStaffAssignment::create([
                'user_id' => $staff->id,
                'office_id' => $a['office_id'],
                'is_manager' => $a['is_manager'],
                'assigned_at' => now(),
                'removed_at' => null,
            ]),
        );
    }
}
```

- [ ] **Step 4: Run tests — expect pass**

```bash
vendor/bin/pest tests/Unit/Services/Staff/OfficeAssignmentServiceTest.php
```

Expected: 6/6 pass.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Services/Staff/OfficeAssignmentService.php tests/Unit/Services/Staff/OfficeAssignmentServiceTest.php
git add app/Services/Staff/OfficeAssignmentService.php tests/Unit/Services/Staff/OfficeAssignmentServiceTest.php
git commit -m "feat(staff): OfficeAssignmentService — attach/detach/attachMany (Phase 1, RuntimeException stubs)"
```

---

## Task 3: `AttachOfficeAssignmentRequest`

**Files:**
- Create: `app/Http/Requests/Staff/AttachOfficeAssignmentRequest.php`

- [ ] **Step 1: Create the FormRequest**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

final class AttachOfficeAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'office_id' => ['required', 'integer', 'exists:office_locations,id'],
            'is_manager' => ['sometimes', 'boolean'],
        ];
    }

    public function isManager(): bool
    {
        return (bool) $this->input('is_manager', false);
    }
}
```

- [ ] **Step 2: Pint + commit**

```bash
vendor/bin/pint app/Http/Requests/Staff/AttachOfficeAssignmentRequest.php
git add app/Http/Requests/Staff/AttachOfficeAssignmentRequest.php
git commit -m "feat(staff): AttachOfficeAssignmentRequest"
```

---

## Task 4: `OfficeAssignmentResource`

**Files:**
- Create: `app/Http/Resources/Staff/OfficeAssignmentResource.php`

- [ ] **Step 1: Create the resource**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Staff;

use App\Models\OfficeStaffAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OfficeStaffAssignment
 */
final class OfficeAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OfficeStaffAssignment $a */
        $a = $this->resource;

        return [
            'id' => $a->public_id ?? (string) $a->id,
            'office' => [
                'id' => $a->office->public_id ?? (string) $a->office_id,
                'name' => $a->office?->name,
            ],
            'is_manager' => (bool) $a->is_manager,
            'assigned_at' => $a->assigned_at?->toIso8601String(),
            'removed_at' => $a->removed_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 2: Pint + commit**

```bash
vendor/bin/pint app/Http/Resources/Staff/OfficeAssignmentResource.php
git add app/Http/Resources/Staff/OfficeAssignmentResource.php
git commit -m "feat(staff): OfficeAssignmentResource"
```

---

## Task 5: `OfficeAssignmentController` (Phase 1 — no routes wired yet)

**Files:**
- Create: `app/Http/Controllers/Api/Admin/Staff/OfficeAssignmentController.php`

- [ ] **Step 1: Create the controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\AttachOfficeAssignmentRequest;
use App\Http\Resources\Staff\OfficeAssignmentResource;
use App\Models\OfficeStaffAssignment;
use App\Models\User;
use App\Services\Staff\OfficeAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use RuntimeException;

final class OfficeAssignmentController extends Controller
{
    public function __construct(private readonly OfficeAssignmentService $service) {}

    public function store(AttachOfficeAssignmentRequest $request, User $staff): JsonResponse
    {
        $this->authorize('manageOfficeAssignments', $staff);

        try {
            $assignment = $this->service->attach(
                $staff,
                $request->integer('office_id'),
                $request->isManager(),
            );
        } catch (RuntimeException $e) {
            // Phase 2 swaps this to a catch-StaffDomainException + return($e->toResponse()).
            return $this->stubErrorResponse($e);
        }

        return response()->json(
            (new OfficeAssignmentResource($assignment->load('office')))->resolve(),
            201,
        );
    }

    public function destroy(User $staff, OfficeStaffAssignment $assignment): Response
    {
        $this->authorize('manageOfficeAssignments', $staff);

        try {
            $this->service->detach($staff, $assignment);
        } catch (RuntimeException $e) {
            return $this->stubErrorResponse($e);
        }

        return response()->noContent();
    }

    private function stubErrorResponse(RuntimeException $e): JsonResponse
    {
        // Slice B Phase 1 only. Phase 2 removes this helper because
        // StaffDomainException is rendered globally by bootstrap/app.php.
        $code = explode(':', $e->getMessage(), 2)[0] ?? 'STAFF_ERROR';
        $status = match ($code) {
            'OFFICE_ASSIGNMENT_DUPLICATE' => 409,
            default => 422,
        };

        return response()->json([
            'error' => $code,
            'message' => $e->getMessage(),
        ], $status);
    }
}
```

- [ ] **Step 2: Pint + commit**

```bash
vendor/bin/pint app/Http/Controllers/Api/Admin/Staff/OfficeAssignmentController.php
git add app/Http/Controllers/Api/Admin/Staff/OfficeAssignmentController.php
git commit -m "feat(staff): OfficeAssignmentController (Phase 1, stub error responses)"
```

---

## Task 6: Feature test for `OfficeAssignmentController` (deferred verification)

**Files:**
- Create: `tests/Feature/Staff/OfficeAssignmentControllerTest.php`

The test asserts against the API, but routes aren't wired yet in Phase 1. This task **writes the test file** but does NOT run it. The test verification happens in Phase 2 Task 10 after routes are added.

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Models\OfficeLocation;
use App\Models\OfficeStaffAssignment;
use App\Models\Region;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');
});

function makeOfficeForTest(): OfficeLocation
{
    return OfficeLocation::create([
        'region_id' => Region::query()->firstOrFail()->id,
        'name' => 'Office '.uniqid(),
        'address' => 'addr',
        'location' => Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);
}

it('attaches an office to an office_staff user via POST', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $staff = User::factory()->create();
    $staff->assignRole('office_staff');

    $office = makeOfficeForTest();

    // Pre-existing assignment so detach won't be the last:
    OfficeStaffAssignment::create([
        'user_id' => $staff->id,
        'office_id' => makeOfficeForTest()->id,
        'is_manager' => false,
        'assigned_at' => now(),
    ]);

    $response = $this->postJson(
        "/api/admin/staff/{$staff->public_id}/office-assignments",
        ['office_id' => $office->id, 'is_manager' => true],
    );

    expect($response->status())->toBe(201);
    expect($response->json('office.name'))->toBe($office->name);
    expect($response->json('is_manager'))->toBeTrue();
});

it('rejects attaching to an admin role (ROLE_MISMATCH)', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $target = User::factory()->create();
    $target->assignRole('admin');

    $office = makeOfficeForTest();

    $response = $this->postJson(
        "/api/admin/staff/{$target->public_id}/office-assignments",
        ['office_id' => $office->id, 'is_manager' => false],
    );

    expect($response->status())->toBe(422);
    expect($response->json('error'))->toBe('ROLE_MISMATCH_FOR_OFFICE_ASSIGN');
});

it('rejects duplicate attachment with 409', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $staff = User::factory()->create();
    $staff->assignRole('office_staff');
    $office = makeOfficeForTest();

    OfficeStaffAssignment::create([
        'user_id' => $staff->id,
        'office_id' => $office->id,
        'is_manager' => false,
        'assigned_at' => now(),
    ]);

    $response = $this->postJson(
        "/api/admin/staff/{$staff->public_id}/office-assignments",
        ['office_id' => $office->id, 'is_manager' => false],
    );

    expect($response->status())->toBe(409);
    expect($response->json('error'))->toBe('OFFICE_ASSIGNMENT_DUPLICATE');
});

it('detaches an assignment via DELETE', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $staff = User::factory()->create();
    $staff->assignRole('office_staff');

    // Two assignments so detach is allowed
    $a1 = OfficeStaffAssignment::create([
        'user_id' => $staff->id,
        'office_id' => makeOfficeForTest()->id,
        'is_manager' => false,
        'assigned_at' => now(),
    ]);
    OfficeStaffAssignment::create([
        'user_id' => $staff->id,
        'office_id' => makeOfficeForTest()->id,
        'is_manager' => false,
        'assigned_at' => now(),
    ]);

    $response = $this->deleteJson(
        "/api/admin/staff/{$staff->public_id}/office-assignments/{$a1->public_id}",
    );

    expect($response->status())->toBe(204);
    expect($a1->fresh()->removed_at)->not->toBeNull();
});

it('rejects last-assignment detach with 422', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $staff = User::factory()->create();
    $staff->assignRole('office_staff');

    $only = OfficeStaffAssignment::create([
        'user_id' => $staff->id,
        'office_id' => makeOfficeForTest()->id,
        'is_manager' => false,
        'assigned_at' => now(),
    ]);

    $response = $this->deleteJson(
        "/api/admin/staff/{$staff->public_id}/office-assignments/{$only->public_id}",
    );

    expect($response->status())->toBe(422);
    expect($response->json('error'))->toBe('OFFICE_ASSIGNMENT_LAST_REQUIRED');
});
```

- [ ] **Step 2: Do NOT run the test yet** (routes don't exist on Codex's branch in Phase 1)

- [ ] **Step 3: Pint + commit**

```bash
vendor/bin/pint tests/Feature/Staff/OfficeAssignmentControllerTest.php
git add tests/Feature/Staff/OfficeAssignmentControllerTest.php
git commit -m "test(staff): OfficeAssignmentController feature test scaffold (runs in Phase 2)"
```

---

## Task 7: `scripts/staff-e2e.php` (6 rollback scenarios)

**Files:**
- Create: `scripts/staff-e2e.php`

This is a Tinker rollback-wrapped script following the pattern of `scripts/orders-e2e.php`. Most of the script is wrapping setup + assertions; the test scenarios are short.

- [ ] **Step 1: Create the e2e script**

```php
<?php

declare(strict_types=1);

// Force null broadcaster (same as orders-e2e.php) — staff CRUD doesn't broadcast,
// but RefreshDatabase factories may indirectly trigger broadcast events.
config(['broadcasting.default' => 'null']);

use App\Enums\AccountStatus;
use App\Models\OfficeLocation;
use App\Models\OfficeStaffAssignment;
use App\Models\Region;
use App\Models\User;
use App\Services\Staff\OfficeAssignmentService;
use App\Services\Staff\StaffService;
use App\Services\Staff\TempPasswordChangeService;
use App\Support\DTO\CreateStaffInput;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "PASS {$message}\n";
};

DB::beginTransaction();

try {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');
    Role::findOrCreate('user', 'web');

    $region = Region::query()->firstOrFail();
    $office1 = OfficeLocation::query()->where('region_id', $region->id)->first()
        ?? OfficeLocation::create([
            'region_id' => $region->id,
            'name' => 'E2E Office 1',
            'address' => 'addr',
            'location' => Point::makeGeodetic(32.8872, 13.1913),
            'is_active' => true,
        ]);

    $office2 = OfficeLocation::create([
        'region_id' => $region->id,
        'name' => 'E2E Office 2',
        'address' => 'addr',
        'location' => Point::makeGeodetic(32.9, 13.2),
        'is_active' => true,
    ]);

    // Two seed admins so last-admin guards work
    $rootAdmin = User::create([
        'first_name' => 'Root',
        'last_name' => 'Admin',
        'phone_number' => '+218910'.random_int(100000, 999999),
        'password' => Hash::make('rootpass1'),
        'account_status' => AccountStatus::Active->value,
        'phone_verified_at' => now(),
    ]);
    $rootAdmin->assignRole('admin');

    $coAdmin = User::create([
        'first_name' => 'Co',
        'last_name' => 'Admin',
        'phone_number' => '+218910'.random_int(100000, 999999),
        'password' => Hash::make('copass1'),
        'account_status' => AccountStatus::Active->value,
        'phone_verified_at' => now(),
    ]);
    $coAdmin->assignRole('admin');

    $staffService = app(StaffService::class);
    $passwordChange = app(TempPasswordChangeService::class);
    $officeAssign = app(OfficeAssignmentService::class);

    // SCENARIO 1: admin creates admin, temp password works once, forced change
    echo "Scenario 1: admin creates admin, forced change\n";
    $created = $staffService->create(new CreateStaffInput(
        phoneNumber: '+218910'.random_int(100000, 999999),
        firstName: 'New',
        lastName: 'Admin',
        email: null,
        role: 'admin',
    ), $rootAdmin);
    $assert(is_string($created['temporary_password']), 'temp password returned');
    $assert($created['user']->must_change_password === true, 'must_change_password flag set');
    $changed = $passwordChange->change($created['user'], $created['temporary_password'], 'newPass99X');
    $assert($changed['user']->must_change_password === false, 'flag cleared after change');
    $assert(is_string($changed['token']), 'new token issued');

    // SCENARIO 2: admin creates office_staff with 2 offices via service
    echo "Scenario 2: admin creates office_staff with 2 offices\n";
    $officeStaff = $staffService->create(new CreateStaffInput(
        phoneNumber: '+218910'.random_int(100000, 999999),
        firstName: 'Office',
        lastName: 'Worker',
        email: null,
        role: 'office_staff',
        officeAssignments: [
            ['office_id' => $office1->id, 'is_manager' => false],
            ['office_id' => $office2->id, 'is_manager' => true],
        ],
    ), $rootAdmin);
    $activeCount = $officeStaff['user']->activeOfficeAssignments()->count();
    $assert($activeCount === 2, "office_staff has 2 active assignments (got {$activeCount})");

    // SCENARIO 3: admin resets another admin's password
    echo "Scenario 3: admin resets another admin's password\n";
    $coAdmin->createToken('old');
    $reset = $staffService->resetTempPassword($coAdmin, $rootAdmin);
    $assert($reset['user']->must_change_password === true, 'flag set after reset');
    $assert($coAdmin->fresh()->tokens()->count() === 0, 'tokens revoked');

    // SCENARIO 4: admin suspends office_staff
    echo "Scenario 4: admin suspends office_staff\n";
    $suspended = $staffService->suspend($officeStaff['user'], $rootAdmin);
    $assert($suspended->account_status === AccountStatus::Suspended, 'office_staff suspended');

    // SCENARIO 5: admin deactivates office_staff — assignments soft-removed
    echo "Scenario 5: admin deactivates office_staff\n";
    $reinstated = $staffService->reinstate($officeStaff['user'], $rootAdmin);
    $deactivated = $staffService->deactivate($reinstated, $rootAdmin);
    $assert($deactivated->account_status === AccountStatus::Suspended, 'deactivate → suspended');
    // Slice B: deactivate should soft-remove all assignments. If StaffService::deactivate
    // hasn't been updated to call OfficeAssignmentService yet (post-integration), this assertion
    // may FAIL during Phase 1 — that's expected; it'll pass after Phase 2 integration.
    // Comment out the next line during Phase 1 build:
    //   $assert($deactivated->activeOfficeAssignments()->count() === 0, 'all assignments removed');

    // SCENARIO 6: self-suspend rejected, last-admin protected
    echo "Scenario 6: guards against self-modify and last-admin\n";
    try {
        $staffService->suspend($rootAdmin, $rootAdmin);
        throw new RuntimeException('self-suspend should have thrown');
    } catch (\Throwable $e) {
        $assert(str_contains($e->getMessage(), 'own account'), 'self-suspend rejected');
    }
    // Suspend co-admin (now $rootAdmin is the last admin)
    $staffService->suspend($coAdmin->fresh(), $rootAdmin);
    // Now have an inactive admin attempt to suspend $rootAdmin
    try {
        $staffService->suspend($rootAdmin, $coAdmin->fresh());
        throw new RuntimeException('last-admin suspend should have thrown');
    } catch (\Throwable $e) {
        $assert(str_contains($e->getMessage(), 'last active admin'), 'last-admin protected');
    }

    echo "\nALL STAFF E2E SMOKE SCENARIOS PASSED\n";
} finally {
    DB::rollBack();
}
```

- [ ] **Step 2: Run the smoke (test will require Slice A's services + Phase 1 OfficeAssignmentService)**

```bash
php artisan tinker --execute="require base_path('scripts/staff-e2e.php');"
```

Expected during Phase 1 (Slice A NOT YET MERGED): the script may fail at Scenario 1 because `StaffService` etc. don't exist on Codex's branch. **This is expected — the smoke script is for Phase 2 verification.** Do NOT block on it during Phase 1.

If you want a partial Phase 1 verification, comment out scenarios 1–6 except the OfficeAssignmentService-only assertions. Re-enable for Phase 2.

- [ ] **Step 3: Pint + commit**

```bash
vendor/bin/pint scripts/staff-e2e.php
git add scripts/staff-e2e.php
git commit -m "test(staff): e2e smoke — 6 rollback scenarios (full run in Phase 2)"
```

---

## Task 8: Phase 1 verification + WAIT for Slice A merge

- [ ] **Step 1: Run all unit tests on Codex's branch**

```bash
vendor/bin/pest tests/Unit/Services/Staff/OfficeAssignmentServiceTest.php
```

Expected: 6/6 pass.

- [ ] **Step 2: Verify full Pest suite still green**

```bash
vendor/bin/pest
```

Expected: all previously-green tests still green; new Slice B unit tests added. Slice B feature tests will fail because routes don't exist yet — that's expected. (Or skip the feature test for now: `vendor/bin/pest --exclude-group=slice-b-feature`. Easier: don't add the `--group` annotation and just acknowledge the feature test will fail in Phase 1.)

- [ ] **Step 3: WAIT for Slice A to merge to main**

Phase 1 is complete. **DO NOT push or open a PR yet.** Wait for the user to confirm Slice A has merged to main. Then proceed to Phase 2.

---

# PHASE 2 — After Slice A merges

## Task 9: Rebase on updated main

- [ ] **Step 1: Pull updated main**

```bash
git fetch origin
git rebase origin/main
```

If conflicts arise on `app/Models/User.php` (Slice A added `activeOfficeAssignments()`, Codex may have added it too in Task 2 Step 1 note), accept either version — both should be identical.

If conflicts arise on `app/Enums/StaffErrorCode.php` (shouldn't — Codex hasn't touched this file in Phase 1), accept main's version.

- [ ] **Step 2: Verify the rebase**

```bash
git status
vendor/bin/pest tests/Unit/Services/Staff/OfficeAssignmentServiceTest.php
```

Expected: tests still pass after rebase.

---

## Task 10: Add Slice B error codes to `StaffErrorCode` enum

**Files:**
- Modify: `app/Enums/StaffErrorCode.php`

- [ ] **Step 1: Add the three cases**

In `app/Enums/StaffErrorCode.php`, find the existing cases and append:

```php
case RoleMismatchForOfficeAssign = 'ROLE_MISMATCH_FOR_OFFICE_ASSIGN';
case OfficeAssignmentDuplicate = 'OFFICE_ASSIGNMENT_DUPLICATE';
case OfficeAssignmentLastRequired = 'OFFICE_ASSIGNMENT_LAST_REQUIRED';
```

And update the `httpStatus()` match expression to include them:

```php
public function httpStatus(): int
{
    return match ($this) {
        self::CannotSelfModify,
        self::LastAdminProtected,
        self::NewPasswordSameAsTemp,
        self::RoleMismatchForOfficeAssign,
        self::OfficeAssignmentLastRequired => 422,
        self::OfficeAssignmentDuplicate => 409,
        self::TempPasswordMismatch => 401,
    };
}
```

Also remove the "Slice B adds these AFTER..." comment block from Task 2 since they're now added.

- [ ] **Step 2: Commit**

```bash
git add app/Enums/StaffErrorCode.php
git commit -m "feat(staff): add Slice B cases to StaffErrorCode enum"
```

---

## Task 11: Swap `RuntimeException` for `StaffDomainException` in service + controller

**Files:**
- Modify: `app/Services/Staff/OfficeAssignmentService.php`
- Modify: `app/Http/Controllers/Api/Admin/Staff/OfficeAssignmentController.php`
- Modify: `tests/Unit/Services/Staff/OfficeAssignmentServiceTest.php`
- Modify: `tests/Feature/Staff/OfficeAssignmentControllerTest.php`

- [ ] **Step 1: Update service**

Replace each `throw new RuntimeException(...)` with:

```php
throw new \App\Exceptions\Staff\StaffDomainException(
    \App\Enums\StaffErrorCode::RoleMismatchForOfficeAssign,
    'User is not office_staff.',
);
```

(And the equivalent for `OFFICE_ASSIGNMENT_DUPLICATE` → `OfficeAssignmentDuplicate`, `OFFICE_ASSIGNMENT_LAST_REQUIRED` → `OfficeAssignmentLastRequired`.)

Add `use App\Exceptions\Staff\StaffDomainException;` and `use App\Enums\StaffErrorCode;` to imports. Remove `use RuntimeException;`.

- [ ] **Step 2: Update controller — remove stub helper**

Remove the `try/catch RuntimeException` blocks and the `stubErrorResponse()` helper. The global handler in `bootstrap/app.php` (added by Slice A) renders `StaffDomainException` directly. Final controller:

```php
final class OfficeAssignmentController extends Controller
{
    public function __construct(private readonly OfficeAssignmentService $service) {}

    public function store(AttachOfficeAssignmentRequest $request, User $staff): JsonResponse
    {
        $this->authorize('manageOfficeAssignments', $staff);

        $assignment = $this->service->attach(
            $staff,
            $request->integer('office_id'),
            $request->isManager(),
        );

        return response()->json(
            (new OfficeAssignmentResource($assignment->load('office')))->resolve(),
            201,
        );
    }

    public function destroy(User $staff, OfficeStaffAssignment $assignment): Response
    {
        $this->authorize('manageOfficeAssignments', $staff);

        $this->service->detach($staff, $assignment);

        return response()->noContent();
    }
}
```

- [ ] **Step 3: Update tests**

In `OfficeAssignmentServiceTest.php`, replace `\RuntimeException::class` with `\App\Exceptions\Staff\StaffDomainException::class`. The assertion messages may need updating from the raw codes to actual exception messages.

In `OfficeAssignmentControllerTest.php`, the `$response->json('error')` assertions still match (the enum's string value equals the test-expected code). No change needed.

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint app/Services/Staff/OfficeAssignmentService.php app/Http/Controllers/Api/Admin/Staff/OfficeAssignmentController.php tests/Unit/Services/Staff tests/Feature/Staff
git add app/Services/Staff/OfficeAssignmentService.php app/Http/Controllers/Api/Admin/Staff/OfficeAssignmentController.php tests/Unit/Services/Staff/OfficeAssignmentServiceTest.php tests/Feature/Staff/OfficeAssignmentControllerTest.php
git commit -m "refactor(staff): swap RuntimeException for StaffDomainException"
```

---

## Task 12: Replace Slice A's `LogicException("slice-B")` stub in `StaffService::create()`

**Files:**
- Modify: `app/Services/Staff/StaffService.php`

- [ ] **Step 0: Widen `CreateStaffRequest` validation to accept office_staff**

In `app/Http/Requests/Staff/CreateStaffRequest.php`, change the `role` and `office_assignments` rules:

```php
// Was (Slice A): Rule::in(['admin']) + 'prohibited'
'role' => ['required', Rule::in(['admin', 'office_staff'])],
'office_assignments' => [
    'required_if:role,office_staff',
    'prohibited_if:role,admin',
    'array',
    'min:1',
],
'office_assignments.*.office_id' => ['required', 'integer', 'exists:office_locations,id'],
'office_assignments.*.is_manager' => ['required', 'boolean'],
```

Remove the Slice A "widens this to" comment lines.

- [ ] **Step 1: Wire `OfficeAssignmentService` into `StaffService`**

In `StaffService`, add to constructor:

```php
public function __construct(
    private readonly TempPasswordGenerator $passwords,
    private readonly OfficeAssignmentService $officeAssignments,
) {}
```

Add `use App\Services\Staff\OfficeAssignmentService;` (might already be present — verify).

- [ ] **Step 2: Replace the stub**

Find the defensive guard block in `StaffService::create()` (introduced by Slice A's revised plan):

```php
if ($input->role === 'office_staff' && $input->officeAssignments !== []) {
    // Slice B replaces this with: $this->officeAssignments->attachMany($user, $input->officeAssignments);
    throw new \RuntimeException(
        'office_staff creation requires Slice B (OfficeAssignmentService::attachMany)'
    );
}
```

Replace with:

```php
if ($input->role === 'office_staff') {
    $this->officeAssignments->attachMany($user, $input->officeAssignments);
}
```

- [ ] **Step 3: Update `deactivate()` to soft-remove assignments**

Find the existing `deactivate()` method. Inside the `DB::transaction()`, after the suspend-and-tokens-delete block, add:

```php
OfficeStaffAssignment::query()
    ->where('user_id', $staff->id)
    ->whereNull('removed_at')
    ->update(['removed_at' => now()]);
```

Add `use App\Models\OfficeStaffAssignment;` to imports.

- [ ] **Step 4: Remove the `LogicException` import**

If `use LogicException;` is no longer needed, remove it.

- [ ] **Step 5: Update Slice A's `StaffServiceCreateTest` expectation**

In `tests/Unit/Services/Staff/StaffServiceCreateTest.php`, the test "throws LogicException for office_staff creation" must be rewritten. Replace it with a positive test:

```php
it('creates an office_staff user with office assignments', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('admin');

    $office = \App\Models\OfficeLocation::create([
        'region_id' => \App\Models\Region::query()->firstOrFail()->id,
        'name' => 'Test Office',
        'address' => 'addr',
        'location' => \Clickbar\Magellan\Data\Geometries\Point::makeGeodetic(32.8872, 13.1913),
        'is_active' => true,
    ]);

    $service = app(StaffService::class);

    $result = $service->create(new CreateStaffInput(
        phoneNumber: '+218910000022',
        firstName: 'Yusuf',
        lastName: 'Smith',
        email: null,
        role: 'office_staff',
        officeAssignments: [['office_id' => $office->id, 'is_manager' => false]],
    ), $actor);

    expect($result['user']->hasRole('office_staff'))->toBeTrue();
    expect($result['user']->activeOfficeAssignments()->count())->toBe(1);
});
```

- [ ] **Step 6: Run tests**

```bash
vendor/bin/pest tests/Unit/Services/Staff
vendor/bin/pest tests/Feature/Staff
```

Expected: all green.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint app/Services/Staff/StaffService.php tests/Unit/Services/Staff
git add app/Services/Staff/StaffService.php tests/Unit/Services/Staff/StaffServiceCreateTest.php
git commit -m "feat(staff): wire OfficeAssignmentService into StaffService.create + deactivate"
```

---

## Task 13: Wire routes + update `StaffResource`

**Files:**
- Modify: `routes/api.php`
- Modify: `app/Http/Resources/Staff/StaffResource.php`

- [ ] **Step 1: Add the 2 office-assignment routes**

In `routes/api.php`, find the `admin.staff.*` group Claude registered. Inside the same group's closure, add:

```php
Route::post('/{staff}/office-assignments', [\App\Http\Controllers\Api\Admin\Staff\OfficeAssignmentController::class, 'store'])->name('office-assignments.store');
Route::delete('/{staff}/office-assignments/{assignment}', [\App\Http\Controllers\Api\Admin\Staff\OfficeAssignmentController::class, 'destroy'])->name('office-assignments.destroy');
```

- [ ] **Step 2: Update `StaffResource` to use `OfficeAssignmentResource`**

In `app/Http/Resources/Staff/StaffResource.php`, find the `office_assignments` key. Replace the inline-mapping callback with:

```php
'office_assignments' => $this->whenLoaded(
    'activeOfficeAssignments',
    fn () => OfficeAssignmentResource::collection($u->activeOfficeAssignments),
),
```

Add `use App\Http\Resources\Staff\OfficeAssignmentResource;` to imports.

- [ ] **Step 3: Run the feature test**

```bash
vendor/bin/pest tests/Feature/Staff/OfficeAssignmentControllerTest.php
```

Expected: 5/5 pass.

- [ ] **Step 4: Run the e2e smoke**

```bash
php artisan tinker --execute="require base_path('scripts/staff-e2e.php');"
```

Expected: `ALL STAFF E2E SMOKE SCENARIOS PASSED`. Uncomment the deactivate-removes-assignments assertion in Scenario 5 (now that Task 12 wired it).

- [ ] **Step 5: Run full Pest + orders e2e**

```bash
vendor/bin/pest
php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"
```

Expected: all Pest tests green; 32 orders-e2e scenarios still pass.

- [ ] **Step 6: Final Pint**

```bash
vendor/bin/pint
```

- [ ] **Step 7: Pint + commit + push + open PR**

```bash
git add routes/api.php app/Http/Resources/Staff/StaffResource.php scripts/staff-e2e.php
git commit -m "feat(staff): wire office-assignment routes + StaffResource embeds OfficeAssignmentResource"
git push -u origin codex/staff-crud-office-assignments
gh pr create --title "feat(staff): slice B — office assignments + e2e smoke" --body "$(cat <<'EOF'
## Summary

Slice B of the Staff CRUD milestone. See `docs/superpowers/specs/2026-05-20-staff-crud-design.md`.

Builds on Slice A (already merged).

### What landed

- `OfficeAssignmentService` (attach / detach / attachMany) with full transaction safety
- `OfficeAssignmentController` (2 endpoints) with policy + FormRequest
- `OfficeAssignmentResource`
- 3 new `StaffErrorCode` cases (RoleMismatchForOfficeAssign, OfficeAssignmentDuplicate, OfficeAssignmentLastRequired)
- Wired `OfficeAssignmentService::attachMany()` into `StaffService::create()` for office_staff role
- Wired soft-removal of assignments into `StaffService::deactivate()`
- `StaffResource` now embeds `OfficeAssignmentResource` when `activeOfficeAssignments` is loaded
- `scripts/staff-e2e.php` — 6 rollback-wrapped scenarios

### Test plan

- [x] All Pest tests green
- [x] scripts/staff-e2e.php 6 scenarios pass
- [x] scripts/orders-e2e.php 32 scenarios pass (no regression)
- [x] Pint clean

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review checklist

- [ ] **Spec coverage:** every Slice B deliverable in spec §10.3 has a task. OfficeAssignmentService (Task 2), AttachOfficeAssignmentRequest (Task 3), OfficeAssignmentResource (Task 4), OfficeAssignmentController (Task 5), OfficeAssignmentControllerTest (Task 6), staff-e2e.php (Task 7), 3 enum cases (Task 10), exception swap (Task 11), StaffService.create integration (Task 12), routes + StaffResource embed (Task 13). ✓
- [ ] **Placeholders:** no "TBD" / "fill in" inside code blocks. The intentional `TODO` markers in Phase 1 stub code are documented as "Phase 2 replaces this".
- [ ] **Type consistency:** `OfficeAssignmentService::attach()` returns `OfficeStaffAssignment`. `OfficeAssignmentController::store()` returns the assignment via `OfficeAssignmentResource`. Test assertions reference `$response->json('office.name')` which matches the resource shape (Task 4).
- [ ] **Phase boundaries clear:** Tasks 1–8 are Phase 1 (parallel with Slice A); Tasks 9–13 are Phase 2 (after Slice A merges). The "WAIT" task (Task 8 Step 3) is explicit. The plan repeatedly notes which exception/import to use in each phase.
- [ ] **No race condition with Slice A:** Codex's Phase 1 never modifies `StaffErrorCode`, `StaffDomainException`, or `StaffService` because those files don't exist on Codex's branch. They land in Phase 2 only after Slice A merges.
