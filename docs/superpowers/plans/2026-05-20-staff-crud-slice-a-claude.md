# Staff CRUD — Slice A (Claude) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the foundation of Staff CRUD — admins can create/list/show/update/suspend/reinstate/deactivate other admins (and stub office_staff creation that Slice B fills in), reset temp passwords, and a forced-password-change middleware blocks staff with unactivated temp passwords from using the API.

**Architecture:** Single namespace `/api/admin/staff/*` gated by `auth:sanctum + role:admin`. One service per concern (`StaffService`, `TempPasswordGenerator`, `TempPasswordChangeService`). One cross-cutting middleware applied to all sanctum groups (`EnsurePasswordChanged`). One domain exception (`StaffDomainException`) carrying an error code enum. All multi-step writes wrapped in `DB::transaction()`.

**Tech Stack:** Laravel 13 · PostgreSQL · Sanctum · Spatie Permission · Pest 4

**Prerequisites:**
- Spec at `docs/superpowers/specs/2026-05-20-staff-crud-design.md` is locked.
- Working in worktree `C:\Users\User\Desktop\delivary-app-claude\`, branch `claude/staff-crud-core` from `main`.
- Per-worktree git identity already configured (`Claude <claude@delivary.local>`).
- Postgres test DB `delivary_app_testing` is running.

---

## File structure (Slice A creates/modifies)

**New files:**

- `database/migrations/2026_05_20_000001_add_must_change_password_to_users.php`
- `app/Enums/StaffErrorCode.php`
- `app/Exceptions/Staff/StaffDomainException.php`
- `app/Services/Staff/TempPasswordGenerator.php`
- `app/Services/Staff/StaffService.php`
- `app/Services/Staff/TempPasswordChangeService.php`
- `app/Http/Middleware/EnsurePasswordChanged.php`
- `app/Http/Controllers/Api/Admin/Staff/StaffController.php`
- `app/Http/Controllers/Api/Me/ChangeFromTempPasswordController.php`
- `app/Http/Requests/Staff/CreateStaffRequest.php`
- `app/Http/Requests/Staff/UpdateStaffRequest.php`
- `app/Http/Requests/Staff/ChangeFromTempPasswordRequest.php`
- `app/Http/Resources/Staff/StaffResource.php`
- `app/Policies/StaffPolicy.php`
- `app/Support/DTO/CreateStaffInput.php` (readonly DTO)
- `app/Support/DTO/UpdateStaffInput.php` (readonly DTO)
- All Slice A tests under `tests/Unit/Services/Staff`, `tests/Unit/Middleware`, `tests/Unit/Policies`, `tests/Feature/Staff`

**Modified files:**

- `app/Models/User.php` — add `must_change_password` to `$fillable` + `casts()`; add `activeOfficeAssignments()` relation
- `bootstrap/app.php` — register `staff.password_change_required` middleware alias + register `StaffPolicy`
- `routes/api.php` — add staff route group + change-from-temp route + apply `staff.password_change_required` to existing sanctum groups
- `app/Providers/AppServiceProvider.php` — add `password_change_temp` rate limiter

---

## Task 1: Migration — `users.must_change_password`

**Files:**
- Create: `database/migrations/2026_05_20_000001_add_must_change_password_to_users.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')
                ->default(false)
                ->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('must_change_password');
        });
    }
};
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate
```

Expected: `Migrated: 2026_05_20_000001_add_must_change_password_to_users`.

- [ ] **Step 3: Update `app/Models/User.php`**

Add `'must_change_password'` to `$fillable` (find the existing array and append). Add `'must_change_password' => 'boolean'` to the `casts()` method.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_20_000001_add_must_change_password_to_users.php app/Models/User.php
git commit -m "feat(staff): add users.must_change_password column"
```

---

## Task 2: `StaffErrorCode` enum

**Files:**
- Create: `app/Enums/StaffErrorCode.php`

- [ ] **Step 1: Create the enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum StaffErrorCode: string
{
    case CannotSelfModify = 'CANNOT_SELF_MODIFY';
    case LastAdminProtected = 'LAST_ADMIN_PROTECTED';
    case TempPasswordMismatch = 'TEMP_PASSWORD_MISMATCH';
    case NewPasswordSameAsTemp = 'NEW_PASSWORD_SAME_AS_TEMP';

    // Slice B (Codex) adds these AFTER Slice A merges. Do NOT add them in Slice A:
    //   case RoleMismatchForOfficeAssign = 'ROLE_MISMATCH_FOR_OFFICE_ASSIGN';
    //   case OfficeAssignmentDuplicate = 'OFFICE_ASSIGNMENT_DUPLICATE';
    //   case OfficeAssignmentLastRequired = 'OFFICE_ASSIGNMENT_LAST_REQUIRED';

    public function httpStatus(): int
    {
        return match ($this) {
            self::CannotSelfModify, self::LastAdminProtected, self::NewPasswordSameAsTemp => 422,
            self::TempPasswordMismatch => 401,
        };
    }
}
```

- [ ] **Step 2: Pint + commit**

```bash
vendor/bin/pint app/Enums/StaffErrorCode.php
git add app/Enums/StaffErrorCode.php
git commit -m "feat(staff): StaffErrorCode enum (slice A cases)"
```

---

## Task 3: `StaffDomainException`

**Files:**
- Create: `app/Exceptions/Staff/StaffDomainException.php`
- Modify: `bootstrap/app.php` — register the exception renderer

- [ ] **Step 1: Create the exception class**

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Staff;

use App\Enums\StaffErrorCode;
use RuntimeException;

final class StaffDomainException extends RuntimeException
{
    public function __construct(
        public readonly StaffErrorCode $errorCode,
        string $message,
        /** @var array<string, mixed> */
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->errorCode->httpStatus();
    }

    /** @return array<string, mixed> */
    public function toResponse(): array
    {
        return [
            'error' => $this->errorCode->value,
            'message' => $this->getMessage(),
            'context' => $this->context,
        ];
    }
}
```

- [ ] **Step 2: Register renderer in `bootstrap/app.php`**

Inside the existing `->withExceptions(function (Exceptions $exceptions): void { ... })` block, find the existing `$exceptions->render(...)` for `OrderDomainException` and add a sibling for `StaffDomainException`:

```php
$exceptions->render(function (\App\Exceptions\Staff\StaffDomainException $e): \Illuminate\Http\JsonResponse {
    return new \Illuminate\Http\JsonResponse($e->toResponse(), $e->httpStatus());
});
```

- [ ] **Step 3: Pint + commit**

```bash
vendor/bin/pint app/Exceptions/Staff/StaffDomainException.php bootstrap/app.php
git add app/Exceptions/Staff/StaffDomainException.php bootstrap/app.php
git commit -m "feat(staff): StaffDomainException + global JSON renderer"
```

---

## Task 4: `TempPasswordGenerator` + test

