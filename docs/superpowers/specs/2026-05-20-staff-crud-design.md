# Staff CRUD Milestone — Design Spec

**Date:** 2026-05-20
**Status:** Approved (awaiting Codex review of this written spec)
**Owners:** Claude (Slice A — core CRUD + auth), Codex (Slice B — office assignments + e2e smoke)
**Reviewers:** Claude reviews Codex's PR; Codex reviews Claude's PR. Style + security gate-keeping shared.
**Worktrees:** First milestone using the new parallel-worktree workflow — read §10 carefully before starting.

---

## 0. Context

The platform has five Spatie roles seeded (`user`, `driver`, `merchant`, `office_staff`, `admin`). Existing endpoints let admins manage drivers and let office staff do their operational work (settlements, returns, etc.). **What's missing: admins have no way to create or manage other internal accounts.** Today, admins and office_staff users exist only because someone seeded them or inserted them in the DB by hand.

This milestone closes that gap by adding a unified `/api/admin/staff/*` namespace for managing both admins and office_staff users — creation, suspension, reinstatement, deactivation, password reset, and office assignment management.

Building on the precedent set by the driver-onboarding milestone (face-to-face trust model, no self-registration), this milestone uses an admin-mediated workflow: admin creates the account, system generates a random temporary password, admin delivers the password out-of-band, employee logs in and is forced to change it on first use.

---

## 1. Locked decisions (do not re-litigate)

| # | Decision | Why |
|---|---|---|
| 1 | **Scope:** admins + office_staff only. Drivers and merchants out of scope. | Drivers have their own onboarding flow. Merchants are deferred to the merchant-deliveries milestone. |
| 2 | **Authority model:** flat — only `admin` role can use any staff-CRUD endpoint. No "office manager managing their office's staff" tier. | Avoids permission complexity early. `is_manager` column on `office_staff_assignments` is kept writable but no behavior gated on it; reserved for a future tiered-authority milestone. |
| 3 | **First-login flow:** system generates a 10-char random alphanumeric password, returned **once** in the create response. Admin delivers it out-of-band (face-to-face / DM / etc.). System sets `users.must_change_password = true`. Employee logs in, all endpoints blocked except change-from-temp + logout, employee changes password → flag cleared, all tokens revoked, new token issued. **No SMS dependency.** | Matches existing face-to-face trust model. Removes external-provider risk. Admin never picks the password (avoids weak passwords). |
| 4 | **Office assignment:** strict — every new office_staff requires ≥1 office at creation. Multi-office supported per spec §2.4 ("Assigned to one or more offices"). `is_manager` is a writable boolean per pivot row with no behavior change today. | An office_staff with zero office assignments can't do anything; preventing them at create-time avoids dead accounts. |
| 5 | **Lifecycle:** soft-deactivate only. Never hard-delete a staff account. Suspend / reinstate / remove-from-office / deactivate (= suspend + remove all office assignments). | Preserves FK relationships to past `settlements.processed_by_staff_id`, `order_status_logs.actor_id`, etc. Mirrors the existing driver-suspension pattern. |
| 6 | **Endpoint layout:** unified `/api/admin/staff/*` namespace. One controller pair, one policy, one resource. Role is a body field on create. | Less duplication than splitting into `/api/admin/admins` + `/api/admin/office-staff`. Lets admins list all internal accounts in one query. |
| 7 | **Super admin tier:** deferred to its own milestone. | Current design's `reset-temp-password` is a force-reset that works on any staff in any state — handles all known recovery scenarios without needing a super-admin tier. |
| 8 | **Self-modification:** an admin cannot suspend, deactivate, or reset their own password. Last admin protected against deactivation/suspension. | Prevents accidental self-lockout. System always has ≥1 active admin. |
| 9 | **Suspension actually prevents login.** `AccountStatus::canLogin()` returns `false` for `Suspended` and `SuspendedUnpaidFees` (previously only `Banned` was blocked). `LoginService::attempt()` checks `canLogin()` before issuing a token. Suspended users get `AuthErrorCode::AccountNotLoginable` (new). Revoking tokens alone is insufficient — a suspended user could re-login. | Discovered in Codex's pre-implementation review. The Real-time milestone exposed that suspension wasn't actually enforceable; Slice A fixes both the enum and the service. |
| 10 | **Slice A rejects `role=office_staff` via FormRequest validation.** During the Slice A → Slice B merge window, `CreateStaffRequest` only allows `role=admin`. Slice B amends the rule to widen it. This avoids exposing a half-built endpoint that throws an internal exception. | The API never lies — if the feature isn't fully there yet, validation rejects with a clean 422 + standard error format. |

---

## 2. Architecture

