# Account Moderation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Consult `anthropic-skills:laravel-backend` for style, but **`docs/CLAUDE.md` conventions win** where they differ (this project uses Services — not Actions/Repositories — FormRequests, JsonResources, and domain exceptions with an error-code enum rendered in `bootstrap/app.php`).

**Goal:** Admin-only account moderation (suspend / ban / reinstate) on the `AccountStatus` axis for any user, with a structured reason, an append-only audit trail, a targeted cascade, and a minimal phone lookup — with `StaffService` routed through the same audited path.

**Architecture:** `AccountModerationService` is the sole writer of `AccountStatus` for moderation. Public guarded methods (`suspend`/`ban`/`reinstate`) enforce guards + transitions then call an internal `apply()` that writes status + revokes tokens + runs the cascade + appends an audit row. `StaffService` keeps its own `StaffErrorCode` guards (preserving the staff API contract) and delegates side-effects to `apply()`. Endpoints are admin-only, double-gated (`role:admin` + `ModerationPolicy`), `public_id`-bound.

**Tech Stack:** Laravel 13 · PHP 8.3 · PostgreSQL · Sanctum · Spatie Permission · Pest 4 · Pint.

**Spec:** `docs/superpowers/specs/2026-06-03-account-moderation-design.md` (locked).

**Prerequisites:** Docker Postgres running (`delivery-postgis`, 127.0.0.1:5432). Branch off latest `main`.

---

## File map

**New:**
- `database/migrations/2026_06_03_000200_create_account_moderation_actions_table.php`
- `app/Models/AccountModerationAction.php`
- `database/factories/AccountModerationActionFactory.php`
- `app/Enums/ModerationAction.php`
- `app/Enums/ModerationReason.php`
- `app/Enums/ModerationErrorCode.php`
- `app/Exceptions/Moderation/ModerationException.php`
- `app/Services/Moderation/AccountModerationService.php`
- `app/Policies/ModerationPolicy.php`
- `app/Http/Requests/Admin/Moderation/ModerationActionRequest.php`
- `app/Http/Requests/Admin/Moderation/UserLookupRequest.php`
- `app/Http/Resources/Moderation/UserModerationResource.php`
- `app/Http/Resources/Moderation/ModerationActionResource.php`
- `app/Http/Resources/Moderation/UserLookupResource.php`
- `app/Http/Controllers/Api/Admin/UserModerationController.php`
- `app/Http/Controllers/Api/Admin/AdminUserLookupController.php`
- `scripts/moderation-e2e.php`
- Tests: `tests/Unit/Services/Moderation/AccountModerationServiceTest.php`, `tests/Unit/Policies/ModerationPolicyTest.php`, `tests/Feature/Admin/Moderation/UserModerationTest.php`, `tests/Feature/Admin/Moderation/AdminUserLookupTest.php`

**Modified:**
- `app/Models/User.php` — `hasOutstandingFees()`, `moderationActions()` relation
- `bootstrap/app.php` — render `ModerationException`
- `app/Providers/AppServiceProvider.php` — `throttle:moderation` limiter
- `routes/api.php` — 5 routes
- `app/Services/Staff/StaffService.php` — delegate side-effects to `AccountModerationService::apply()`
- `app/Http/Controllers/Api/Admin/Staff/StaffController.php` + staff suspend/deactivate requests — optional `reason_code`/`detail`

---

## Task 1: `account_moderation_actions` table + model + factory

**Files:**
- Create: `database/migrations/2026_06_03_000200_create_account_moderation_actions_table.php`
- Create: `app/Models/AccountModerationAction.php`
- Create: `database/factories/AccountModerationActionFactory.php`
- Test: `tests/Unit/Models/AccountModerationActionTest.php`

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_06_03_000200_create_account_moderation_actions_table.php`:

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
        Schema::create('account_moderation_actions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action');        // ModerationAction
            $table->string('reason_code');   // ModerationReason
            $table->text('detail');
            $table->string('from_status');   // AccountStatus
            $table->string('to_status');     // AccountStatus
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_moderation_actions');
    }
};
```

- [ ] **Step 2: Write the model**

Create `app/Models/AccountModerationAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\ModerationAction;
use App\Enums\ModerationReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class AccountModerationAction extends Model
{
    use HasFactory;

    public const UPDATED_AT = null; // append-only — created_at only

    /** @var array<int, string> */
    protected $fillable = [
        'public_id', 'user_id', 'actor_id',
        'action', 'reason_code', 'detail',
        'from_status', 'to_status',
    ];

    protected static function booted(): void
    {
        self::creating(static function (AccountModerationAction $row): void {
            if (empty($row->public_id)) {
                $row->public_id = (string) Str::ulid();
            }
            if (empty($row->created_at)) {
                $row->created_at = now();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => ModerationAction::class,
            'reason_code' => ModerationReason::class,
            'from_status' => AccountStatus::class,
            'to_status' => AccountStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
```

> NOTE: Enums referenced here are created in Task 2. If implementing strictly task-by-task, expect a "class not found" until Task 2 lands; run this task's test after Task 2.

- [ ] **Step 3: Write the factory**

Create `database/factories/AccountModerationActionFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\ModerationAction;
use App\Enums\ModerationReason;
use App\Models\AccountModerationAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccountModerationAction> */
final class AccountModerationActionFactory extends Factory
{
    protected $model = AccountModerationAction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'actor_id' => User::factory(),
            'action' => ModerationAction::Suspend->value,
            'reason_code' => ModerationReason::Other->value,
            'detail' => $this->faker->sentence(),
            'from_status' => AccountStatus::Active->value,
            'to_status' => AccountStatus::Suspended->value,
        ];
    }
}
```

- [ ] **Step 4: Write the model test**

Create `tests/Unit/Models/AccountModerationActionTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\ModerationAction;
use App\Models\AccountModerationAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('auto-assigns a public_id and casts enums', function (): void {
    $row = AccountModerationAction::factory()->create();

    expect($row->public_id)->not->toBeEmpty();
    expect($row->getRouteKeyName())->toBe('public_id');
    expect($row->action)->toBeInstanceOf(ModerationAction::class);
    expect($row->created_at)->not->toBeNull();
});

it('exposes target and actor relations', function (): void {
    $row = AccountModerationAction::factory()->create();

    expect($row->user)->not->toBeNull();
    expect($row->actor)->not->toBeNull();
});
```