**Files:**
- Create: `app/Services/Staff/TempPasswordGenerator.php`
- Create: `tests/Unit/Services/Staff/TempPasswordGeneratorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Services\Staff\TempPasswordGenerator;

it('generates a 10-character alphanumeric password', function (): void {
    $password = (new TempPasswordGenerator())->generate();

    expect($password)->toBeString();
    expect(strlen($password))->toBe(10);
    expect($password)->toMatch('/^[A-Za-z0-9]+$/');
});

it('produces a different password on each call', function (): void {
    $gen = new TempPasswordGenerator();

    $passwords = collect(range(1, 50))->map(fn () => $gen->generate());

    expect($passwords->unique()->count())->toBe(50);
});
```

- [ ] **Step 2: Run test — expect failure**

```bash
vendor/bin/pest tests/Unit/Services/Staff/TempPasswordGeneratorTest.php
```

Expected: fails — class not found.

- [ ] **Step 3: Create the class**

```php
<?php

declare(strict_types=1);

namespace App\Services\Staff;

final class TempPasswordGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    private const LENGTH = 10;

    public function generate(): string
    {
        $alphabet = self::ALPHABET;
        $alphabetLen = strlen($alphabet);
        $bytes = random_bytes(self::LENGTH);
        $password = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $password .= $alphabet[ord($bytes[$i]) % $alphabetLen];
        }

        return $password;
    }
}
```

Note: ALPHABET excludes ambiguous chars (0/O, 1/l/I) to avoid transcription errors when an admin reads the password aloud.

- [ ] **Step 4: Run test — expect pass**

```bash
vendor/bin/pest tests/Unit/Services/Staff/TempPasswordGeneratorTest.php
```

Expected: 2/2 pass.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Services/Staff tests/Unit/Services/Staff
git add app/Services/Staff/TempPasswordGenerator.php tests/Unit/Services/Staff/TempPasswordGeneratorTest.php
git commit -m "feat(staff): TempPasswordGenerator — crypto-random 10-char ambiguity-free alphabet"
```

---

## Task 5: DTOs for service inputs

**Files:**
- Create: `app/Support/DTO/CreateStaffInput.php`
- Create: `app/Support/DTO/UpdateStaffInput.php`

- [ ] **Step 1: Create `CreateStaffInput`**

```php
<?php

declare(strict_types=1);

namespace App\Support\DTO;

final readonly class CreateStaffInput
{
    /**
     * @param  array<int, array{office_id: int, is_manager: bool}>  $officeAssignments
     */
    public function __construct(
        public string $phoneNumber,
        public string $firstName,
        public string $lastName,
        public ?string $email,
        public string $role,
        public array $officeAssignments = [],
    ) {}
}
```

- [ ] **Step 2: Create `UpdateStaffInput`**

```php
<?php

declare(strict_types=1);

namespace App\Support\DTO;

final readonly class UpdateStaffInput
{
    public function __construct(
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $email = null,
    ) {}
}
```

- [ ] **Step 3: Pint + commit**

```bash
vendor/bin/pint app/Support/DTO
git add app/Support/DTO
git commit -m "feat(staff): CreateStaffInput + UpdateStaffInput readonly DTOs"
```

---

## Task 6: `StaffService` skeleton + create() for admin role

**Files:**
- Create: `app/Services/Staff/StaffService.php`
- Create: `tests/Unit/Services/Staff/StaffServiceCreateTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\StaffErrorCode;
use App\Exceptions\Staff\StaffDomainException;
use App\Models\User;
use App\Services\Staff\StaffService;
use App\Support\DTO\CreateStaffInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');
});

it('creates an admin user with a generated temp password', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('admin');

    $service = app(StaffService::class);

    $result = $service->create(new CreateStaffInput(
        phoneNumber: '+218910000010',
        firstName: 'Aya',
        lastName: 'Smith',
        email: null,
        role: 'admin',
    ), $actor);

    expect($result)->toHaveKeys(['user', 'temporary_password']);
    expect($result['user'])->toBeInstanceOf(User::class);
    expect($result['user']->first_name)->toBe('Aya');
    expect($result['user']->phone_number)->toBe('+218910000010');
    expect($result['user']->account_status)->toBe(AccountStatus::Active);
    expect($result['user']->must_change_password)->toBeTrue();
    expect($result['user']->hasRole('admin'))->toBeTrue();
    expect($result['temporary_password'])->toBeString();
    expect(strlen($result['temporary_password']))->toBe(10);
    expect(Hash::check($result['temporary_password'], $result['user']->password))->toBeTrue();
});

it('throws LogicException for office_staff creation (slice B fills this in)', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('admin');

    $service = app(StaffService::class);

    $service->create(new CreateStaffInput(
        phoneNumber: '+218910000011',
        firstName: 'Yusuf',
        lastName: 'Smith',
        email: null,
        role: 'office_staff',
        officeAssignments: [['office_id' => 1, 'is_manager' => false]],
    ), $actor);
})->throws(LogicException::class, 'slice-B');
```

- [ ] **Step 2: Run test — expect failure**

```bash
vendor/bin/pest tests/Unit/Services/Staff/StaffServiceCreateTest.php
```

Expected: fails — `StaffService` not found.

- [ ] **Step 3: Create `StaffService`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Staff;

use App\Enums\AccountStatus;
use App\Enums\StaffErrorCode;
use App\Exceptions\Staff\StaffDomainException;
use App\Models\User;
use App\Support\DTO\CreateStaffInput;
use App\Support\DTO\UpdateStaffInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

final class StaffService
{
    public function __construct(
        private readonly TempPasswordGenerator $passwords,
    ) {}

    /**
     * @return array{user: User, temporary_password: string}
     */
    public function create(CreateStaffInput $input, User $actor): array
    {
        return DB::transaction(function () use ($input): array {
            $tempPassword = $this->passwords->generate();

            $user = User::create([
                'phone_number' => $input->phoneNumber,
                'first_name' => $input->firstName,
                'last_name' => $input->lastName,
                'email' => $input->email,
                'password' => Hash::make($tempPassword),
                'must_change_password' => true,
                'account_status' => AccountStatus::Active->value,
                'phone_verified_at' => now(),
            ]);

            $user->assignRole($input->role);

            if ($input->role === 'office_staff') {
                // Slice B replaces this stub with $this->officeAssignments->attachMany($user, $input->officeAssignments).
                throw new LogicException(
                    'office_staff creation stubbed in slice-B; assigning role then aborting transaction.'
                );
            }

            return [
                'user' => $user->fresh(),
                'temporary_password' => $tempPassword,
            ];
        });
    }

    public function update(User $staff, UpdateStaffInput $input): User
    {
        $staff->fill(array_filter([
            'first_name' => $input->firstName,
            'last_name' => $input->lastName,
            'email' => $input->email,
        ], fn ($v) => $v !== null))->save();

        return $staff->fresh();
    }

    public function suspend(User $staff, User $actor): User
    {
        $this->assertNotSelf($staff, $actor);
        $this->assertNotLastAdmin($staff);

        return DB::transaction(function () use ($staff): User {
            $staff->forceFill(['account_status' => AccountStatus::Suspended->value])->save();
            $staff->tokens()->delete();

            return $staff->fresh();
        });
    }

    public function reinstate(User $staff, User $actor): User
    {
        $staff->forceFill(['account_status' => AccountStatus::Active->value])->save();

        return $staff->fresh();
    }

    public function deactivate(User $staff, User $actor): User
    {
        $this->assertNotSelf($staff, $actor);
        $this->assertNotLastAdmin($staff);

        return DB::transaction(function () use ($staff): User {
            $staff->forceFill(['account_status' => AccountStatus::Suspended->value])->save();
            $staff->tokens()->delete();

            // Slice B: soft-remove all active office_staff_assignments here.
            // Until Slice B merges, this loop is a no-op for admins (who have no assignments)
            // and would never run for office_staff (which can't be created yet).

            return $staff->fresh();
        });
    }

    /**
     * @return array{user: User, temporary_password: string}
     */
    public function resetTempPassword(User $staff, User $actor): array
    {
        $this->assertNotSelf($staff, $actor);

        return DB::transaction(function () use ($staff): array {
            $tempPassword = $this->passwords->generate();

            $staff->forceFill([
                'password' => Hash::make($tempPassword),
                'must_change_password' => true,
            ])->save();

            $staff->tokens()->delete();

            return [
                'user' => $staff->fresh(),
                'temporary_password' => $tempPassword,
            ];
        });
    }

    private function assertNotSelf(User $staff, User $actor): void
    {
        if ($staff->id === $actor->id) {
            throw new StaffDomainException(
                StaffErrorCode::CannotSelfModify,
                'Admins cannot perform this action on their own account.',
            );
        }
    }

    private function assertNotLastAdmin(User $staff): void
    {
        if (! $staff->hasRole('admin')) {
            return;
        }

        $activeAdmins = User::query()
            ->role('admin')
            ->where('account_status', AccountStatus::Active->value)
            ->where('id', '!=', $staff->id)
            ->count();

        if ($activeAdmins < 1) {
            throw new StaffDomainException(
                StaffErrorCode::LastAdminProtected,
                'Cannot suspend or deactivate the last active admin.',
            );
        }
    }
}
```