```
                    ┌────────────────────────────────────────┐
   Admin client ──▶ │  /api/admin/staff/*  (sanctum + admin) │
                    └────────┬───────────────────────────────┘
                             │
                  ┌──────────▼──────────┐    ┌──────────────────────┐
                  │  StaffController    │───▶│ StaffService         │
                  │  (HTTP only —       │    │ (create/update/      │
                  │   validate + call)  │    │  suspend/reinstate/  │
                  └──────────┬──────────┘    │  deactivate/         │
                             │               │  resetTempPassword)  │
                             │               └──────────────────────┘
                  ┌──────────▼─────────────────┐
                  │ OfficeAssignmentController │───▶ OfficeAssignmentService
                  │ (attach / remove           │    (writes office_staff_assignments,
                  │  per-office)               │     enforces ≥1 active assignment)
                  └────────────────────────────┘

                             │
              ┌──────────────▼──────────────────────────────────┐
              │  Cross-cutting: EnsurePasswordChanged middleware │
              │  applies to ALL authenticated routes — blocks    │
              │  users with must_change_password=true except     │
              │  for /api/me/password/change-from-temp + logout  │
              └──────────────────────────────────────────────────┘
```

**Architectural rules:**

- **Two services** (`StaffService`, `OfficeAssignmentService`) — one service per concern. `StaffService` owns user-level operations; `OfficeAssignmentService` owns the pivot. Either can be tested in isolation.
- **Cross-cutting middleware** for password-change enforcement. Not a per-endpoint check; applied globally to the sanctum group via a named alias. Allowlisted routes bypass it explicitly.
- **All multi-step writes inside `DB::transaction()`**. Creating a staff member writes: `users` insert + Spatie `model_has_roles` insert + `office_staff_assignments` inserts (if office_staff) + (later, in change-from-temp) tokens delete + new token create. All-or-nothing per Critical Rule 19.
- **No new tables.** Only one new column on `users`. No financial-table migrations (per Critical Rule 20).
- **No facades/`app()`/`resolve()` inside services.** All dependencies via constructor injection.
- **Authorization via `StaffPolicy`** — not inline `$user->can(...)`. Mounted on the route group.

---

## 3. Schema delta

### Migration: `add_must_change_password_to_users`

```php
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
```

- Existing users default to `false` — no behavior change for them.
- New staff get `true` at creation.
- Cleared to `false` when the user changes from temp password.

### Model deltas

`app/Models/User.php`:
- Add `'must_change_password'` to `$fillable`.
- Add `'must_change_password' => 'boolean'` to `casts()`.

### Schema changes from Slice B (Codex)

Slice B's migration on `office_staff_assignments` does TWO things in one file:

1. **Add `public_id` ULID column** (was missing — Codex verified).
2. **Drop the existing `unique(['user_id', 'office_id'])` and replace it with a partial unique index:**
   ```sql
   DROP INDEX office_staff_assignments_user_id_office_id_unique;
   CREATE UNIQUE INDEX office_staff_assignments_active_unique
       ON office_staff_assignments (user_id, office_id)
       WHERE removed_at IS NULL;
   ```
   Reason: the original unique constraint blocks re-attaching a staff member to an office after detach. Soft-removed rows must not count toward uniqueness. Postgres partial unique indexes are the standard fix.

### No changes elsewhere

- Spatie tables unchanged. We just `assignRole('admin')` / `assignRole('office_staff')` on new users.
- Other tables (users, etc.) only get the `must_change_password` addition above.

---

## 4. Endpoint catalog

All routes under `auth:sanctum + role:admin + staff.password_change_required` middleware unless noted.

### 4.1 Slice A — Core staff CRUD (`StaffController`, Claude)

| Method | Path | Body / Params | Returns | Errors |
|---|---|---|---|---|
| `POST` | `/api/admin/staff` | `phone_number`, `first_name`, `last_name`, `email?`, `role` (`admin\|office_staff`), `office_assignments[]?` (required if role=office_staff): `[{ office_id, is_manager }]` | `{ staff: StaffResource, temporary_password: string }` | 422 if validation, 409 if phone already exists |
| `GET` | `/api/admin/staff` | Query: `role?`, `account_status?`, `office_id?`, `page?`, `per_page?` | `StaffResource::collection(paginated)` | — |
| `GET` | `/api/admin/staff/{public_id}` | — | `StaffResource` | 404 |
| `PATCH` | `/api/admin/staff/{public_id}` | `first_name?`, `last_name?`, `email?` (phone + role are immutable) | `StaffResource` | 422, 404 |
| `POST` | `/api/admin/staff/{public_id}/suspend` | — | `StaffResource` | 422 `CANNOT_SELF_MODIFY`, 422 `LAST_ADMIN_PROTECTED`, 404 |
| `POST` | `/api/admin/staff/{public_id}/reinstate` | — | `StaffResource` | 404 |
| `POST` | `/api/admin/staff/{public_id}/deactivate` | — | `StaffResource` | 422 `CANNOT_SELF_MODIFY`, 422 `LAST_ADMIN_PROTECTED`, 404 |
| `POST` | `/api/admin/staff/{public_id}/reset-temp-password` | — | `{ staff: StaffResource, temporary_password: string }` | 422 `CANNOT_SELF_MODIFY`, 404 |

### 4.2 Slice B — Office assignment management (`OfficeAssignmentController`, Codex)