- [ ] **Step 5: Run migrations + test (after Task 2 enums exist)**

```bash
php artisan migrate --force
vendor\bin\pest tests/Unit/Models/AccountModerationActionTest.php
```
Expected: PASS (2 tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor\bin\pint database/migrations app/Models/AccountModerationAction.php database/factories/AccountModerationActionFactory.php tests/Unit/Models
git add database/migrations app/Models/AccountModerationAction.php database/factories/AccountModerationActionFactory.php tests/Unit/Models/AccountModerationActionTest.php
git commit -m "feat(moderation): account_moderation_actions table + model + factory"
```

---

## Task 2: Enums — `ModerationAction`, `ModerationReason`, `ModerationErrorCode`

**Files:**
- Create: `app/Enums/ModerationAction.php`, `app/Enums/ModerationReason.php`, `app/Enums/ModerationErrorCode.php`
- Test: `tests/Unit/Enums/ModerationErrorCodeTest.php`

- [ ] **Step 1: Create `ModerationAction`**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum ModerationAction: string
{
    case Suspend = 'suspend';
    case Ban = 'ban';
    case Reinstate = 'reinstate';
}
```

- [ ] **Step 2: Create `ModerationReason`**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum ModerationReason: string
{
    case Fraud = 'fraud';
    case Abuse = 'abuse';
    case NonPayment = 'non_payment';
    case PolicyViolation = 'policy_violation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fraud => 'Fraud',
            self::Abuse => 'Abuse',
            self::NonPayment => 'Non-payment',
            self::PolicyViolation => 'Policy violation',
            self::Other => 'Other',
        };
    }
}
```

- [ ] **Step 3: Create `ModerationErrorCode`**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum ModerationErrorCode: string
{
    case CannotModerateSelf = 'CANNOT_MODERATE_SELF';
    case LastActiveAdmin = 'LAST_ACTIVE_ADMIN';
    case InvalidTransition = 'INVALID_TRANSITION';

    public function httpStatus(): int
    {
        return match ($this) {
            self::CannotModerateSelf,
            self::LastActiveAdmin,
            self::InvalidTransition => 422,
        };
    }
}
```

- [ ] **Step 4: Write the test**

Create `tests/Unit/Enums/ModerationErrorCodeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\ModerationErrorCode;

it('maps every error code to 422', function (): void {
    foreach (ModerationErrorCode::cases() as $code) {
        expect($code->httpStatus())->toBe(422);
    }
});
```

- [ ] **Step 5: Run test**