- [ ] **Step 4: Run tests — expect pass**

```bash
vendor/bin/pest tests/Unit/Services/Staff/StaffServiceCreateTest.php
```

Expected: 2/2 pass.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Services/Staff/StaffService.php tests/Unit/Services/Staff/StaffServiceCreateTest.php
git add app/Services/Staff/StaffService.php tests/Unit/Services/Staff/StaffServiceCreateTest.php
git commit -m "feat(staff): StaffService — create() for admin role + suspend/deactivate/reinstate/resetTempPassword"
```

---

## Task 7: StaffService suspension/reinstate/deactivate/reset-temp tests

**Files:**
- Create: `tests/Unit/Services/Staff/StaffServiceLifecycleTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\StaffErrorCode;
use App\Exceptions\Staff\StaffDomainException;
use App\Models\User;
use App\Services\Staff\StaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
});

function makeAdmin(): User
{
    $user = User::factory()->create([
        'account_status' => AccountStatus::Active->value,
        'must_change_password' => false,
    ]);
    $user->assignRole('admin');

    return $user;
}

it('suspends another admin, revokes their tokens, sets status', function (): void {
    $actor = makeAdmin();
    $target = makeAdmin();
    $protector = makeAdmin();
    $target->createToken('test');

    expect($target->tokens()->count())->toBe(1);

    $result = app(StaffService::class)->suspend($target, $actor);

    expect($result->account_status)->toBe(AccountStatus::Suspended);
    expect($target->tokens()->count())->toBe(0);
});

it('refuses self-suspension', function (): void {
    $actor = makeAdmin();
    makeAdmin();

    expect(fn () => app(StaffService::class)->suspend($actor, $actor))
        ->toThrow(StaffDomainException::class, 'cannot perform this action on their own');
});

it('refuses to suspend the last admin', function (): void {
    $actor = makeAdmin();
    $target = makeAdmin();

    // Only $actor and $target are admins. Suspending $target would leave only $actor.
    // That's fine. But suspending $actor with $target as caller would also be fine.
    // We need exactly ONE admin to be active to trigger LAST_ADMIN_PROTECTED.
    $other = makeAdmin();
    app(StaffService::class)->suspend($other, $actor); // now 2 admins active

    // Now suspend $target — would leave only $actor active. Still fine.
    app(StaffService::class)->suspend($target, $actor); // now 1 admin active

    // Now $actor suspending themself? No, that's CannotSelfModify first.
    // Have someone else attempt to suspend $actor.
    $newAdmin = makeAdmin();
    app(StaffService::class)->suspend($newAdmin, $actor); // still only $actor active

    // $actor is now the only active admin. Suspending $actor should throw LAST_ADMIN_PROTECTED.
    // Use another admin to attempt this. But we have none active... Create one inactive.
    $inactive = User::factory()->create(['account_status' => AccountStatus::Suspended->value]);
    $inactive->assignRole('admin');

    expect(fn () => app(StaffService::class)->suspend($actor, $inactive))
        ->toThrow(StaffDomainException::class, 'last active admin');
});

it('reinstates a suspended admin', function (): void {
    $actor = makeAdmin();
    $target = makeAdmin();
    makeAdmin();

    app(StaffService::class)->suspend($target, $actor);
    expect($target->fresh()->account_status)->toBe(AccountStatus::Suspended);

    $result = app(StaffService::class)->reinstate($target, $actor);

    expect($result->account_status)->toBe(AccountStatus::Active);
});

it('resets temp password — regenerates, sets flag, revokes tokens', function (): void {
    $actor = makeAdmin();
    $target = makeAdmin();
    $target->createToken('test');
    $oldHash = $target->password;

    $result = app(StaffService::class)->resetTempPassword($target, $actor);

    expect($result)->toHaveKeys(['user', 'temporary_password']);
    expect($result['user']->must_change_password)->toBeTrue();
    expect(Hash::check($result['temporary_password'], $result['user']->password))->toBeTrue();
    expect($result['user']->password)->not->toBe($oldHash);
    expect($target->tokens()->count())->toBe(0);
});

it('refuses self-reset of temp password', function (): void {
    $actor = makeAdmin();
    makeAdmin();

    expect(fn () => app(StaffService::class)->resetTempPassword($actor, $actor))
        ->toThrow(StaffDomainException::class, 'cannot perform this action on their own');
});
```

- [ ] **Step 2: Run tests — expect pass**

```bash
vendor/bin/pest tests/Unit/Services/Staff/StaffServiceLifecycleTest.php
```

Expected: 6/6 pass. The service was already written in Task 6.

- [ ] **Step 3: Pint + commit**

```bash
vendor/bin/pint tests/Unit/Services/Staff/StaffServiceLifecycleTest.php
git add tests/Unit/Services/Staff/StaffServiceLifecycleTest.php
git commit -m "test(staff): StaffService suspend/reinstate/deactivate/resetTempPassword"
```

---

## Task 8: `TempPasswordChangeService` + test

**Files:**
- Create: `app/Services/Staff/TempPasswordChangeService.php`
- Create: `tests/Unit/Services/Staff/TempPasswordChangeServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\StaffErrorCode;
use App\Exceptions\Staff\StaffDomainException;
use App\Models\User;
use App\Services\Staff\TempPasswordChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('changes password, clears flag, revokes tokens, issues new token', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('temp1234ab'),
        'must_change_password' => true,
    ]);
    $user->createToken('old');
    $user->createToken('older');
    expect($user->tokens()->count())->toBe(2);

    $result = app(TempPasswordChangeService::class)->change(
        $user,
        'temp1234ab',
        'newSecret99X',
    );

    expect($result)->toHaveKeys(['user', 'token']);
    expect($result['user']->must_change_password)->toBeFalse();
    expect(Hash::check('newSecret99X', $result['user']->password))->toBeTrue();
    expect($result['token'])->toBeString();
    expect($user->fresh()->tokens()->count())->toBe(1); // only the new token
});