| Method | Path | Body / Params | Returns | Errors |
|---|---|---|---|---|
| `POST` | `/api/admin/staff/{public_id}/office-assignments` | `office_id`, `is_manager` (default `false`) | `OfficeAssignmentResource` | 422 `ROLE_MISMATCH_FOR_OFFICE_ASSIGN`, 409 `OFFICE_ASSIGNMENT_DUPLICATE`, 404 |
| `DELETE` | `/api/admin/staff/{public_id}/office-assignments/{assignment_public_id}` | — | 204 | 422 `OFFICE_ASSIGNMENT_LAST_REQUIRED`, 404 |

### 4.3 Cross-cutting (Slice A)

| Method | Path | Auth | Body | Returns |
|---|---|---|---|---|
| `POST` | `/api/me/password/change-from-temp` | `auth:sanctum` only (intentionally bypasses `staff.password_change_required`) | `current_password`, `new_password`, `new_password_confirmation` | `{ user: UserResource, token: string }` |

### 4.4 Route naming

All routes have explicit names so the middleware allowlist can reference them by name:

- `admin.staff.store`, `admin.staff.index`, `admin.staff.show`, `admin.staff.update`
- `admin.staff.suspend`, `admin.staff.reinstate`, `admin.staff.deactivate`, `admin.staff.reset-temp-password`
- `admin.staff.office-assignments.store`, `admin.staff.office-assignments.destroy`
- `me.password.change-from-temp`
- `auth.logout` (existing, just need to confirm the name)

### 4.5 Rate limiting

- `POST /api/me/password/change-from-temp`: throttle `password_change_temp` — 5 attempts per 15 min per user. Defined in `AppServiceProvider::configureRateLimiters()`.
- All `/api/admin/staff/*` routes: existing `auth:sanctum` rate limiting suffices.

---

## 5. Service-layer contracts

### 5.1 `App\Services\Staff\TempPasswordGenerator` (Slice A)

```php
final class TempPasswordGenerator
{
    /**
     * 10-char alphanumeric. Uses random_bytes for crypto-grade entropy.
     * Two consecutive calls must produce different values.
     */
    public function generate(): string;
}
```

### 5.2 `App\Services\Staff\StaffService` (Slice A)

```php
final class StaffService
{
    public function __construct(
        private readonly TempPasswordGenerator $passwords,
        private readonly OfficeAssignmentService $officeAssignments,
    ) {}

    /**
     * @return array{user: User, temporary_password: string}
     *   The temporary_password is returned ONLY by this method and resetTempPassword().
     *   Caller is responsible for surfacing it to the admin (in the HTTP response)
     *   and NOT logging it anywhere.
     */
    public function create(CreateStaffInput $input, User $actor): array;

    public function update(User $staff, UpdateStaffInput $input): User;

    public function suspend(User $staff, User $actor): User;
    public function reinstate(User $staff, User $actor): User;
    public function deactivate(User $staff, User $actor): User;

    /** @return array{user: User, temporary_password: string} */
    public function resetTempPassword(User $staff, User $actor): array;
}
```

**Invariants (must be enforced inside the service, not in the controller):**

- `create()`: if `role=office_staff`, `office_assignments[]` non-empty → calls `OfficeAssignmentService::attachMany()` inside the same transaction
- `suspend()` / `deactivate()` / `resetTempPassword()`: throw `StaffDomainException(CANNOT_SELF_MODIFY)` if `$staff->id === $actor->id`
- `suspend()` / `deactivate()`: throw `StaffDomainException(LAST_ADMIN_PROTECTED)` if this would leave zero active admins. Active = `account_status=Active AND has admin role`.
- `suspend()`: revoke all tokens via `$staff->tokens()->delete()`
- `deactivate()`: revoke all tokens + soft-remove all `office_staff_assignments` (set `removed_at = now()`)
- `resetTempPassword()`: works on any staff regardless of current `must_change_password` state. Regenerates password, hashes it, sets flag back to `true`, revokes all tokens, returns the new plaintext password to caller. Audit: writes a row to `order_status_logs`-equivalent if such a table exists for staff actions — **for this milestone, no staff-action audit table** (deferred to Account Moderation milestone).

### 5.3 `App\Services\Staff\OfficeAssignmentService` (Slice B — Codex)

```php
final class OfficeAssignmentService
{
    public function attach(User $staff, int $officeId, bool $isManager): OfficeStaffAssignment;
    public function detach(User $staff, OfficeStaffAssignment $assignment): void;

    /** @param  array<int, array{office_id:int, is_manager:bool}>  $assignments */
    public function attachMany(User $staff, array $assignments): Collection;
}
```

**Invariants:**