```bash
vendor\bin\pest tests/Unit/Enums/ModerationErrorCodeTest.php
```
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor\bin\pint app/Enums tests/Unit/Enums
git add app/Enums/ModerationAction.php app/Enums/ModerationReason.php app/Enums/ModerationErrorCode.php tests/Unit/Enums/ModerationErrorCodeTest.php
git commit -m "feat(moderation): ModerationAction/Reason/ErrorCode enums"
```

---

## Task 3: `ModerationException` + JSON render

**Files:**
- Create: `app/Exceptions/Moderation/ModerationException.php`
- Modify: `bootstrap/app.php`

- [ ] **Step 1: Create the exception** (mirrors `StaffDomainException`)

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Moderation;

use App\Enums\ModerationErrorCode;
use RuntimeException;

final class ModerationException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly ModerationErrorCode $errorCode,
        string $message,
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

- [ ] **Step 2: Register the render in `bootstrap/app.php`**

Add the import at top:
```php
use App\Exceptions\Moderation\ModerationException;
```

Inside `->withExceptions(function (Exceptions $exceptions): void {`, after the `StaffDomainException` render block, add:
```php
        $exceptions->render(function (ModerationException $e): JsonResponse {
            return new JsonResponse($e->toResponse(), $e->httpStatus());
        });
```

- [ ] **Step 3: Pint + commit**

```bash
vendor\bin\pint app/Exceptions/Moderation bootstrap/app.php
git add app/Exceptions/Moderation/ModerationException.php bootstrap/app.php
git commit -m "feat(moderation): ModerationException + JSON render"
```

---

## Task 4: `User::hasOutstandingFees()` + `moderationActions()` relation

**Files:**
- Modify: `app/Models/User.php`
- Test: `tests/Unit/Models/UserOutstandingFeesTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Models/UserOutstandingFeesTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\DriverAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('is false for a user with no driver account', function (): void {
    expect(User::factory()->create()->hasOutstandingFees())->toBeFalse();
});

it('is false when driver debt is zero', function (): void {
    $user = User::factory()->create();
    DriverAccount::factory()->create(['driver_id' => $user->id, 'debt_balance' => '0.00']);

    expect($user->fresh()->hasOutstandingFees())->toBeFalse();
});

it('is true when driver debt is positive', function (): void {
    $user = User::factory()->create();
    DriverAccount::factory()->create(['driver_id' => $user->id, 'debt_balance' => '15.00']);

    expect($user->fresh()->hasOutstandingFees())->toBeTrue();
});
```

- [ ] **Step 2: Run — expect failure** (`Call to undefined method ... hasOutstandingFees()`)

```bash
vendor\bin\pest tests/Unit/Models/UserOutstandingFeesTest.php
```

- [ ] **Step 3: Implement on `User`**

Add the import near the other model imports:
```php
use App\Models\AccountModerationAction;
use Illuminate\Database\Eloquent\Relations\HasMany;
```

Add these methods to `User` (near the other relations):
```php
    public function moderationActions(): HasMany
    {
        return $this->hasMany(AccountModerationAction::class, 'user_id');
    }

    /**
     * Whether the user currently owes platform fees. MVP: a driver with a
     * positive debt_balance. Extend here when user-level (seller) fee debt is
     * tracked. Used by moderation reinstate to land debtors in
     * suspended_unpaid_fees rather than active (spec §6.5 / §10).
     */
    public function hasOutstandingFees(): bool
    {
        $account = $this->driverAccount;

        return $account !== null
            && bccomp((string) $account->debt_balance, '0', 2) === 1;
    }
```

- [ ] **Step 4: Run — expect pass**

```bash
vendor\bin\pest tests/Unit/Models/UserOutstandingFeesTest.php
```

- [ ] **Step 5: Pint + commit**

```bash
vendor\bin\pint app/Models/User.php tests/Unit/Models/UserOutstandingFeesTest.php
git add app/Models/User.php tests/Unit/Models/UserOutstandingFeesTest.php
git commit -m "feat(moderation): User::hasOutstandingFees + moderationActions relation"
```

---

## Task 5: `AccountModerationService`

The core. Public guarded `suspend`/`ban`/`reinstate`; internal `apply()` performs status write + token revoke + cascade + audit (used by `StaffService` too).

**Files:**
- Create: `app/Services/Moderation/AccountModerationService.php`
- Test: `tests/Unit/Services/Moderation/AccountModerationServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Moderation/AccountModerationServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\ModerationAction;
use App\Enums\ModerationReason;
use App\Exceptions\Moderation\ModerationException;
use App\Models\AccountModerationAction;
use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Moderation\AccountModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('driver', 'web');
    $this->service = app(AccountModerationService::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('suspends an active user, revokes tokens, and writes an audit row', function (): void {
    $target = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $target->createToken('t');

    $result = $this->service->suspend($target, $this->admin, ModerationReason::Abuse, 'spam');

    expect($result->account_status)->toBe(AccountStatus::Suspended);
    expect($target->tokens()->count())->toBe(0);

    $row = AccountModerationAction::query()->latest('id')->first();
    expect($row->action)->toBe(ModerationAction::Suspend);
    expect($row->reason_code)->toBe(ModerationReason::Abuse);
    expect($row->from_status)->toBe(AccountStatus::Active);
    expect($row->to_status)->toBe(AccountStatus::Suspended);
    expect($row->user_id)->toBe($target->id);
    expect($row->actor_id)->toBe($this->admin->id);
});

it('bans a user from any non-banned status', function (): void {
    $target = User::factory()->create(['account_status' => AccountStatus::Suspended->value]);

    $result = $this->service->ban($target, $this->admin, ModerationReason::Fraud, 'chargebacks');

    expect($result->account_status)->toBe(AccountStatus::Banned);
});

it('forces an online driver offline on suspend (cascade) without touching DriverStatus', function (): void {
    $driver = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $driver->assignRole('driver');
    DriverProfile::factory()->create([
        'user_id' => $driver->id,
        'status' => DriverStatus::Active->value,
        'activity_status' => DriverActivityStatus::Online->value,
    ]);

    $this->service->ban($driver, $this->admin, ModerationReason::Fraud, 'x');

    $profile = DriverProfile::query()->where('user_id', $driver->id)->first();
    expect($profile->activity_status)->toBe(DriverActivityStatus::Offline);
    expect($profile->status)->toBe(DriverStatus::Active); // DriverStatus untouched
});

it('reinstates a suspended user to active when no fees are owed', function (): void {
    $target = User::factory()->create(['account_status' => AccountStatus::Suspended->value]);

    $result = $this->service->reinstate($target, $this->admin, ModerationReason::Other, 'appeal upheld');

    expect($result->account_status)->toBe(AccountStatus::Active);
});

it('reinstates a debtor into suspended_unpaid_fees', function (): void {
    $target = User::factory()->create(['account_status' => AccountStatus::Banned->value]);
    DriverAccount::factory()->create(['driver_id' => $target->id, 'debt_balance' => '20.00']);

    $result = $this->service->reinstate($target, $this->admin, ModerationReason::Other, 'x');

    expect($result->account_status)->toBe(AccountStatus::SuspendedUnpaidFees);
});

it('rejects moderating yourself', function (): void {
    $this->service->suspend($this->admin, $this->admin, ModerationReason::Other, 'x');
})->throws(ModerationException::class);

it('rejects suspending the last active admin', function (): void {
    // $this->admin is the only active admin
    $this->service->suspend($this->admin, User::factory()->create()->tap(fn ($u) => $u->assignRole('admin')), ModerationReason::Other, 'x');
})->throws(ModerationException::class);

it('rejects no-op transitions (suspend an already-suspended user)', function (): void {
    $target = User::factory()->create(['account_status' => AccountStatus::Suspended->value]);
    $this->service->suspend($target, $this->admin, ModerationReason::Other, 'x');
})->throws(ModerationException::class);
```

> NOTE: the "last active admin" test makes the *actor* a second admin and the *target* `$this->admin` (the only active admin besides the actor — but the actor is being created inline; ensure the target is the sole pre-existing active admin). Adjust the inline actor creation if your factory state differs; the assertion is that suspending the final active admin throws.

- [ ] **Step 2: Run — expect failure** (class not found)

```bash
vendor\bin\pest tests/Unit/Services/Moderation/AccountModerationServiceTest.php
```

- [ ] **Step 3: Implement the service**

Create `app/Services/Moderation/AccountModerationService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Moderation;

use App\Enums\AccountStatus;
use App\Enums\DriverActivityStatus;
use App\Enums\ModerationAction;
use App\Enums\ModerationErrorCode;
use App\Enums\ModerationReason;
use App\Exceptions\Moderation\ModerationException;
use App\Models\AccountModerationAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AccountModerationService
{
    public function suspend(User $target, User $actor, ModerationReason $reason, string $detail): User
    {
        $this->assertNotSelf($target, $actor);
        $this->assertNotLastActiveAdmin($target);
        $this->assertTransitionAllowed($target->account_status, ModerationAction::Suspend);

        return $this->apply($target, $actor, ModerationAction::Suspend, AccountStatus::Suspended, $reason, $detail);
    }

    public function ban(User $target, User $actor, ModerationReason $reason, string $detail): User
    {
        $this->assertNotSelf($target, $actor);
        $this->assertNotLastActiveAdmin($target);
        $this->assertTransitionAllowed($target->account_status, ModerationAction::Ban);

        return $this->apply($target, $actor, ModerationAction::Ban, AccountStatus::Banned, $reason, $detail);
    }

    public function reinstate(User $target, User $actor, ModerationReason $reason, string $detail): User
    {
        $this->assertNotSelf($target, $actor);
        $this->assertTransitionAllowed($target->account_status, ModerationAction::Reinstate);

        $to = $target->hasOutstandingFees()
            ? AccountStatus::SuspendedUnpaidFees
            : AccountStatus::Active;

        return $this->apply($target, $actor, ModerationAction::Reinstate, $to, $reason, $detail);
    }

    /**
     * Status write + token revoke + cascade + audit. NO guards/transition
     * enforcement — callers that own their own guards (e.g. StaffService) use
     * this directly. Public moderation methods call it after guarding.
     */
    public function apply(
        User $target,
        User $actor,
        ModerationAction $action,
        AccountStatus $toStatus,
        ModerationReason $reason,
        string $detail,
    ): User {
        return DB::transaction(function () use ($target, $actor, $action, $toStatus, $reason, $detail): User {
            $fromStatus = $target->account_status;

            $target->forceFill(['account_status' => $toStatus->value])->save();

            if ($action !== ModerationAction::Reinstate) {
                $target->tokens()->delete();
                $this->cascade($target);
            }

            AccountModerationAction::create([
                'user_id' => $target->id,
                'actor_id' => $actor->id,
                'action' => $action->value,
                'reason_code' => $reason->value,
                'detail' => $detail,
                'from_status' => $fromStatus->value,
                'to_status' => $toStatus->value,
            ]);

            return $target->fresh();
        });
    }

    /**
     * Targeted cascade: drivers are forced offline so BroadcastService's
     * eligibleDriversFor (which filters activity_status = online) drops them.
     * DriverStatus (operational axis) is intentionally NOT touched. A live
     * delivery is left for support/AdminAssignmentService — not auto-cancelled.
     */
    private function cascade(User $target): void
    {
        $profile = $target->driverProfile;
        if ($profile !== null && $profile->activity_status !== DriverActivityStatus::Offline) {
            $profile->forceFill(['activity_status' => DriverActivityStatus::Offline->value])->save();
        }
    }

    private function assertNotSelf(User $target, User $actor): void
    {
        if ($target->id === $actor->id) {
            throw new ModerationException(
                ModerationErrorCode::CannotModerateSelf,
                'You cannot moderate your own account.',
            );
        }
    }

    private function assertNotLastActiveAdmin(User $target): void
    {
        if (! $target->hasRole('admin')) {
            return;
        }

        $otherActiveAdmins = User::query()
            ->role('admin')
            ->where('account_status', AccountStatus::Active->value)
            ->where('id', '!=', $target->id)
            ->count();

        if ($otherActiveAdmins < 1) {
            throw new ModerationException(
                ModerationErrorCode::LastActiveAdmin,
                'Cannot suspend or ban the last active admin.',
            );
        }
    }

    private function assertTransitionAllowed(AccountStatus $from, ModerationAction $action): void
    {
        $allowed = match ($action) {
            ModerationAction::Suspend => in_array($from, [AccountStatus::Active, AccountStatus::PendingVerification], true),
            ModerationAction::Ban => $from !== AccountStatus::Banned,
            ModerationAction::Reinstate => in_array($from, [AccountStatus::Suspended, AccountStatus::Banned], true),
        };

        if (! $allowed) {
            throw new ModerationException(
                ModerationErrorCode::InvalidTransition,
                "Cannot {$action->value} a user whose status is {$from->value}.",
            );
        }
    }
}
```

- [ ] **Step 4: Run — expect pass** (all service tests)

```bash
vendor\bin\pest tests/Unit/Services/Moderation/AccountModerationServiceTest.php
```
Expected: PASS (8 tests). If the "last active admin" test wiring needs tweaks, fix the test setup (the production guard is correct).

- [ ] **Step 5: Pint + commit**

```bash
vendor\bin\pint app/Services/Moderation tests/Unit/Services/Moderation
git add app/Services/Moderation/AccountModerationService.php tests/Unit/Services/Moderation/AccountModerationServiceTest.php
git commit -m "feat(moderation): AccountModerationService — suspend/ban/reinstate + guards + cascade + audit"
```

---

## Task 6: `ModerationPolicy`

**Files:**
- Create: `app/Policies/ModerationPolicy.php`
- Test: `tests/Unit/Policies/ModerationPolicyTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Policies/ModerationPolicyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\ModerationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => Role::findOrCreate('admin', 'web'));

it('allows an admin to moderate another user', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    expect((new ModerationPolicy)->moderate($admin, User::factory()->create()))->toBeTrue();
});

it('forbids a non-admin', function (): void {
    expect((new ModerationPolicy)->moderate(User::factory()->create(), User::factory()->create()))->toBeFalse();
});

it('forbids moderating yourself', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    expect((new ModerationPolicy)->moderate($admin, $admin))->toBeFalse();
});
```

- [ ] **Step 2: Run — expect failure**

```bash
vendor\bin\pest tests/Unit/Policies/ModerationPolicyTest.php
```

- [ ] **Step 3: Implement the policy**

Create `app/Policies/ModerationPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class ModerationPolicy
{
    public function moderate(User $actor, User $target): bool
    {
        return $actor->hasRole('admin') && $actor->id !== $target->id;
    }
}
```

(Laravel 11+ auto-discovers `App\Policies\ModerationPolicy` for the `User` model only if named `UserPolicy`. Since this is a non-default policy, controllers call `$this->authorize('moderate', $targetUser)` with an explicit `Gate`/policy method — register it in Step 4.)

- [ ] **Step 4: Register the policy mapping**

In `app/Providers/AppServiceProvider.php` `boot()`, add (with `use App\Models\User; use App\Policies\ModerationPolicy; use Illuminate\Support\Facades\Gate;`):
```php
        Gate::define('moderate', [ModerationPolicy::class, 'moderate']);