it('rejects when current password is wrong', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('temp1234ab'),
        'must_change_password' => true,
    ]);

    expect(fn () => app(TempPasswordChangeService::class)->change(
        $user,
        'wrongPass',
        'newSecret99X',
    ))->toThrow(StaffDomainException::class);
});

it('rejects when new password equals current', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('temp1234ab'),
        'must_change_password' => true,
    ]);

    expect(fn () => app(TempPasswordChangeService::class)->change(
        $user,
        'temp1234ab',
        'temp1234ab',
    ))->toThrow(StaffDomainException::class, 'same');
});
```

- [ ] **Step 2: Run test — expect failure**

```bash
vendor/bin/pest tests/Unit/Services/Staff/TempPasswordChangeServiceTest.php
```

Expected: class not found.

- [ ] **Step 3: Create the service**

```php
<?php

declare(strict_types=1);

namespace App\Services\Staff;

use App\Enums\StaffErrorCode;
use App\Exceptions\Staff\StaffDomainException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class TempPasswordChangeService
{
    /**
     * @return array{user: User, token: string}
     */
    public function change(User $user, string $currentPassword, string $newPassword): array
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new StaffDomainException(
                StaffErrorCode::TempPasswordMismatch,
                'Current password is incorrect.',
            );
        }

        if ($currentPassword === $newPassword) {
            throw new StaffDomainException(
                StaffErrorCode::NewPasswordSameAsTemp,
                'New password must differ from the temporary password.',
            );
        }

        return DB::transaction(function () use ($user, $newPassword): array {
            $user->forceFill([
                'password' => Hash::make($newPassword),
                'must_change_password' => false,
            ])->save();

            $user->tokens()->delete();
            $token = $user->createToken('post-temp-change')->plainTextToken;

            return [
                'user' => $user->fresh(),
                'token' => $token,
            ];
        });
    }
}
```

- [ ] **Step 4: Run tests — expect pass**

```bash
vendor/bin/pest tests/Unit/Services/Staff/TempPasswordChangeServiceTest.php
```

Expected: 3/3 pass.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint app/Services/Staff/TempPasswordChangeService.php tests/Unit/Services/Staff/TempPasswordChangeServiceTest.php
git add app/Services/Staff/TempPasswordChangeService.php tests/Unit/Services/Staff/TempPasswordChangeServiceTest.php
git commit -m "feat(staff): TempPasswordChangeService — change-from-temp with token revocation"
```

---

## Task 9: `EnsurePasswordChanged` middleware + test

**Files:**
- Create: `app/Http/Middleware/EnsurePasswordChanged.php`
- Create: `tests/Unit/Middleware/EnsurePasswordChangedMiddlewareTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Http\Middleware\EnsurePasswordChanged;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

uses(RefreshDatabase::class);

function makeRequest(?User $user, string $routeName = 'admin.staff.index'): Request
{
    $request = Request::create('/api/admin/staff');
    if ($user !== null) {
        $request->setUserResolver(fn () => $user);
    }
    $request->setRouteResolver(fn () => (new Route(['GET'], '/api/admin/staff', []))->name($routeName));

    return $request;
}

it('passes through when user has no must_change_password flag', function (): void {
    $user = User::factory()->create(['must_change_password' => false]);

    $next = fn ($req) => response('ok');
    $response = (new EnsurePasswordChanged())->handle(makeRequest($user), $next);

    expect($response->getContent())->toBe('ok');
});

it('passes through when no user is authenticated', function (): void {
    $next = fn ($req) => response('ok');
    $response = (new EnsurePasswordChanged())->handle(makeRequest(null), $next);

    expect($response->getContent())->toBe('ok');
});

it('blocks with 403 when user must change password', function (): void {
    $user = User::factory()->create(['must_change_password' => true]);

    $next = fn ($req) => response('ok');
    $response = (new EnsurePasswordChanged())->handle(makeRequest($user), $next);

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['error'])->toBe('password_change_required');
});

it('allows the change-from-temp route through', function (): void {
    $user = User::factory()->create(['must_change_password' => true]);

    $next = fn ($req) => response('ok');
    $response = (new EnsurePasswordChanged())->handle(
        makeRequest($user, 'me.password.change-from-temp'),
        $next,
    );

    expect($response->getContent())->toBe('ok');
});

it('allows the logout route through', function (): void {
    $user = User::factory()->create(['must_change_password' => true]);

    $next = fn ($req) => response('ok');
    $response = (new EnsurePasswordChanged())->handle(
        makeRequest($user, 'auth.logout'),
        $next,
    );

    expect($response->getContent())->toBe('ok');
});
```

- [ ] **Step 2: Run test — expect failure**

```bash
vendor/bin/pest tests/Unit/Middleware/EnsurePasswordChangedMiddlewareTest.php
```

Expected: class not found.

- [ ] **Step 3: Create the middleware**

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePasswordChanged
{
    private const ALLOWED_ROUTES = [
        'me.password.change-from-temp',
        'auth.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        return response()->json([
            'error' => 'password_change_required',
            'message' => 'You must change your temporary password before using the API.',
            'next' => ['endpoint' => 'POST /api/me/password/change-from-temp'],
        ], 403);
    }
}
```

- [ ] **Step 4: Run tests — expect pass**

```bash
vendor/bin/pest tests/Unit/Middleware/EnsurePasswordChangedMiddlewareTest.php
```

Expected: 5/5 pass.

- [ ] **Step 5: Register alias in `bootstrap/app.php`**

Find the existing `$middleware->alias([...])` block in `->withMiddleware(...)` and add:

```php
'staff.password_change_required' => \App\Http\Middleware\EnsurePasswordChanged::class,
```

(Do NOT yet apply it to any routes — that happens in Task 16 along with the routes definition.)

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Http/Middleware tests/Unit/Middleware bootstrap/app.php
git add app/Http/Middleware/EnsurePasswordChanged.php tests/Unit/Middleware/EnsurePasswordChangedMiddlewareTest.php bootstrap/app.php
git commit -m "feat(staff): EnsurePasswordChanged middleware + alias"
```

---

## Task 10: `StaffPolicy` + test

