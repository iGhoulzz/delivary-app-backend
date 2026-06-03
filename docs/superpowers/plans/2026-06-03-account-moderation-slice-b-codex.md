# Account Moderation — Slice B (Codex) Handoff

**Owner:** Codex · **Branch:** `account-moderation/http` · **Worktree:** `delivary-app-codex/`
**Reviewer of this slice:** Claude. **You review:** Claude's Slice A PR (`account-moderation/core`).

Full task code lives in the shared plan: **`docs/superpowers/plans/2026-06-03-account-moderation.md`**. Spec: **`docs/superpowers/specs/2026-06-03-account-moderation-design.md`**. This file only defines scope, workflow, and boundaries — do not duplicate the task code, follow the plan tasks verbatim.

---

## Your scope — Tasks 6–10 (HTTP + integration)

- **Task 6** — `ModerationPolicy` + `moderate` gate (in `AppServiceProvider::boot`)
- **Task 7** — FormRequests (`ModerationActionRequest`, `UserLookupRequest`) + Resources (`UserModerationResource`, `ModerationActionResource`, `UserLookupResource`)
- **Task 8** — `UserModerationController` + `AdminUserLookupController` + routes in `routes/api.php` + `throttle:moderation` limiter (in `AppServiceProvider`)
- **Task 9** — Route `StaffService::suspend/reinstate/deactivate` through `AccountModerationService::apply()`; thread optional `reason_code`/`detail` through `StaffController`
- **Task 10** — `scripts/moderation-e2e.php`

## NOT your scope — Slice A (Claude) owns these; do not edit

`app/Enums/Moderation*.php`, `app/Exceptions/Moderation/ModerationException.php`, `app/Services/Moderation/AccountModerationService.php`, `app/Models/AccountModerationAction.php` (+ migration/factory), `app/Models/User.php` (`hasOutstandingFees`, `moderationActions`), `bootstrap/app.php` (exception render). If you think one of these needs a change, **stop and raise it with Claude** rather than editing.

## The contract you build against (from Slice A — locked in the plan)

You can start immediately against these signatures even before core merges:

```php
// App\Services\Moderation\AccountModerationService
public function suspend(User $target, User $actor, ModerationReason $reason, string $detail): User
public function ban(User $target, User $actor, ModerationReason $reason, string $detail): User
public function reinstate(User $target, User $actor, ModerationReason $reason, string $detail): User
public function apply(User $target, User $actor, ModerationAction $action, AccountStatus $toStatus, ModerationReason $reason, string $detail): User

// App\Enums\ModerationReason: Fraud, Abuse, NonPayment, PolicyViolation, Other
// App\Enums\ModerationAction: Suspend, Ban, Reinstate
// App\Enums\ModerationErrorCode: CannotModerateSelf, LastActiveAdmin, InvalidTransition (all httpStatus 422)
// App\Models\User::hasOutstandingFees(): bool   // used only by StaffService::reinstate
// App\Models\User::moderationActions(): HasMany // used by the history endpoint
```

Endpoint contract (admin-only, `public_id`-bound, prefix `admin/users`, middleware `auth:sanctum,role:admin,staff.password_change_required,throttle:moderation`):
- `GET  admin/users/lookup?phone=` → `UserLookupResource` or `{data:null}`
- `POST admin/users/{user}/suspend|ban|reinstate` (body `reason_code`,`detail`) → `UserModerationResource`
- `GET  admin/users/{user}/moderation-history` → paginated `ModerationActionResource`

Register `lookup` **before** the `{user}/...` wildcard so it isn't shadowed.

## Workflow (worktrees → branch → merge → cross-review)

1. **Develop** Slice B here on `account-moderation/http`. You may build in parallel with Claude's core using the contract above.
2. **Before greening Task 8/9 feature tests** you need the real `AccountModerationService`. Once Claude's `account-moderation/core` is merged to `main`, run:
   ```
   git fetch origin && git rebase origin/main
   ```
   then run the full suite.
3. **Style is non-negotiable and shared:** `declare(strict_types=1)`, `final` classes, full type hints, constructor promotion, FormRequests (no inline validation), JsonResources (never raw models, `public_id` only), Policies (no inline auth), one service per concern. Run `vendor\bin\pint` before every commit. Pest `it('...')` style.
4. **Verify:** `vendor\bin\pest` green (incl. existing Staff tests — your Task 9 must NOT change staff error codes; `StaffService` keeps its `StaffErrorCode` guards), `scripts/moderation-e2e.php` passes, `scripts/staff-e2e.php` + `scripts/orders-e2e.php` no regression, Pint clean.
5. **PR:** `feat(moderation): slice B — HTTP endpoints + StaffService delegation`. Claude reviews contract adherence (channel/route names, payload shapes, error codes) + style.

## Boundaries / gotchas

- **Shared database:** all worktrees use the same `.env` → same `delivary_app` / `delivary_app_testing`. Coordinate with Claude so you don't run `RefreshDatabase` suites simultaneously, or point this worktree's `DB_DATABASE`/test DB elsewhere.
- **Don't change staff API error codes.** Task 9 keeps `StaffService::assertNotSelf/assertNotLastAdmin` (→ `StaffDomainException`); only the status write + token revoke + cascade + audit move into `apply()`. Existing Staff feature tests + `staff-e2e.php` must stay green.
- Resources expose `public_id` only; nest `actor` as `{id: public_id, name}` — never `actor_id`/internal ids.
- `detail` is `required|min:3|max:1000`; `reason_code` is `required` + `Rule::enum(ModerationReason::class)` on the direct endpoints; StaffService delegation defaults `reason_code = Other`.