- `attach()`: throw `StaffDomainException(ROLE_MISMATCH_FOR_OFFICE_ASSIGN)` if `$staff` does not have the `office_staff` Spatie role
- `attach()`: throw `StaffDomainException(OFFICE_ASSIGNMENT_DUPLICATE)` if an active assignment for `(user_id, office_id)` already exists (i.e., a row where `removed_at IS NULL`)
- `detach()`: throw `StaffDomainException(OFFICE_ASSIGNMENT_LAST_REQUIRED)` if this would leave the user with zero active assignments. Admin must call `/deactivate` instead.
- `attachMany()`: called by `StaffService::create()` inside the parent transaction. Does NOT open its own transaction. Skips role check on the assumption that the parent service has just assigned the `office_staff` role earlier in the same transaction (parent's responsibility).
- All methods set `assigned_at = now()` on insert; soft-removal sets `removed_at = now()` on update.

### 5.4 `App\Services\Staff\TempPasswordChangeService` (Slice A)

```php
final class TempPasswordChangeService
{
    /**
     * Verifies current temp password, sets new password, clears flag, revokes
     * ALL tokens (including the calling one), issues a new token.
     *
     * @return array{user: User, token: string}
     */
    public function change(User $user, string $currentPassword, string $newPassword): array;
}
```

**Logic:**

1. `Hash::check($currentPassword, $user->password)` — verify temp password. Throw `StaffDomainException(TEMP_PASSWORD_MISMATCH)` (401) on failure.
2. Refuse if `$newPassword === $currentPassword` → throw `StaffDomainException(NEW_PASSWORD_SAME_AS_TEMP)` (422)
3. `DB::transaction(fn () => {`
4.   `$user->password = Hash::make($newPassword);`
5.   `$user->must_change_password = false;`
6.   `$user->save();`
7.   `$user->tokens()->delete();`
8.   `$token = $user->createToken('post-temp-change')->plainTextToken;`
9. `});`
10. Return `['user' => $user->fresh(), 'token' => $token]`

### 5.5 `App\Exceptions\Staff\StaffDomainException` (Slice A, owns the file; Codex contributes enum cases)

Mirrors `OrderDomainException`. Carries a `StaffErrorCode` enum, maps to HTTP via `httpStatus()`. Rendered to JSON by the global handler in `bootstrap/app.php`.

**Enum cases** (in `App\Enums\StaffErrorCode`):

| Case | HTTP | Owner |
|---|---|---|
| `CANNOT_SELF_MODIFY` | 422 | Slice A |
| `LAST_ADMIN_PROTECTED` | 422 | Slice A |
| `TEMP_PASSWORD_MISMATCH` | 401 | Slice A |
| `NEW_PASSWORD_SAME_AS_TEMP` | 422 | Slice A |
| `ROLE_MISMATCH_FOR_OFFICE_ASSIGN` | 422 | Slice B contributes the case (file owned by Slice A) |
| `OFFICE_ASSIGNMENT_DUPLICATE` | 409 | Slice B contributes |
| `OFFICE_ASSIGNMENT_LAST_REQUIRED` | 422 | Slice B contributes |

**Coordination protocol for the enum:** Claude creates the file with the Slice A cases. Codex's branch starts AFTER Slice A's enum file exists (see merge order below). Codex's PR adds the three Slice B cases via a normal edit to the enum file. If Claude renames or reorganizes the enum after Codex starts, Claude must notify Codex before the rename lands.

---

## 6. The forced-password-change middleware

### 6.1 Class: `App\Http\Middleware\EnsurePasswordChanged` (Slice A)

```php
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

### 6.2 Registration

In `bootstrap/app.php`:

```php
$middleware->alias([
    // ... existing aliases
    'staff.password_change_required' => EnsurePasswordChanged::class,
]);
```

In `routes/api.php` — apply to the sanctum-protected groups (every group that uses `auth:sanctum`):

```php
Route::middleware(['auth:sanctum', 'staff.password_change_required'])
    ->group(function (): void {
        // existing authenticated routes (must move under this group, or
        // each existing group adds the new middleware alongside auth:sanctum)
    });
```

**Implementation note:** the existing route file has multiple sanctum groups (admin, office, driver, me). Each of those groups gains `staff.password_change_required` in its middleware list. The `change-from-temp` route and `auth.logout` route get only `auth:sanctum` (no new middleware) so they remain accessible to users with the flag.

### 6.3 Token revocation semantics

Every operation that should "kick the user out" calls `$user->tokens()->delete()`:
- Suspend
- Deactivate
- Reset temp password
- Successful password change from temp

Sanctum's `tokens()` is a hasMany on `PersonalAccessToken`. Deleting them invalidates all existing bearer tokens immediately — next API call from that user gets 401.

---

## 7. Resources

### 7.1 `StaffResource` (Slice A)

```php
final class StaffResource extends JsonResource
{
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
            'role' => $u->getRoleNames()->first(),    // 'admin' or 'office_staff'
            'account_status' => $u->account_status->value,
            'must_change_password' => $u->must_change_password,
            'phone_verified_at' => $u->phone_verified_at?->toIso8601String(),
            'email_verified_at' => $u->email_verified_at?->toIso8601String(),
            'office_assignments' => $this->whenLoaded(
                'activeOfficeAssignments',
                fn () => OfficeAssignmentResource::collection($u->activeOfficeAssignments),
            ),
            'created_at' => $u->created_at?->toIso8601String(),
            'updated_at' => $u->updated_at?->toIso8601String(),
        ];
    }
}
```

**Caller contract:** for show/list responses, the caller must `->with('roles', 'activeOfficeAssignments.office')` to avoid N+1.

### 7.2 `OfficeAssignmentResource` (Slice B)

```php
final class OfficeAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var OfficeStaffAssignment $a */
        $a = $this->resource;

        return [
            'id' => $a->public_id ?? (string) $a->id, // pivot may need a public_id column added
            'office' => [
                'id' => $a->office->public_id,
                'name' => $a->office->name,
            ],
            'is_manager' => $a->is_manager,
            'assigned_at' => $a->assigned_at?->toIso8601String(),
            'removed_at' => $a->removed_at?->toIso8601String(),
        ];
    }
}
```

**Open question for Codex during implementation:** does `office_staff_assignments` have a `public_id` column today? If NOT, Slice B includes a small migration to add one (ULID, unique). If checking shows the column already exists, skip that migration. Either way, the `DELETE` endpoint route binding uses `{assignment_public_id}` (a string ULID), never the internal id.

### 7.3 Active-assignments relation on `User`

Add to `App\Models\User`:

```php
public function activeOfficeAssignments(): HasMany
{
    return $this->hasMany(OfficeStaffAssignment::class, 'user_id')
        ->whereNull('removed_at');
}
```

This is a **read-only relation** for the resource. The `OfficeAssignmentService` writes via direct pivot manipulation, not through this relation.

---

## 8. Authorization

### 8.1 `App\Policies\StaffPolicy` (Slice A)

```php
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