**Files:**
- Create: `app/Policies/StaffPolicy.php`
- Create: `tests/Unit/Policies/StaffPolicyTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\StaffPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');
});

it('grants every staff-management ability to admins only', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $officeStaff = User::factory()->create();
    $officeStaff->assignRole('office_staff');

    $regular = User::factory()->create();
    $target = User::factory()->create();

    $policy = new StaffPolicy();

    foreach (['viewAny', 'create'] as $ability) {
        expect($policy->$ability($admin))->toBeTrue();
        expect($policy->$ability($officeStaff))->toBeFalse();
        expect($policy->$ability($regular))->toBeFalse();
    }

    foreach (['view', 'update', 'suspend', 'reinstate', 'deactivate', 'resetTempPassword', 'manageOfficeAssignments'] as $ability) {
        expect($policy->$ability($admin, $target))->toBeTrue();
        expect($policy->$ability($officeStaff, $target))->toBeFalse();
        expect($policy->$ability($regular, $target))->toBeFalse();
    }
});
```

- [ ] **Step 2: Run test — expect failure**

```bash
vendor/bin/pest tests/Unit/Policies/StaffPolicyTest.php
```

- [ ] **Step 3: Create the policy**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class StaffPolicy
{
    public function viewAny(User $actor): bool { return $actor->hasRole('admin'); }

    public function view(User $actor, User $staff): bool { return $actor->hasRole('admin'); }

    public function create(User $actor): bool { return $actor->hasRole('admin'); }

    public function update(User $actor, User $staff): bool { return $actor->hasRole('admin'); }

    public function suspend(User $actor, User $staff): bool { return $actor->hasRole('admin'); }

    public function reinstate(User $actor, User $staff): bool { return $actor->hasRole('admin'); }

    public function deactivate(User $actor, User $staff): bool { return $actor->hasRole('admin'); }

    public function resetTempPassword(User $actor, User $staff): bool { return $actor->hasRole('admin'); }

    public function manageOfficeAssignments(User $actor, User $staff): bool { return $actor->hasRole('admin'); }
}
```

- [ ] **Step 4: Register policy in `bootstrap/app.php`**

Inside the existing service-provider boot OR a new `->withProviders([...])` block. Simplest: add to `bootstrap/app.php` inside `->withMiddleware`/etc. Actually the cleanest place is `AppServiceProvider::boot()`. Add to `app/Providers/AppServiceProvider.php`:

```php
use Illuminate\Support\Facades\Gate;
use App\Policies\StaffPolicy;
use App\Models\User as UserModel;
```

In `boot()`:

```php
Gate::policy(UserModel::class, StaffPolicy::class);
```

NOTE: Laravel's Gate auto-discovery uses convention `App\Policies\UserPolicy` for `App\Models\User`. Since we name the policy `StaffPolicy`, explicit registration is required.

- [ ] **Step 5: Run tests — expect pass**

```bash
vendor/bin/pest tests/Unit/Policies/StaffPolicyTest.php
```

Expected: passes.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint app/Policies app/Providers/AppServiceProvider.php tests/Unit/Policies
git add app/Policies/StaffPolicy.php app/Providers/AppServiceProvider.php tests/Unit/Policies/StaffPolicyTest.php
git commit -m "feat(staff): StaffPolicy + registration"
```

---

## Task 11: FormRequests

**Files:**
- Create: `app/Http/Requests/Staff/CreateStaffRequest.php`
- Create: `app/Http/Requests/Staff/UpdateStaffRequest.php`
- Create: `app/Http/Requests/Staff/ChangeFromTempPasswordRequest.php`

- [ ] **Step 1: Create `CreateStaffRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Support\DTO\CreateStaffInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'regex:/^\+218[0-9]{9}$/', 'unique:users,phone_number'],
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:120', 'unique:users,email'],
            'role' => ['required', Rule::in(['admin', 'office_staff'])],
            'office_assignments' => [
                'required_if:role,office_staff',
                'prohibited_if:role,admin',
                'array',
                'min:1',
            ],
            'office_assignments.*.office_id' => ['required', 'integer', 'exists:office_locations,id'],
            'office_assignments.*.is_manager' => ['required', 'boolean'],
        ];
    }

    public function toInput(): CreateStaffInput
    {
        return new CreateStaffInput(
            phoneNumber: $this->string('phone_number')->toString(),
            firstName: $this->string('first_name')->toString(),
            lastName: $this->string('last_name')->toString(),
            email: $this->input('email'),
            role: $this->string('role')->toString(),
            officeAssignments: $this->input('office_assignments', []),
        );
    }
}
```

- [ ] **Step 2: Create `UpdateStaffRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Support\DTO\UpdateStaffInput;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $staffId = $this->route('staff')?->id;

        return [
            'first_name' => ['sometimes', 'string', 'max:60'],
            'last_name' => ['sometimes', 'string', 'max:60'],
            'email' => ['sometimes', 'nullable', 'email', 'max:120', 'unique:users,email,'.$staffId],
        ];
    }

    public function toInput(): UpdateStaffInput
    {
        return new UpdateStaffInput(
            firstName: $this->input('first_name'),
            lastName: $this->input('last_name'),
            email: $this->input('email'),
        );
    }
}
```

- [ ] **Step 3: Create `ChangeFromTempPasswordRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ChangeFromTempPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', Password::min(8)->letters()->numbers(), 'confirmed'],
        ];
    }
}
```

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint app/Http/Requests/Staff
git add app/Http/Requests/Staff
git commit -m "feat(staff): FormRequests for create/update/change-from-temp"
```

---

## Task 12: `StaffResource`

**Files:**
- Create: `app/Http/Resources/Staff/StaffResource.php`
- Modify: `app/Models/User.php` — add `activeOfficeAssignments()` relation

- [ ] **Step 1: Add the relation to `User`**

In `app/Models/User.php`, after the existing relation methods, add:

```php
public function activeOfficeAssignments(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(\App\Models\OfficeStaffAssignment::class, 'user_id')
        ->whereNull('removed_at');
}
```

(Use FQCN in case OfficeStaffAssignment isn't already imported.)

- [ ] **Step 2: Create `StaffResource`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Staff;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class StaffResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $u */
        $u = $this->resource;

        return [
            'id' => $u->public_id,
            'phone_number' => $u->phone_number,
            'first_name' => $u->first_name,
            'last_name' => $u->last_name,
            'email' => $u->email,
            'role' => $u->getRoleNames()->first(),
            'account_status' => $u->account_status->value,
            'must_change_password' => $u->must_change_password,
            'phone_verified_at' => $u->phone_verified_at?->toIso8601String(),
            'email_verified_at' => $u->email_verified_at?->toIso8601String(),
            // Slice B's OfficeAssignmentResource is used when loaded. whenLoaded is null-safe
            // even if the relation isn't fetched (returns MissingValue → omitted from output).
            'office_assignments' => $this->whenLoaded(
                'activeOfficeAssignments',
                fn () => $u->activeOfficeAssignments->map(fn ($a) => [
                    'id' => $a->id, // Slice B replaces with public_id when migration lands
                    'office_id' => $a->office_id,
                    'is_manager' => (bool) $a->is_manager,
                    'assigned_at' => $a->assigned_at?->toIso8601String(),
                    'removed_at' => $a->removed_at?->toIso8601String(),
                ]),
            ),
            'created_at' => $u->created_at?->toIso8601String(),
            'updated_at' => $u->updated_at?->toIso8601String(),
        ];
    }
}
```