```

- [ ] **Step 5: Run — expect pass; Pint + commit**

```bash
vendor\bin\pest tests/Unit/Policies/ModerationPolicyTest.php
vendor\bin\pint app/Policies app/Providers/AppServiceProvider.php tests/Unit/Policies
git add app/Policies/ModerationPolicy.php app/Providers/AppServiceProvider.php tests/Unit/Policies/ModerationPolicyTest.php
git commit -m "feat(moderation): ModerationPolicy + moderate gate"
```

---

## Task 7: FormRequests + Resources

**Files:**
- Create: `app/Http/Requests/Admin/Moderation/ModerationActionRequest.php`, `UserLookupRequest.php`
- Create: `app/Http/Resources/Moderation/UserModerationResource.php`, `ModerationActionResource.php`, `UserLookupResource.php`
- Test: `tests/Unit/Resources/Moderation/ModerationResourcesTest.php`

- [ ] **Step 1: `ModerationActionRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Moderation;

use App\Enums\ModerationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ModerationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason_code' => ['required', Rule::enum(ModerationReason::class)],
            'detail' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function reason(): ModerationReason
    {
        return ModerationReason::from((string) $this->string('reason_code'));
    }

    public function detail(): string
    {
        return (string) $this->string('detail');
    }
}
```

- [ ] **Step 2: `UserLookupRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Moderation;