Self-modify and last-admin protections are enforced in the **service layer**, not the policy. Reason: the policy's purpose is role-based access control ("is this user allowed in the door?"). Self-modify and last-admin are business invariants that must throw with specific error codes the client can react to — domain exceptions, not 403s.

### 8.2 Policy registration

In `bootstrap/app.php` (or `AuthServiceProvider` if it gets created):

```php
Gate::policy(User::class, StaffPolicy::class);
```

Then controllers call `$this->authorize('suspend', $staff)` etc.

---

## 9. Testing strategy

### 9.1 Slice A tests (Claude writes)

**Unit:**
- `tests/Unit/Services/Staff/TempPasswordGeneratorTest.php` — length, alphanumeric, uniqueness over 100 generations
- `tests/Unit/Services/Staff/StaffServiceTest.php` — every method, happy path + exception paths (CANNOT_SELF_MODIFY, LAST_ADMIN_PROTECTED). Mocks `OfficeAssignmentService` and `TempPasswordGenerator`.
- `tests/Unit/Services/Staff/TempPasswordChangeServiceTest.php` — wrong current password, same-as-current rejection, success path, tokens revoked
- `tests/Unit/Middleware/EnsurePasswordChangedMiddlewareTest.php` — bypass when flag false, block when true, allow listed routes, ignore unauthenticated
- `tests/Unit/Policies/StaffPolicyTest.php` — every method returns true only for admin role

**Feature:**
- `tests/Feature/Staff/StaffControllerTest.php` — full CRUD lifecycle including the change-from-temp flow end-to-end
- `tests/Feature/Staff/StaffSuspensionTest.php` — suspend/reinstate, self-modify rejection, last-admin protection
- `tests/Feature/Staff/StaffDeactivationTest.php` — deactivate, side-effects on assignments, reinstate doesn't auto-restore assignments
- `tests/Feature/Staff/ResetTempPasswordTest.php` — admin resets another admin's password, works regardless of current state, tokens revoked

### 9.2 Slice B tests (Codex writes)

**Unit:**
- `tests/Unit/Services/Staff/OfficeAssignmentServiceTest.php` — attach/detach/attachMany happy paths, ROLE_MISMATCH refusal, DUPLICATE refusal, LAST_REQUIRED refusal

**Feature:**
- `tests/Feature/Staff/OfficeAssignmentControllerTest.php` — attach office (admin role rejected, duplicate rejected, happy path), detach (last-required rejected, happy path)

### 9.3 e2e smoke (Codex writes — Slice B)

`scripts/staff-e2e.php`, 6 rollback-wrapped scenarios:

1. **Admin creates admin** → temp password works once → forced change → original admin operates normally
2. **Admin creates office_staff with 2 offices** → office_staff can hit `/office/orders` for both offices
3. **Admin resets another admin's password** → original admin's tokens revoked → new temp works
4. **Admin suspends office_staff** → their tokens revoked → `/office/orders` returns 401
5. **Admin deactivates office_staff** → all office assignments removed → reinstate doesn't restore assignments
6. **Admin attempts self-suspend → 422** + **Admin attempts to suspend last admin → 422**

Pattern: `DB::beginTransaction()` at top, throw on failure, `DB::rollBack()` in finally. Like `orders-e2e.php`.

### 9.4 Regression gates

- All 44 existing Pest tests must still pass on both branches.
- `scripts/orders-e2e.php` 32 scenarios must still pass.
- Pint clean on every commit.

---

## 10. Work division: Claude × Codex (the parallel-worktree workflow)

> **READ THIS BEFORE STARTING CODING.** This is the first milestone using the new worktree workflow. Get this right and the next ten milestones are fast; get it wrong and we have merge conflicts every milestone.