Note: the inline mapping array is a Slice A placeholder. Slice B replaces it with `OfficeAssignmentResource::collection(...)` once that Resource exists.

- [ ] **Step 3: Pint + commit**

```bash
vendor/bin/pint app/Http/Resources/Staff app/Models/User.php
git add app/Http/Resources/Staff/StaffResource.php app/Models/User.php
git commit -m "feat(staff): StaffResource + User::activeOfficeAssignments relation"
```

---

## Task 13: `StaffController` + feature test

**Files:**
- Create: `app/Http/Controllers/Api/Admin/Staff/StaffController.php`
- Create: `tests/Feature/Staff/StaffControllerTest.php`

- [ ] **Step 1: Create the controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\CreateStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\Staff\StaffResource;
use App\Models\User;
use App\Services\Staff\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class StaffController extends Controller
{
    public function __construct(private readonly StaffService $staff) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'office_staff']))
            ->with(['roles', 'activeOfficeAssignments']);

        if ($role = request('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        if ($status = request('account_status')) {
            $query->where('account_status', $status);
        }

        if ($officeId = request('office_id')) {
            $query->whereHas('activeOfficeAssignments', fn ($q) => $q->where('office_id', $officeId));
        }

        return StaffResource::collection($query->paginate((int) request('per_page', 20)));
    }

    public function show(User $staff): StaffResource
    {
        $this->authorize('view', $staff);

        return new StaffResource($staff->load(['roles', 'activeOfficeAssignments']));
    }

    public function store(CreateStaffRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $result = $this->staff->create($request->toInput(), $request->user());

        return response()->json([
            'staff' => (new StaffResource($result['user']->load('roles', 'activeOfficeAssignments')))->resolve(),
            'temporary_password' => $result['temporary_password'],
        ], 201);
    }

    public function update(UpdateStaffRequest $request, User $staff): StaffResource
    {
        $this->authorize('update', $staff);

        $updated = $this->staff->update($staff, $request->toInput());

        return new StaffResource($updated->load('roles', 'activeOfficeAssignments'));
    }

    public function suspend(User $staff): StaffResource
    {
        $this->authorize('suspend', $staff);

        return new StaffResource(
            $this->staff->suspend($staff, request()->user())->load('roles', 'activeOfficeAssignments'),
        );
    }

    public function reinstate(User $staff): StaffResource
    {
        $this->authorize('reinstate', $staff);

        return new StaffResource(
            $this->staff->reinstate($staff, request()->user())->load('roles', 'activeOfficeAssignments'),
        );
    }

    public function deactivate(User $staff): StaffResource
    {
        $this->authorize('deactivate', $staff);

        return new StaffResource(
            $this->staff->deactivate($staff, request()->user())->load('roles', 'activeOfficeAssignments'),
        );
    }

    public function resetTempPassword(User $staff): JsonResponse
    {
        $this->authorize('resetTempPassword', $staff);

        $result = $this->staff->resetTempPassword($staff, request()->user());

        return response()->json([
            'staff' => (new StaffResource($result['user']->load('roles', 'activeOfficeAssignments')))->resolve(),
            'temporary_password' => $result['temporary_password'],
        ]);
    }
}
```

- [ ] **Step 2: Update User route binding (verify it uses public_id)**

Check `app/Models/User.php` — there should be a `getRouteKeyName()` returning `'public_id'`. If not, add it:

```php
public function getRouteKeyName(): string { return 'public_id'; }
```

(Likely already exists; check before adding.)

- [ ] **Step 3: Write the feature test**

```php
<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');
});

function makeActingAdmin(): User
{
    $admin = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    return $admin;
}

it('creates an admin via POST /api/admin/staff and returns temp password ONCE', function (): void {
    makeActingAdmin();

    $response = $this->postJson('/api/admin/staff', [
        'phone_number' => '+218910000099',
        'first_name' => 'Aya',
        'last_name' => 'Smith',
        'role' => 'admin',
    ]);

    expect($response->status())->toBe(201);
    expect($response->json('staff.role'))->toBe('admin');
    expect($response->json('temporary_password'))->toBeString();
    expect(strlen($response->json('temporary_password')))->toBe(10);

    // Verify password not returned on subsequent GET
    $publicId = $response->json('staff.id');
    $show = $this->getJson("/api/admin/staff/{$publicId}");
    expect($show->json())->not->toHaveKey('temporary_password');
});

it('rejects non-admin actors with 403', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/admin/staff', [
        'phone_number' => '+218910000098',
        'first_name' => 'X',
        'last_name' => 'Y',
        'role' => 'admin',
    ]);

    expect($response->status())->toBe(403);
});

it('lists staff with role filter', function (): void {
    makeActingAdmin();
    $other = User::factory()->create();
    $other->assignRole('admin');

    $response = $this->getJson('/api/admin/staff?role=admin');

    expect($response->status())->toBe(200);
    expect($response->json('data'))->toHaveCount(2); // acting + other
});
```

- [ ] **Step 4: Run tests after Task 16 (routes) — for now, only write the file, do NOT run**

(Tests will pass once Task 16 wires the routes. Move on to other tasks.)

- [ ] **Step 5: Pint + commit (controller only, no test verification yet)**

```bash
vendor/bin/pint app/Http/Controllers/Api/Admin/Staff tests/Feature/Staff
git add app/Http/Controllers/Api/Admin/Staff/StaffController.php tests/Feature/Staff/StaffControllerTest.php
git commit -m "feat(staff): StaffController + feature test scaffold (routes wired in task 16)"
```

---

## Task 14: `ChangeFromTempPasswordController`

**Files:**
- Create: `app/Http/Controllers/Api/Me/ChangeFromTempPasswordController.php`

- [ ] **Step 1: Create the controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Me;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ChangeFromTempPasswordRequest;
use App\Http\Resources\UserResource;
use App\Services\Staff\TempPasswordChangeService;
use Illuminate\Http\JsonResponse;

final class ChangeFromTempPasswordController extends Controller
{
    public function __construct(private readonly TempPasswordChangeService $service) {}

    public function __invoke(ChangeFromTempPasswordRequest $request): JsonResponse
    {
        $result = $this->service->change(
            $request->user(),
            $request->string('current_password')->toString(),
            $request->string('new_password')->toString(),
        );

        return response()->json([
            'user' => (new UserResource($result['user']))->resolve(),
            'token' => $result['token'],
        ]);
    }
}
```

- [ ] **Step 2: Pint + commit**

```bash
vendor/bin/pint app/Http/Controllers/Api/Me/ChangeFromTempPasswordController.php
git add app/Http/Controllers/Api/Me/ChangeFromTempPasswordController.php
git commit -m "feat(staff): ChangeFromTempPasswordController"
```