use Illuminate\Foundation\Http\FormRequest;

final class UserLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
        ];
    }
}
```

- [ ] **Step 3: Resources**

`app/Http/Resources/Moderation/ModerationActionResource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Moderation;

use App\Models\AccountModerationAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccountModerationAction */
final class ModerationActionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'action' => $this->action->value,
            'reason_code' => $this->reason_code->value,
            'detail' => $this->detail,
            'from_status' => $this->from_status->value,
            'to_status' => $this->to_status->value,
            'actor' => [
                'id' => $this->whenLoaded('actor', fn () => $this->actor?->public_id),
                'name' => $this->whenLoaded('actor', fn () => trim((string) ($this->actor?->first_name.' '.$this->actor?->last_name))),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

`app/Http/Resources/Moderation/UserModerationResource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Moderation;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserModerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'account_status' => $this->account_status->value,
            'latest_action' => $this->whenLoaded(
                'moderationActions',
                fn () => new ModerationActionResource($this->moderationActions->first()),
            ),
        ];
    }
}
```

`app/Http/Resources/Moderation/UserLookupResource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Moderation;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserLookupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => trim((string) ($this->first_name.' '.$this->last_name)),
            'phone' => $this->phone_number,
            'roles' => $this->getRoleNames()->values()->all(),
            'account_status' => $this->account_status->value,
        ];
    }
}
```

- [ ] **Step 4: Test the resources are public-id-only**

Create `tests/Unit/Resources/Moderation/ModerationResourcesTest.php`:

```php
<?php

declare(strict_types=1);

use App\Http\Resources\Moderation\UserLookupResource;
use App\Http\Resources\Moderation\UserModerationResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('user moderation resource exposes public_id as id, no internal id', function (): void {
    $user = User::factory()->create();
    $array = (new UserModerationResource($user))->resolve();

    expect($array['id'])->toBe($user->public_id);
    expect($array)->not->toHaveKey('user_id');
});

it('lookup resource returns thin identity with public_id', function (): void {
    $user = User::factory()->create();
    $array = (new UserLookupResource($user))->resolve();

    expect($array)->toHaveKeys(['id', 'name', 'phone', 'roles', 'account_status']);
    expect($array['id'])->toBe($user->public_id);
});
```

- [ ] **Step 5: Run + Pint + commit**

```bash
vendor\bin\pest tests/Unit/Resources/Moderation/ModerationResourcesTest.php
vendor\bin\pint app/Http/Requests/Admin/Moderation app/Http/Resources/Moderation tests/Unit/Resources/Moderation
git add app/Http/Requests/Admin/Moderation app/Http/Resources/Moderation tests/Unit/Resources/Moderation
git commit -m "feat(moderation): form requests + resources (action/user/lookup)"
```

---

## Task 8: Controllers + routes + throttle limiter

**Files:**
- Create: `app/Http/Controllers/Api/Admin/UserModerationController.php`, `AdminUserLookupController.php`
- Modify: `routes/api.php`, `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Admin/Moderation/UserModerationTest.php`, `tests/Feature/Admin/Moderation/AdminUserLookupTest.php`

- [ ] **Step 1: Write the failing feature tests**

Create `tests/Feature/Admin/Moderation/UserModerationTest.php`:

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
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('admin suspends a user and an audit row is written', function (): void {
    Sanctum::actingAs($this->admin);
    $target = User::factory()->create(['account_status' => AccountStatus::Active->value]);

    $response = $this->postJson("/api/admin/users/{$target->public_id}/suspend", [
        'reason_code' => 'abuse',
        'detail' => 'spamming receivers',
    ]);

    $response->assertOk()->assertJsonPath('data.account_status', 'suspended');
    $this->assertDatabaseHas('account_moderation_actions', [
        'user_id' => $target->id,
        'action' => 'suspend',
        'reason_code' => 'abuse',
    ]);
});

it('rejects a non-admin (403)', function (): void {
    Sanctum::actingAs(User::factory()->create());
    $target = User::factory()->create();

    $this->postJson("/api/admin/users/{$target->public_id}/ban", [
        'reason_code' => 'fraud', 'detail' => 'xxx',
    ])->assertForbidden();
});

it('rejects self-moderation (422)', function (): void {
    Sanctum::actingAs($this->admin);

    $this->postJson("/api/admin/users/{$this->admin->public_id}/suspend", [
        'reason_code' => 'other', 'detail' => 'self',
    ])->assertStatus(422)->assertJsonPath('error', 'CANNOT_MODERATE_SELF');
});

it('validates reason_code and detail (422)', function (): void {
    Sanctum::actingAs($this->admin);
    $target = User::factory()->create();

    $this->postJson("/api/admin/users/{$target->public_id}/suspend", [
        'reason_code' => 'not_a_reason', 'detail' => 'x',
    ])->assertStatus(422);
});

it('reinstates and returns moderation history', function (): void {
    Sanctum::actingAs($this->admin);
    $target = User::factory()->create(['account_status' => AccountStatus::Suspended->value]);

    $this->postJson("/api/admin/users/{$target->public_id}/reinstate", [
        'reason_code' => 'other', 'detail' => 'appeal upheld',
    ])->assertOk()->assertJsonPath('data.account_status', 'active');

    $history = $this->getJson("/api/admin/users/{$target->public_id}/moderation-history");
    $history->assertOk()->assertJsonPath('data.0.action', 'reinstate');
    $history->assertJsonPath('data.0.actor.id', $this->admin->public_id);
});
```

Create `tests/Feature/Admin/Moderation/AdminUserLookupTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('admin looks up a user by phone', function (): void {
    Sanctum::actingAs($this->admin);
    $target = User::factory()->create(['phone_number' => '+218910000111']);

    $this->getJson('/api/admin/users/lookup?phone=%2B218910000111')
        ->assertOk()
        ->assertJsonPath('data.id', $target->public_id)
        ->assertJsonPath('data.phone', '+218910000111');
});

it('returns null data for an unknown phone', function (): void {
    Sanctum::actingAs($this->admin);

    $this->getJson('/api/admin/users/lookup?phone=%2B218999999999')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('forbids non-admin lookup', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/admin/users/lookup?phone=%2B218910000111')->assertForbidden();
});
```

- [ ] **Step 2: Run — expect failure** (routes/controllers missing)

```bash
vendor\bin\pest tests/Feature/Admin/Moderation
```

- [ ] **Step 3: Implement `UserModerationController`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Moderation\ModerationActionRequest;
use App\Http\Resources\Moderation\ModerationActionResource;
use App\Http\Resources\Moderation\UserModerationResource;
use App\Models\User;
use App\Services\Moderation\AccountModerationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class UserModerationController extends Controller
{
    public function __construct(private readonly AccountModerationService $moderation) {}

    public function suspend(ModerationActionRequest $request, User $user): UserModerationResource
    {
        $this->authorize('moderate', $user);
        $updated = $this->moderation->suspend($user, $request->user(), $request->reason(), $request->detail());

        return new UserModerationResource($updated->load('moderationActions'));
    }

    public function ban(ModerationActionRequest $request, User $user): UserModerationResource
    {
        $this->authorize('moderate', $user);
        $updated = $this->moderation->ban($user, $request->user(), $request->reason(), $request->detail());

        return new UserModerationResource($updated->load('moderationActions'));
    }

    public function reinstate(ModerationActionRequest $request, User $user): UserModerationResource
    {
        $this->authorize('moderate', $user);
        $updated = $this->moderation->reinstate($user, $request->user(), $request->reason(), $request->detail());

        return new UserModerationResource($updated->load('moderationActions'));
    }

    public function history(Request $request, User $user): AnonymousResourceCollection
    {
        $actions = $user->moderationActions()
            ->with('actor')
            ->latest('id')
            ->paginate(20);

        return ModerationActionResource::collection($actions);
    }
}
```

> The `latest_action` in `UserModerationResource` relies on `moderationActions` being loaded; `->load('moderationActions')` after the action returns it newest-first if you add a default order. To guarantee newest-first, load explicitly: replace `$updated->load('moderationActions')` with `$updated->load(['moderationActions' => fn ($q) => $q->latest('id')->limit(1)])`.

- [ ] **Step 4: Implement `AdminUserLookupController`** (single-action)

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Moderation\UserLookupRequest;
use App\Http\Resources\Moderation\UserLookupResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class AdminUserLookupController extends Controller
{
    public function __invoke(UserLookupRequest $request): UserLookupResource|JsonResponse
    {
        $user = User::query()
            ->with('roles')
            ->where('phone_number', (string) $request->string('phone'))
            ->first();

        if ($user === null) {
            return new JsonResponse(['data' => null]);
        }

        return new UserLookupResource($user);
    }
}
```

- [ ] **Step 5: Register routes**

In `routes/api.php` add the import near other admin controllers:
```php
use App\Http\Controllers\Api\Admin\AdminUserLookupController;
use App\Http\Controllers\Api\Admin\UserModerationController;
```

Add a new group (after the `admin/staff` group):
```php
// ─── /admin/users — admin account moderation ───────────────────────────────
Route::middleware(['auth:sanctum', 'role:admin', 'staff.password_change_required', 'throttle:moderation'])
    ->prefix('admin/users')
    ->name('admin.users.')
    ->group(function (): void {
        Route::get('lookup', AdminUserLookupController::class)->name('lookup');
        Route::post('{user}/suspend', [UserModerationController::class, 'suspend'])->name('suspend');
        Route::post('{user}/ban', [UserModerationController::class, 'ban'])->name('ban');
        Route::post('{user}/reinstate', [UserModerationController::class, 'reinstate'])->name('reinstate');
        Route::get('{user}/moderation-history', [UserModerationController::class, 'history'])->name('moderation-history');
    });
```

> `{user}` binds by `public_id` automatically (User::getRouteKeyName). `lookup` is registered before `{user}/...` so it is not shadowed by the wildcard.

- [ ] **Step 6: Add the `throttle:moderation` limiter**

In `app/Providers/AppServiceProvider.php` `configureRateLimiters()`, add (alongside the others):
```php
        RateLimiter::for('moderation', function (Request $request): Limit {
            $key = (string) ($request->user()?->id ?? $request->ip());

            return Limit::perMinute(30)
                ->by('moderation:'.$key)
                ->response($this->throttleResponseCallback());
        });
```

- [ ] **Step 7: Run — expect pass**

```bash
vendor\bin\pest tests/Feature/Admin/Moderation
```
Expected: PASS (all). Fix any binding/route-order issues until green.

- [ ] **Step 8: Pint + commit**

```bash
vendor\bin\pint app/Http/Controllers/Api/Admin routes/api.php app/Providers/AppServiceProvider.php tests/Feature/Admin/Moderation
git add app/Http/Controllers/Api/Admin/UserModerationController.php app/Http/Controllers/Api/Admin/AdminUserLookupController.php routes/api.php app/Providers/AppServiceProvider.php tests/Feature/Admin/Moderation
git commit -m "feat(moderation): admin endpoints (suspend/ban/reinstate/history/lookup) + throttle"
```

---

## Task 9: Route `StaffService` through the audited path

Keep `StaffService`'s own `StaffErrorCode` guards (preserve the staff API contract + existing tests); delegate the status write + token revoke + cascade + audit to `AccountModerationService::apply()`. Add optional `reason_code`/`detail` to staff suspend/deactivate.

**Files:**
- Modify: `app/Services/Staff/StaffService.php`
- Modify: `app/Http/Controllers/Api/Admin/Staff/StaffController.php` + the staff suspend/deactivate FormRequests (or inline request)
- Test: `tests/Feature/Admin/Moderation/StaffModerationAuditTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/Moderation/StaffModerationAuditTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Models\User;
use App\Services\Staff\StaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('office_staff', 'web');
});