### 10.1 Worktree layout

```
C:\Users\User\Desktop\
├── delivary-app\               ← main worktree, branch `main`. User's default.
├── delivary-app-claude\        ← Claude's worktree, branch `claude/staff-crud-core`
└── delivary-app-codex\         ← Codex's worktree, branch `codex/staff-crud-office-assignments`
```

All three share the same `.git/`. Per-worktree git identity:
- Main worktree: user's normal identity (commits go on PRs as the user/merger)
- Claude's worktree: `Claude <claude@delivary.local>`
- Codex's worktree: `Codex <codex@delivary.local>`

Verified setup: `git config extensions.worktreeConfig true` is enabled, and `git config --worktree user.name "Claude|Codex"` was set in each worktree. Commits show real author. PR pages on GitHub show the author chip per commit.

### 10.2 Branch creation

From within each worktree, on its first task of a milestone:

```bash
# In delivary-app-claude/
git fetch origin
git checkout main
git pull
git checkout -b claude/staff-crud-core
```

```bash
# In delivary-app-codex/
git fetch origin
git checkout main
git pull
git checkout -b codex/staff-crud-office-assignments
```

### 10.3 Slice ownership — files

**Slice A — Claude's files (do NOT have Codex touch these):**

- `database/migrations/*_add_must_change_password_to_users.php`
- `app/Models/User.php` (the `must_change_password` fillable/cast addition + the `activeOfficeAssignments` relation)
- `app/Enums/StaffErrorCode.php` (Claude creates with Slice A cases; Codex adds Slice B cases in a follow-up commit on Codex's branch — see §10.7 coordination)
- `app/Exceptions/Staff/StaffDomainException.php`
- `app/Services/Staff/TempPasswordGenerator.php`
- `app/Services/Staff/StaffService.php`
- `app/Services/Staff/TempPasswordChangeService.php`
- `app/Http/Middleware/EnsurePasswordChanged.php`
- `app/Http/Controllers/Api/Admin/Staff/StaffController.php`
- `app/Http/Controllers/Api/Me/ChangeFromTempPasswordController.php`
- `app/Http/Requests/Staff/CreateStaffRequest.php`, `UpdateStaffRequest.php`, `ChangeFromTempPasswordRequest.php`
- `app/Http/Resources/Staff/StaffResource.php`
- `app/Policies/StaffPolicy.php`
- `bootstrap/app.php` (middleware alias registration + policy registration)
- `routes/api.php` (the staff route group + change-from-temp route + applying middleware to other sanctum groups)
- All Slice A tests listed in §9.1

**Slice B — Codex's files (do NOT have Claude touch these):**

- `app/Services/Staff/OfficeAssignmentService.php`
- `app/Http/Controllers/Api/Admin/Staff/OfficeAssignmentController.php`
- `app/Http/Requests/Staff/AttachOfficeAssignmentRequest.php`
- `app/Http/Resources/Staff/OfficeAssignmentResource.php`
- `database/migrations/*_add_public_id_to_office_staff_assignments.php` IF the column doesn't exist (Codex checks first)
- All Slice B tests in §9.2
- `scripts/staff-e2e.php`

### 10.4 Files both slices touch (coordinated)

| File | Owner | What the other slice contributes |
|---|---|---|
| `app/Enums/StaffErrorCode.php` | Claude creates with Slice A cases. | Codex adds the three Slice B cases (`ROLE_MISMATCH_FOR_OFFICE_ASSIGN`, `OFFICE_ASSIGNMENT_DUPLICATE`, `OFFICE_ASSIGNMENT_LAST_REQUIRED`) AFTER Slice A merges. |
| `routes/api.php` | Claude adds the main staff group. | Codex adds the two office-assignment routes inside Claude's group AFTER Slice A merges. |
| `app/Models/OfficeStaffAssignment.php` | Codex may add a `public_id` column + booted hook (if the column doesn't exist). Claude does not touch. | n/a |

### 10.5 Merge order — IMPORTANT

**Slice A merges FIRST.**

Why: Slice A creates the `StaffErrorCode` enum that Slice B's exceptions use. Slice A creates the `routes/api.php` staff group that Slice B's routes nest inside. Slice A creates the `StaffResource` that conditionally embeds `OfficeAssignmentResource` from Slice B (but `whenLoaded` makes that null-safe).

Sequence:

1. **Both worktrees branch from main.** (`claude/staff-crud-core` from main, `codex/staff-crud-office-assignments` from main.)
2. **Claude builds Slice A** in `delivary-app-claude/`. While building, Claude does NOT yet reference `OfficeAssignmentService` or `OfficeAssignmentResource`. Instead:
   - `StaffService::create()` accepts the `office_assignments[]` input but stubs the pivot insertion with a `TODO(slice-B)` comment that just throws a `LogicException` for office_staff creation. This way Slice A's tests for `admin` creation pass; office_staff creation tests are deferred to Slice B integration.
   - `StaffResource::toArray()` references `OfficeAssignmentResource::class` only inside `$this->whenLoaded(...)` which is null-safe at runtime even if the class doesn't exist yet — but to be safe, Claude can use a string class name and resolve it dynamically OR Claude defers this part to a final integration commit.
3. **Claude opens PR claude/staff-crud-core → main.** Codex reviews.
4. **Slice A merges to main.**
5. **Codex rebases `codex/staff-crud-office-assignments` on the updated main.** Resolves any conflicts (should be none if §10.3 was followed).
6. **Codex builds Slice B** in `delivary-app-codex/`. Codex:
   - Adds the three Slice B cases to `StaffErrorCode`
   - Adds the two office-assignment routes to `routes/api.php`
   - Creates `OfficeAssignmentService`, `OfficeAssignmentController`, `OfficeAssignmentResource`
   - Replaces the `TODO(slice-B)` LogicException stub in `StaffService::create()` with the real `attachMany()` call
   - Writes Slice B tests + the e2e smoke
7. **Codex opens PR codex/staff-crud-office-assignments → main.** Claude reviews.
8. **Slice B merges to main.**
9. **Claude in main worktree:** updates `docs/SYSTEM_SPECIFICATION.md` §17.x + `docs/CLAUDE.md` "Current Project State" with the milestone summary. Final smoke run. Final PR if any docs.

### 10.6 What "parallel" actually means here

You will notice the merge order is sequential: Slice A first, then Slice B. So how is this parallel?

**The building phase is parallel.** While Claude builds Slice A in its worktree, Codex can start building Slice B in its worktree at the same time. Codex's Slice B starts on a branch from `main` (the same starting point as Claude's branch), and Codex does the parts of Slice B that don't depend on Slice A — which is most of it:

- `OfficeAssignmentService` — purely operates on `office_staff_assignments` and `User`/`OfficeLocation` models. Doesn't depend on `StaffService` or `StaffErrorCode`. Codex can build this in full.
- `OfficeAssignmentController` — depends on the route group existing AND on `StaffErrorCode` for some error responses. Codex can build the class with stubbed error constants (or use raw `abort(422, 'ROLE_MISMATCH_FOR_OFFICE_ASSIGN')`) and swap to real enum cases at the end.
- `OfficeAssignmentResource` — purely self-contained
- Slice B unit tests for `OfficeAssignmentServiceTest` — all of these can run on Codex's branch because they only need Codex's service + the models + the factories that already exist on main
- The `public_id` migration for `office_staff_assignments` (if needed) — Codex can write this on his branch and it'll merge cleanly

**The integration phase is sequential.** When Slice A merges, Codex rebases, and the parts that depend on Slice A (the enum cases, the route group, the `StaffService::create()` swap) get plugged in. This is typically ~30 minutes of integration work, not the full ~3 hours of Slice B build time.

**Net wall-clock win:** Slice A and Slice B build in parallel (≈2 hours). Codex rebases + integrates (≈30 min). Slice B PR opened. Sequential variant would be: Slice A build (≈2 hours) + Slice B build (≈3 hours) = 5 hours. Parallel variant: max(2, 2) hours building + 30 min integrating + reviews = ≈3 hours total. **~40% faster wall-clock.**

### 10.7 Coordination protocol for shared files

Any file in §10.4 is **dual-touched** and needs an explicit handoff. The protocol:

1. **Slice A defines the public surface first** — Claude commits `StaffErrorCode` with Slice A cases, opens PR. The PR is the contract Slice B reads.
2. **Codex never modifies a Slice A file pre-merge.** If Slice B's tests need a new error code that's not in Slice A's enum yet, Codex either: (a) waits for Slice A to merge then adds the case, or (b) for tests-only situations uses a placeholder string and replaces it post-merge.
3. **Codex's branch never rebases on top of Slice A pre-merge** to avoid muddying the diff. Codex rebases ONLY after Slice A merges to main.
4. **If Slice A needs a change that affects Slice B's contract** (e.g., renaming `StaffErrorCode` cases that Codex was going to add) — Claude posts the change explicitly to the user before landing it.

### 10.8 Code review responsibilities

| Concern | Reviewer | When |
|---|---|---|
| Slice A spec compliance + code quality | Codex | When Claude opens PR |
| Slice B spec compliance + code quality | Claude | When Codex opens PR |
| Style + Pint clean | Both, on each other's PR | Always |
| Security review (last-admin guard, self-modify guard, token revocation paths, temp password handling) | Claude runs `security-review` skill on the merged main after Slice B lands | End of milestone |
| Final docs sign-off (`docs/SYSTEM_SPECIFICATION.md` §17.x + `docs/CLAUDE.md`) | Claude writes; user approves | After Slice B merges |

Style is reviewed by both sides. Either reviewer can block a PR on style violations — `declare(strict_types=1)`, `final` classes, type hints, no inline logic in controllers, FormRequests for validation, Resources not raw models, Pint clean, no abbreviations, no static methods except pure utilities.

### 10.9 Test DB note

Both worktrees currently share the `delivary_app_testing` Postgres DB (set in `phpunit.xml`). If both agents run `pest` at the exact same moment, they can race on `RefreshDatabase`. In practice this hasn't bitten us yet, but if Slice A and Slice B test suites land same-second issues, the fix is to switch each worktree's `phpunit.xml` to a suffix-named DB (`delivary_app_testing_claude` / `_codex`). Defer until needed.

---

## 11. Out of scope (deliberate)

- Super admin role tier (deferred to its own milestone)
- Role promotion/demotion endpoint (role is immutable; admin must deactivate + recreate to change role)
- Phone-number change for staff (immutable; would need re-verification flow)
- Tiered authority (office managers managing office_staff in their own office) — `is_manager` flag stays writable with no behavior gated
- Bulk staff operations
- Admin-action audit log (separate "Account Moderation" milestone)
- Drivers and merchants (separate flows)
- SMS notification when staff account is created (admin delivers password out-of-band)
- Email notification when staff account is created
- Self-service profile editing beyond what `/api/me/profile` already provides
- Welcome flow / onboarding tour for new staff

---

## 12. Done criteria

- [ ] Slice A PR merged to main, reviewed by Codex
- [ ] Slice B PR merged to main, reviewed by Claude
- [ ] All Pest tests green on main
- [ ] `scripts/orders-e2e.php` 32 scenarios still pass (no regression)
- [ ] `scripts/staff-e2e.php` 6 new scenarios pass
- [ ] Pint clean on all touched files
- [ ] Security review pass complete (Claude runs `security-review` skill)
- [ ] `docs/SYSTEM_SPECIFICATION.md` §17.x updated with milestone summary
- [ ] `docs/CLAUDE.md` "Current Project State" table updated with Staff CRUD ✅

---

## 13. Already-resolved corrections (do not relitigate)

These were caught in Codex's pre-implementation review and are baked into the design above:

1. **`auth.logout` route must be named** — current `routes/api.php` registers it without `->name('auth.logout')`. Slice A's Task 16 names it. Without the name, the `EnsurePasswordChanged` middleware allowlist never matches and logout is blocked for users with `must_change_password=true`.

2. **`AccountStatus::canLogin()` must block `Suspended` (not just `Banned`)** and `LoginService::attempt()` must actually call it. Without this fix, suspending a staff via Slice A is meaningless — they re-login and get a new token in seconds. See §1 locked decision 9.

3. **`office_staff_assignments.public_id` is missing.** Slice B's migration adds it.

4. **Existing `unique(user_id, office_id)` blocks soft-removal re-attach.** Slice B's migration drops it and creates a partial unique index `WHERE removed_at IS NULL`. See §3.

5. **Slice A's `LogicException("slice-B")` stub for office_staff creation would crash the API with a 500 between merges.** Slice A's `CreateStaffRequest` instead validates `role` as `Rule::in(['admin'])` only; Slice B amends the rule. The service-level stub is removed because the FormRequest layer rejects office_staff before the service is called. See §1 locked decision 10.

---

## 14. Open items Codex may raise

If Codex hits any of these during implementation, **stop and ask** (do not guess):

1. `office_staff_assignments` table doesn't have a `public_id` column today. Codex's `DELETE` route expects one. Confirm with Claude whether to add via migration in Slice B or change the route to use internal id (NOT RECOMMENDED — violates Critical Rule 11).
2. `User::activeOfficeAssignments()` relation — Claude is adding this in Slice A. If Slice A merges with that relation working, Codex's `OfficeAssignmentResource` collection assertion can rely on it. If Claude forgets, Codex flags before submitting PR.
3. The `whenLoaded('activeOfficeAssignments', ...)` call in `StaffResource` references `OfficeAssignmentResource`. If Slice A merges before `OfficeAssignmentResource` exists, the class name is a string lookup at runtime — but eager-loading isn't possible. Either Claude uses string-based class resolution in Slice A or defers the embed-block to a final-integration commit.
4. If `LAST_ADMIN_PROTECTED` is hit during the e2e smoke (because the smoke makes only one admin and tries to suspend them), Codex's smoke seeds two admins to start.
5. The forced-password-change middleware applies to ALL existing sanctum-protected routes. After Slice A merges, all existing tests that authenticate as a user must NOT inadvertently set `must_change_password=true` on the actor. Slice A's User factory should default `must_change_password=false` (default already false from migration).

---

## 15. Reference: existing milestones

For context on how previous milestones organized themselves, see:
- `docs/superpowers/specs/2026-05-07-auth-design.md` — auth + token + OTP infra (we reuse the SMS abstraction conceptually but don't use SMS in this milestone)
- `docs/superpowers/specs/2026-05-10-driver-onboarding-design.md` — face-to-face trust model, admin approval pattern, no self-registration (this milestone follows the same trust model)
- `docs/superpowers/specs/2026-05-17-settlement-and-seller-payouts-design.md` — three-service pattern, exception-with-error-code pattern, the office-staff endpoint structure we extend
- `docs/superpowers/specs/2026-05-18-realtime-reverb-design.md` — the previous parallel-Claude-Codex milestone; the worktree workflow in §10 here is the production version of what we attempted there
