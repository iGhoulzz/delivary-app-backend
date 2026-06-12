# Merchant Deliveries — Slice A (Onboarding) — Codex Handoff

**You are building Slice A** of the Merchant Deliveries milestone: the **admin-facing merchant onboarding** (admin/merchants CRUD + lifecycle). Claude is building Slice B (merchant order flow) in parallel on a separate branch.

## Read these first
- **Plan:** `docs/superpowers/plans/2026-06-12-merchant-deliveries.md` — your tasks are **Slice A (A.1–A.6)**. Build them in order, TDD.
- **Spec:** `docs/superpowers/specs/2026-06-12-merchant-deliveries-design.md` — §5 (onboarding) is authoritative.
- **Conventions:** `docs/CLAUDE.md` (code style, public_id, Critical Rules).

## Branch & environment
- **Fork from current `main`** (Phase 0 is already merged there): `git checkout main && git pull && git checkout -b merchant-deliveries/onboarding`.
- **Test DB (per-worktree):** export `DB_DATABASE=delivary_app_testing_codex` before Pest; `createdb` it once via Docker (`docker exec delivery-postgis createdb -U postgres delivary_app_testing_codex`). Don't run RefreshDatabase suites against `delivary_app_testing` while Claude is using it.
- Docker `delivery-postgis` (127.0.0.1:5432) must be up.

## Already on main from Phase 0 — DO NOT recreate
- `App\Enums\MerchantErrorCode` (values are **UPPERCASE**, e.g. `USER_NOT_FOUND`; has `httpStatus()`). It already includes every case Slice A needs: `UserNotFound`, `AlreadyMerchant`, `AccountNotEligible`, `InvalidStatusTransition`.
- `App\Exceptions\Merchant\MerchantException` (carries `MerchantErrorCode $errorCode`, `httpStatus()`, `toResponse()`); already rendered in `bootstrap/app.php`.
- `lang/en/merchant_messages.php` + `lang/ar/merchant_messages.php` (all keys present — use `trans('merchant_messages.<key>')`).
- `Database\Factories\MerchantProfileFactory` (states: `active` default, `->suspended()`, `->banned()`); `MerchantProfile` now has `HasFactory`.

## Your files (all new except routes)
- `app/Policies/MerchantProfilePolicy.php`
- `app/Http/Requests/Admin/Merchant/{StoreMerchantRequest,UpdateMerchantRequest}.php`
- `app/Http/Resources/MerchantResource.php`
- `app/Services/Merchant/MerchantOnboardingService.php`
- `app/Http/Controllers/Admin/MerchantController.php` (`lookup` is a method on it)
- `routes/api.php` (append the `admin/merchants` group — see plan A.6)
- Tests: `tests/Feature/Admin/MerchantOnboardingTest.php`, `tests/Unit/Merchant/MerchantOnboardingServiceTest.php`, `tests/Feature/Admin/MerchantPolicyTest.php`

## Non-negotiable gotchas (these are where reviews will bite)
1. **Policy must be named `MerchantProfilePolicy`** (model-named → Laravel auto-discovers it). Do NOT register it manually and do NOT call it `MerchantPolicy`.
2. **Onboarding guard ORDER (strict):** resolve user → **reject banned account (`AccountNotEligible`) BEFORE** inspecting any profile → then inspect `MerchantProfile::withTrashed()->lockForUpdate()`:
   - live profile (active/suspended/banned, `deleted_at IS NULL`) → `AlreadyMerchant`;
   - soft-deleted **banned** → `AlreadyMerchant` (ban is terminal — do NOT restore);
   - soft-deleted **non-banned** → **restore + reactivate**;
   - none → create fresh.
3. **Lock+reload+guard in one txn** (Critical Rule 3) — mirror `app/Services/Account/AccountModerationService.php`. Never check-then-act on a stale instance.
4. **Assign the Spatie `merchant` role** on activation (create/restore); **remove it on `ban`**. Role stays through suspend/reactivate.
5. **`Point::makeGeodetic($lat, $lng)`** for `default_pickup_location` — argument order is **(lat, lng)** in this codebase (see `tests/Support/TestWorld.php`, `CreationService`). NOT (lng, lat).
6. **Override validation:** `commission_rate_override` / `driver_fee_cut_override` → `['nullable', 'decimal:0,4', 'min:0', 'max:1']`.
7. **Routes:** `['auth:sanctum', 'role:admin', 'staff.password_change_required']` on the whole `admin/merchants` group; put `lookup` BEFORE `{merchant:public_id}` so it isn't captured as a route key.
8. **`MerchantResource`** exposes `public_id` as `id`; owner as nested `{id, name}` via `whenLoaded('user')` (Critical Rule 11 — never raw internal ids).
9. **Ban is terminal** on the merchant axis (no reactivate-from-banned). Distinct from account-moderation's `banned` (which is reversible) — keep the two axes separate.

## Do NOT touch (Slice B / Phase 0 territory)
- `app/Services/Order/{CreationService,PricingService,QuoteService}.php`, `app/ValueObjects/MerchantOrderContext.php`, `app/Http/Middleware/EnsureActiveMerchant.php`, `app/Services/Merchant/MerchantOrderCreationService.php`, anything under `merchant/orders`.

## Coordination
- `routes/api.php` is appended by both slices (you: `admin/merchants` group; Claude: `merchant/orders` group). Trivial merge — keep your group self-contained.
- **Cross-review:** Claude reviews your Slice A PR; you review Claude's Slice B PR (the established pattern).

## Done = 
- Pint clean (`vendor/bin/pint --test`), your feature + service + policy tests green, full suite green on your branch. Then push and open a PR to `main`.