it('staff suspension writes an account_moderation_actions audit row', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $staff = User::factory()->create(['account_status' => AccountStatus::Active->value]);
    $staff->assignRole('office_staff');

    app(StaffService::class)->suspend($staff, $admin);

    expect($staff->fresh()->account_status)->toBe(AccountStatus::Suspended);
    $this->assertDatabaseHas('account_moderation_actions', [
        'user_id' => $staff->id,
        'actor_id' => $admin->id,
        'action' => 'suspend',
    ]);
});
```

- [ ] **Step 2: Run — expect failure** (no audit row yet)

```bash
vendor\bin\pest tests/Feature/Admin/Moderation/StaffModerationAuditTest.php
```

- [ ] **Step 3: Refactor `StaffService`**

Add imports:
```php
use App\Enums\ModerationAction;
use App\Enums\ModerationReason;
use App\Services\Moderation\AccountModerationService;
```

Inject the service:
```php
    public function __construct(
        private readonly TempPasswordGenerator $passwords,
        private readonly OfficeAssignmentService $officeAssignments,
        private readonly AccountModerationService $moderation,
    ) {}
```

Replace `suspend`, `reinstate`, `deactivate` with delegating versions (keep the guard calls):
```php
    public function suspend(User $staff, User $actor, ?ModerationReason $reason = null, ?string $detail = null): User
    {
        $this->assertNotSelf($staff, $actor);
        $this->assertNotLastAdmin($staff);

        return $this->moderation->apply(
            $staff, $actor, ModerationAction::Suspend, AccountStatus::Suspended,
            $reason ?? ModerationReason::Other,
            $detail ?? 'Staff lifecycle suspension.',
        );
    }

    public function reinstate(User $staff, User $actor, ?ModerationReason $reason = null, ?string $detail = null): User
    {
        $to = $staff->hasOutstandingFees()
            ? AccountStatus::SuspendedUnpaidFees
            : AccountStatus::Active;

        return $this->moderation->apply(
            $staff, $actor, ModerationAction::Reinstate, $to,
            $reason ?? ModerationReason::Other,
            $detail ?? 'Staff lifecycle reinstatement.',
        );
    }

    public function deactivate(User $staff, User $actor, ?ModerationReason $reason = null, ?string $detail = null): User
    {
        $this->assertNotSelf($staff, $actor);
        $this->assertNotLastAdmin($staff);

        return DB::transaction(function () use ($staff, $actor, $reason, $detail): User {
            $this->moderation->apply(
                $staff, $actor, ModerationAction::Suspend, AccountStatus::Suspended,
                $reason ?? ModerationReason::Other,
                $detail ?? 'Staff lifecycle deactivation.',
            );

            OfficeStaffAssignment::query()
                ->where('user_id', $staff->id)
                ->whereNull('removed_at')
                ->update(['removed_at' => now()]);

            return $staff->fresh();
        });
    }