---

## Task 15: Rate limiter for `password_change_temp`

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Add the rate limiter**

In `AppServiceProvider::configureRateLimiters()`, after the existing limiters, add:

```php
RateLimiter::for('password_change_temp', function (Request $request): Limit {
    $userId = (string) ($request->user()?->id ?? '');

    return Limit::perMinutes(15, 5)
        ->by('password_change_temp:'.$userId)
        ->response($this->throttleResponseCallback());
});
```

- [ ] **Step 2: Commit**

```bash
git add app/Providers/AppServiceProvider.php
git commit -m "feat(staff): rate limiter for password_change_temp (5/15min/user)"
```

---

## Task 16: Routes + apply middleware

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Add the staff route group**

In `routes/api.php`, find a good location (after the existing `/admin/*` groups). Add:

```php
// ─── /admin/staff — admin manages internal accounts (admins + office_staff) ──
Route::middleware(['auth:sanctum', 'role:admin', 'staff.password_change_required'])
    ->prefix('admin/staff')
    ->name('admin.staff.')
    ->group(function (): void {
        Route::get('/', [\App\Http\Controllers\Api\Admin\Staff\StaffController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\Api\Admin\Staff\StaffController::class, 'store'])->name('store');
        Route::get('/{staff}', [\App\Http\Controllers\Api\Admin\Staff\StaffController::class, 'show'])->name('show');
        Route::patch('/{staff}', [\App\Http\Controllers\Api\Admin\Staff\StaffController::class, 'update'])->name('update');
        Route::post('/{staff}/suspend', [\App\Http\Controllers\Api\Admin\Staff\StaffController::class, 'suspend'])->name('suspend');
        Route::post('/{staff}/reinstate', [\App\Http\Controllers\Api\Admin\Staff\StaffController::class, 'reinstate'])->name('reinstate');
        Route::post('/{staff}/deactivate', [\App\Http\Controllers\Api\Admin\Staff\StaffController::class, 'deactivate'])->name('deactivate');
        Route::post('/{staff}/reset-temp-password', [\App\Http\Controllers\Api\Admin\Staff\StaffController::class, 'resetTempPassword'])->name('reset-temp-password');
        // Slice B (Codex) appends 2 office-assignment routes here AFTER this slice merges:
        //   POST   /{staff}/office-assignments         -> store
        //   DELETE /{staff}/office-assignments/{assignment} -> destroy
    });

// ─── /me/password/change-from-temp — bypasses the password-change-required middleware ──
Route::middleware('auth:sanctum')
    ->post('/me/password/change-from-temp', \App\Http\Controllers\Api\Me\ChangeFromTempPasswordController::class)
    ->middleware('throttle:password_change_temp')
    ->name('me.password.change-from-temp');
```

- [ ] **Step 2: Apply `staff.password_change_required` to existing sanctum groups**

For every existing route group that uses `auth:sanctum`, append `staff.password_change_required` to its middleware list. Search `routes/api.php` for `'auth:sanctum'` and add the alias. Example:

```php
// BEFORE:
Route::middleware(['auth:sanctum'])->group(function (): void { ... });

// AFTER:
Route::middleware(['auth:sanctum', 'staff.password_change_required'])->group(function (): void { ... });
```

**Important exceptions** — do NOT add the middleware to:
- The `/me/password/change-from-temp` route (already separate above)
- The `/auth/logout` route (allowlisted by name in the middleware itself; safe to add the middleware too, but skipping it is simpler)

- [ ] **Step 3: Verify route names**

```bash
php artisan route:list --path=admin/staff
php artisan route:list --path=me/password
```

Expected: 8 staff routes named `admin.staff.*` and 1 route named `me.password.change-from-temp`.

- [ ] **Step 4: Run all tests including the feature tests from Tasks 13–14**

```bash
vendor/bin/pest
```

Expected: all green (previous 44 + new Slice A tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint routes/api.php
git add routes/api.php
git commit -m "feat(staff): wire /admin/staff routes + change-from-temp route + apply password-change middleware to sanctum groups"
```

---

## Task 17: Feature tests — suspension + deactivation + reset-temp

**Files:**
- Create: `tests/Feature/Staff/StaffSuspensionTest.php`
- Create: `tests/Feature/Staff/ResetTempPasswordTest.php`

- [ ] **Step 1: Write suspension test**

```php
<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
});

it('suspends another admin, revokes their tokens', function (): void {
    $actor = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $actor->assignRole('admin');
    $target = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $target->assignRole('admin');
    User::factory()->create(['account_status' => AccountStatus::Active->value])->assignRole('admin'); // last-admin protector
    $target->createToken('first');
    $target->createToken('second');

    Sanctum::actingAs($actor);

    $response = $this->postJson("/api/admin/staff/{$target->public_id}/suspend");

    expect($response->status())->toBe(200);
    expect($response->json('account_status'))->toBe(AccountStatus::Suspended->value);
    expect($target->fresh()->tokens()->count())->toBe(0);
});

it('refuses self-suspension with 422 CANNOT_SELF_MODIFY', function (): void {
    $actor = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $actor->assignRole('admin');
    User::factory()->create()->assignRole('admin');

    Sanctum::actingAs($actor);

    $response = $this->postJson("/api/admin/staff/{$actor->public_id}/suspend");

    expect($response->status())->toBe(422);
    expect($response->json('error'))->toBe('CANNOT_SELF_MODIFY');
});

it('refuses to suspend the last admin with 422 LAST_ADMIN_PROTECTED', function (): void {
    $solo = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $solo->assignRole('admin');
    $caller = User::factory()->create(['account_status' => AccountStatus::Suspended->value]);
    $caller->assignRole('admin');

    // Caller is suspended but still has admin role; we authenticate as them anyway to test the guard
    // — but Sanctum::actingAs bypasses suspension. So instead use a fresh active admin to call:
    $active = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $active->assignRole('admin');
    Sanctum::actingAs($active);

    // Now $solo and $active are active. Suspending $active first reduces to one active admin ($solo).
    $this->postJson("/api/admin/staff/{$active->public_id}/suspend"); // this fails CANNOT_SELF_MODIFY

    // Different approach: have $active suspend $solo first. Now only $active is active.
    // Then $active tries to suspend... but can't self. So we need a SECOND active admin to attempt
    // suspending $active.
    //
    // Setup: create another active admin to be the caller.
    $caller2 = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $caller2->assignRole('admin');
    // Now 3 active admins: $solo, $active, $caller2.
    // Suspend $solo:
    Sanctum::actingAs($active);
    $this->postJson("/api/admin/staff/{$solo->public_id}/suspend");
    // Now 2 active admins: $active, $caller2.
    // Switch to $caller2 and suspend $active:
    Sanctum::actingAs($caller2);
    $this->postJson("/api/admin/staff/{$active->public_id}/suspend");
    // Now 1 active admin: $caller2.
    // Anyone tries to suspend $caller2 -> LAST_ADMIN_PROTECTED.
    // Make a non-admin caller... wait, only admins reach the endpoint via the role middleware.
    // So this guard is reached only when an admin tries to suspend the only remaining admin.
    // That admin IS the caller themselves -> CANNOT_SELF_MODIFY hits first.
    //
    // The LAST_ADMIN guard is hit when the target is the last active admin AND the caller is
    // a DIFFERENT admin. So: caller must be inactive-but-still-admin-role.
    //
    // Use the suspended caller:
    $resp = $this->actingAs($solo)->postJson("/api/admin/staff/{$caller2->public_id}/suspend");
    // $solo is suspended, but actingAs bypasses that for the test. solo has admin role.
    // Solo is NOT the only active admin (caller2 is). Suspending caller2 -> 0 active -> guard.
    expect($resp->status())->toBe(422);
    expect($resp->json('error'))->toBe('LAST_ADMIN_PROTECTED');
});
```

(The third test is dense — feel free to simplify if a cleaner setup occurs to you during implementation.)

- [ ] **Step 2: Write reset-temp test**

```php
<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
});