```

> `assertNotLastAdmin` and `assertNotSelf` stay (still used here + by `resetTempPassword`). `StaffService` no longer writes `account_status` directly — `apply()` is the sole writer. The nested `DB::transaction` in `deactivate` wraps `apply()`'s own transaction via a savepoint — safe.

- [ ] **Step 4: Thread optional reason through the staff controller**

In `StaffController::suspend` and `::deactivate`, read optional `reason_code`/`detail` from the request and pass them. If the staff suspend/deactivate routes use a bare `Request`, accept optional fields:
```php
public function suspend(Request $request, User $staff): StaffResource
{
    $reason = $request->filled('reason_code')
        ? \App\Enums\ModerationReason::from((string) $request->string('reason_code'))
        : null;
    $updated = $this->staff->suspend($staff, $request->user(), $reason, $request->string('detail')->value() ?: null);

    return new StaffResource($updated);
}
```
(Apply the same pattern to `deactivate`. Leave `reinstate` signature-compatible — pass `$request->user()` only, optional args default to null.)

- [ ] **Step 5: Run the new test + the full existing Staff suite (no regressions)**

```bash
vendor\bin\pest tests/Feature/Admin/Moderation/StaffModerationAuditTest.php
vendor\bin\pest --filter=Staff
```
Expected: new test PASS; all existing Staff tests still PASS (error codes unchanged — guards still throw `StaffDomainException`).

- [ ] **Step 6: Pint + commit**

```bash
vendor\bin\pint app/Services/Staff app/Http/Controllers/Api/Admin/Staff tests/Feature/Admin/Moderation
git add app/Services/Staff/StaffService.php app/Http/Controllers/Api/Admin/Staff/StaffController.php tests/Feature/Admin/Moderation/StaffModerationAuditTest.php
git commit -m "feat(moderation): route StaffService suspend/reinstate/deactivate through audited apply()"
```

---

## Task 10: `scripts/moderation-e2e.php` smoke

Mirror the rollback-wrapped style of `scripts/staff-e2e.php` (single outer `DB::transaction` + `DB::rollBack()` in `finally`). Moderation has no `$afterCommit` events, so a rollback harness is fine here.

**Files:**
- Create: `scripts/moderation-e2e.php`

- [ ] **Step 1: Write the script**

```php
<?php

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\DriverActivityStatus;
use App\Enums\DriverStatus;
use App\Enums\ModerationReason;
use App\Enums\VehicleType;
use App\Models\AccountModerationAction;
use App\Models\DriverAccount;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Moderation\AccountModerationService;
use App\Services\Staff\StaffService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

$assert = static function (bool $cond, string $msg): void {
    if (! $cond) {
        throw new RuntimeException('FAIL '.$msg);
    }
    echo "PASS {$msg}\n";
};

$phone = static fn (int $n): string => '+21894'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);

$makeUser = static function (string $first, string $ph, ?string $role) use (&$i): User {
    $u = User::create([
        'first_name' => $first, 'last_name' => 'ModSmoke', 'phone_number' => $ph,
        'password' => Hash::make('password'), 'account_status' => AccountStatus::Active->value,
        'phone_verified_at' => now(),
    ]);
    if ($role !== null) {
        $u->assignRole($role);
    }

    return $u;
};

DB::beginTransaction();
try {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('driver', 'web');
    Role::findOrCreate('office_staff', 'web');

    $svc = app(AccountModerationService::class);
    $admin = $makeUser('Admin', $phone(random_int(1, 99999)), 'admin');
    $admin2 = $makeUser('Admin2', $phone(random_int(1, 99999)), 'admin'); // keep "last admin" guard happy

    echo "Scenario 1: suspend a customer blocks login\n";
    $cust = $makeUser('Cust', $phone(random_int(1, 99999)), 'user');
    $svc->suspend($cust, $admin, ModerationReason::Abuse, 'spamming');
    $assert($cust->fresh()->account_status === AccountStatus::Suspended, 'customer suspended');
    $assert(! $cust->fresh()->account_status->canLogin(), 'suspended customer cannot login');

    echo "Scenario 2: ban an online driver forces offline (cascade), DriverStatus untouched\n";
    $driver = $makeUser('Drv', $phone(random_int(1, 99999)), 'driver');
    DriverProfile::create([
        'user_id' => $driver->id, 'office_id' => null,
        'status' => DriverStatus::Active->value,
        'vehicle_type' => VehicleType::Car->value,
        'vehicle_plate' => 'MS-'.Str::upper(Str::random(4)),
        'activity_status' => DriverActivityStatus::Online->value,
    ]);
    $svc->ban($driver, $admin, ModerationReason::Fraud, 'fake orders');
    $p = DriverProfile::where('user_id', $driver->id)->first();
    $assert($driver->fresh()->account_status === AccountStatus::Banned, 'driver banned');
    $assert($p->activity_status === DriverActivityStatus::Offline, 'driver forced offline');
    $assert($p->status === DriverStatus::Active, 'DriverStatus untouched');

    echo "Scenario 3: reinstate debtor → suspended_unpaid_fees\n";
    $debtor = $makeUser('Debt', $phone(random_int(1, 99999)), 'driver');
    DriverAccount::create([
        'driver_id' => $debtor->id, 'cash_to_deposit' => '0.00',
        'earnings_balance' => '0.00', 'debt_balance' => '25.00', 'max_cash_liability' => '200.00',
    ]);
    $svc->suspend($debtor, $admin, ModerationReason::NonPayment, 'owes money');
    $svc->reinstate($debtor, $admin, ModerationReason::Other, 'partial appeal');
    $assert($debtor->fresh()->account_status === AccountStatus::SuspendedUnpaidFees, 'debtor reinstated to suspended_unpaid_fees');

    echo "Scenario 4: reinstate clean user → active\n";
    $clean = $makeUser('Clean', $phone(random_int(1, 99999)), 'user');
    $svc->suspend($clean, $admin, ModerationReason::Other, 'x');
    $svc->reinstate($clean, $admin, ModerationReason::Other, 'cleared');
    $assert($clean->fresh()->account_status === AccountStatus::Active, 'clean user reinstated to active');

    echo "Scenario 5: audit rows recorded with correct snapshots\n";
    $row = AccountModerationAction::where('user_id', $cust->id)->latest('id')->first();
    $assert($row !== null && $row->to_status === AccountStatus::Suspended, 'audit row written for suspend');
    $assert($row->from_status === AccountStatus::Active, 'audit from_status snapshot correct');

    echo "Scenario 6: staff suspend via StaffService is audited\n";
    $staff = $makeUser('Stf', $phone(random_int(1, 99999)), 'office_staff');
    app(StaffService::class)->suspend($staff, $admin);
    $staffRow = AccountModerationAction::where('user_id', $staff->id)->latest('id')->first();
    $assert($staffRow !== null && $staffRow->action->value === 'suspend', 'staff suspension audited');

    echo "ALL MODERATION SMOKE SCENARIOS PASSED\n";
} finally {
    DB::rollBack();
}
```

- [ ] **Step 2: Run the smoke**

```bash
php artisan tinker --execute="require base_path('scripts/moderation-e2e.php');"
```
Expected: all PASS + `ALL MODERATION SMOKE SCENARIOS PASSED`.

- [ ] **Step 3: Pint + commit**

```bash
vendor\bin\pint scripts/moderation-e2e.php
git add scripts/moderation-e2e.php
git commit -m "test(moderation): moderation-e2e smoke script"
```

---

## Task 11: Full verification + docs

**Files:**
- Modify: `docs/SYSTEM_SPECIFICATION.md` (§17.15), `docs/CLAUDE.md` (Current Project State + subsection)

- [ ] **Step 1: Full Pest suite (no regressions)**

```bash
vendor\bin\pest
```
Expected: all green (existing 133 + new moderation tests).

- [ ] **Step 2: Regression smokes**

```bash
php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"
php artisan tinker --execute="require base_path('scripts/staff-e2e.php');"
php artisan tinker --execute="require base_path('scripts/moderation-e2e.php');"
```
Expected: orders-e2e 32/32, staff-e2e all, moderation-e2e all.

- [ ] **Step 3: Pint clean (whole project)**

```bash
vendor\bin\pint
```

- [ ] **Step 4: Update docs**

Add `### 17.15 Account Moderation milestone (2026-06-03) ✅` to `docs/SYSTEM_SPECIFICATION.md` (before "End of Specification Document"): summarize the AccountStatus moderation axis, AccountModerationService as sole authority, StaffService delegation, audit table, reinstate-with-debt → suspended_unpaid_fees (first writer beyond the dead state), driver cascade, lookup endpoint, endpoints shipped, and test/smoke results.

Update `docs/CLAUDE.md` "Current Project State" status line (add Account Moderation ✅), add a concise milestone subsection, and remove Account moderation from "Next Steps" (Test infrastructure becomes #1).

- [ ] **Step 5: Commit docs**

```bash
git add docs/SYSTEM_SPECIFICATION.md docs/CLAUDE.md
git commit -m "docs(moderation): record Account Moderation milestone (§17.15 + Current Project State)"
```

- [ ] **Step 6: Security review (Claude)**

Run the `security-review` skill on the branch diff. Verify: admin-only double-gating (route `role:admin` + `ModerationPolicy` + FormRequest `authorize`), self/last-active-admin guards, no internal-id leaks in any resource (public_id only; actor nested), token revocation on suspend/ban, append-only audit (no update/delete paths), lookup is admin-only and returns thin payload.

---

## Self-Review (completed during planning)

- **Spec coverage:** §2 axis → Tasks 2,5; §3 service/guards/cascade → Task 5; §4 cascade → Task 5; §5 transitions → Task 5 (`assertTransitionAllowed`); §6 audit table → Task 1; §7 enums/errors/exception → Tasks 2,3; §8 endpoints+policy+throttle+lookup → Tasks 6,7,8; §9 StaffService delegation → Task 9; §10 hasOutstandingFees → Task 4; §11 testing → every task + Task 10; §12 file map → all; §13 done criteria → Task 11. ✅
- **Placeholders:** none — all code is complete. Two inline implementation notes (last-admin test wiring; newest-first load) are guidance on top of working code, not gaps.
- **Type consistency:** `apply(User,User,ModerationAction,AccountStatus,ModerationReason,string)` is used identically by the public methods and `StaffService`. `reason()`/`detail()` accessors on `ModerationActionRequest` match controller usage. Enum case names (`ModerationReason::Other`, `ModerationAction::Suspend`, `ModerationErrorCode::CannotModerateSelf`) consistent across service, tests, and controllers.