it('admin resets another admins password — returns new temp once, tokens revoked', function (): void {
    $actor = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $actor->assignRole('admin');
    $target = User::factory()->create([
        'account_status' => AccountStatus::Active->value,
        'must_change_password' => false,
        'password' => Hash::make('originalPass1'),
    ]);
    $target->assignRole('admin');
    $target->createToken('old');

    Sanctum::actingAs($actor);

    $response = $this->postJson("/api/admin/staff/{$target->public_id}/reset-temp-password");

    expect($response->status())->toBe(200);
    expect($response->json('temporary_password'))->toBeString();
    expect(strlen($response->json('temporary_password')))->toBe(10);

    $fresh = $target->fresh();
    expect($fresh->must_change_password)->toBeTrue();
    expect(Hash::check($response->json('temporary_password'), $fresh->password))->toBeTrue();
    expect($fresh->tokens()->count())->toBe(0);
});

it('staff with must_change_password=true is blocked from other endpoints', function (): void {
    $admin = User::factory()->create([
        'account_status' => AccountStatus::Active->value,
        'must_change_password' => true,
    ]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/admin/staff');

    expect($response->status())->toBe(403);
    expect($response->json('error'))->toBe('password_change_required');
});

it('change-from-temp lets the user back in', function (): void {
    $admin = User::factory()->create([
        'account_status' => AccountStatus::Active->value,
        'must_change_password' => true,
        'password' => Hash::make('tempPass123'),
    ]);
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/me/password/change-from-temp', [
        'current_password' => 'tempPass123',
        'new_password' => 'newSecret9X1',
        'new_password_confirmation' => 'newSecret9X1',
    ]);

    expect($response->status())->toBe(200);
    expect($response->json('token'))->toBeString();
    expect($admin->fresh()->must_change_password)->toBeFalse();
});
```

- [ ] **Step 3: Run tests**

```bash
vendor/bin/pest tests/Feature/Staff
```

Expected: all green.

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint tests/Feature/Staff
git add tests/Feature/Staff/StaffSuspensionTest.php tests/Feature/Staff/ResetTempPasswordTest.php
git commit -m "test(staff): suspension + reset-temp-password feature tests"
```

---

## Task 18: Final verification + open Slice A PR

- [ ] **Step 1: Run full Pest suite**

```bash
vendor/bin/pest
```

Expected: all green (existing 44 + Slice A additions).

- [ ] **Step 2: Run orders-e2e regression**

```bash
php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"
```

Expected: 32/32 scenarios pass.

- [ ] **Step 3: Final Pint**

```bash
vendor/bin/pint
```

Expected: clean output (Pint may auto-fix files Codex touched too — ignore those for Slice A's PR; they'll land in Slice B).

- [ ] **Step 4: Push branch**

```bash
git push -u origin claude/staff-crud-core
```

- [ ] **Step 5: Open PR**

```bash
gh pr create --title "feat(staff): slice A — core staff CRUD + auth middleware" --body "$(cat <<'EOF'
## Summary

Slice A of the Staff CRUD milestone. See `docs/superpowers/specs/2026-05-20-staff-crud-design.md`.

- New migration: `users.must_change_password`
- `StaffErrorCode` enum (Slice A cases — Slice B adds 3 more post-merge)
- `StaffDomainException` with JSON renderer
- Services: `TempPasswordGenerator`, `StaffService` (create-admin, suspend, reinstate, deactivate, resetTempPassword), `TempPasswordChangeService`
- Middleware: `EnsurePasswordChanged` — blocks staff with `must_change_password=true` from all endpoints except `me.password.change-from-temp` + `auth.logout`
- Policy: `StaffPolicy` — admin-only on every ability
- HTTP: `/api/admin/staff/*` (8 endpoints) + `/api/me/password/change-from-temp`
- Rate limiter: `password_change_temp` (5/15min/user)

**Office_staff creation is stubbed with a `LogicException("slice-B")`** — Slice B (Codex's PR) replaces this stub with the real `OfficeAssignmentService::attachMany()` call.

## Test plan

- [x] Full Pest suite green
- [x] scripts/orders-e2e.php 32/32 scenarios pass
- [x] Pint clean

## What's NOT in this PR (Slice B / Codex's PR)

- `OfficeAssignmentService` + controller + resource + tests
- 3 additional `StaffErrorCode` cases
- 2 office-assignment routes (`POST` + `DELETE` under `/api/admin/staff/{staff}/office-assignments`)
- `scripts/staff-e2e.php`
- The `$this->officeAssignments->attachMany()` call in `StaffService::create()` for office_staff role

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

(If `gh` isn't available, push and open via GitHub UI using the title and body above.)

---

## Self-review checklist

After writing each task, before moving on, verify:

- [ ] **Spec coverage:** every Slice A deliverable in spec §10.3 has a task here? Migration (Task 1), User model (Task 1 step 3, Task 12 step 1), enum (Task 2), exception + renderer (Task 3), TempPasswordGenerator (Task 4), DTOs (Task 5), StaffService (Tasks 6–7), TempPasswordChangeService (Task 8), middleware (Task 9), policy (Task 10), FormRequests (Task 11), StaffResource (Task 12), StaffController (Task 13), ChangeFromTempPasswordController (Task 14), rate limiter (Task 15), routes + middleware application (Task 16), feature tests (Tasks 13, 17), final verification (Task 18). ✓
- [ ] **Placeholders:** all code blocks are complete; no "TBD"/"fill in" inside code.
- [ ] **Type consistency:** `CreateStaffInput` / `UpdateStaffInput` used consistently across DTO + FormRequest + Service. `StaffErrorCode` cases match between enum (Task 2) and service throws (Task 6).
- [ ] **`LogicException("slice-B")` marker:** Task 6's `create()` throws this for office_staff role. Slice B's plan (separate file) replaces it. The test in Task 6 step 1 asserts this exact behavior.
- [ ] **No reference to Slice B classes in production code:** Slice A's code does NOT `use` `OfficeAssignmentService` or `OfficeAssignmentResource`. Only references appear in stubs/comments.
