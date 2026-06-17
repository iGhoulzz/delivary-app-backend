# Dashboard Support A (Admin-only) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the admin-only backend endpoints the built dashboard binds to, strictly additively, so the frontend builds on stable contracts.

**Architecture:** New admin controllers/resources/services + a handful of additive fields/optional-params on existing files. No refactor of shipped logic; money writes reuse the locked+ledgered `DriverAccountLedgerService`. Spec: `docs/superpowers/specs/2026-06-17-dashboard-support-a-design.md`. Process: `docs/WORKFLOW.md`.

**Tech Stack:** Laravel 11 (PHP 8.4), Sanctum, Spatie Permission, PostGIS (clickbar/magellan), Pest. All endpoints `auth:sanctum` + `role:admin` + `staff.password_change_required`.

---

## Slice ownership & merge order

| Slice | Owner | Scope | Depends on |
|---|---|---|---|
| **A** | Claude | Foundations: `me` enrichment, reference, map overview + Settings API | — |
| **C** | Claude | Driver admin reads (account/strikes), strike void/add, manual adjustment, `driver_strikes.public_id` | — |
| **B** | Codex | Users directory/detail, Orders additive filters, Merchants verify | — |
| **D** | Codex | Admin driver onboarding (reuse office services) | — |

**Merge order:** Claude merges **A → C** first; Codex rebases onto `origin/main` and merges **B → D**. `routes/api.php` + `app/Providers/AppServiceProvider.php` are shared-touch files — each slice appends its own block; Codex rebases to absorb Claude's additions. Per `docs/WORKFLOW.md §5`.

## File-structure map

**New files**
- `app/Http/Controllers/Api/Admin/ReferenceController.php` · `MapOverviewController.php` · `SettingsController.php` (A)
- `app/Http/Resources/Admin/ReferenceResource.php` · `MapOverviewResource.php` · `SettingResource.php` (A)
- `app/Http/Requests/Admin/UpdateSettingsRequest.php` (A)
- `app/Support/SettingsCatalog.php` (A — the editable allowlist + types)
- `app/Http/Controllers/Api/Admin/Driver/AccountController.php` · `StrikeController.php` (C)
- `app/Http/Resources/Driver/DriverStrikeResource.php` (C)
- `app/Services/Driver/DriverStrikeService.php` (C)
- `app/Http/Requests/Driver/AddStrikeRequest.php` · `VoidStrikeRequest.php` · `AdjustAccountRequest.php` (C)
- `database/migrations/2026_06_17_000100_add_public_id_to_driver_strikes_table.php` (C)
- `app/Http/Controllers/Api/Admin/UserDirectoryController.php` (B)
- `app/Http/Resources/Admin/UserDirectoryResource.php` · `UserDetailResource.php` (B)
- `app/Http/Requests/Admin/IndexUsersRequest.php` (B)
- `app/Http/Controllers/Api/Admin/Driver/OnboardingController.php` (D)
- `app/Http/Requests/Driver/AdminOnboardDriverRequest.php` (D)

**Additive touches (new field / optional param / nullable column only)**
- `app/Http/Controllers/Api/Auth/MeController.php` (A) — enrich response
- `app/Http/Resources/DriverProfileResource.php` (C) — `+account_status`
- `app/Http/Resources/DriverProfileFullResource.php` (C) — `+regions,last_active_at,deliveries_today,roles,orders_as_customer_count,notification_prefs`
- `app/Http/Requests/Driver/IndexDriverRequest.php` (C) — `+activity_status` optional filter; controller applies it
- `app/Http/Requests/Order/AdminListOrdersRequest.php` + `Api/Admin/OrderController.php` (B) — `+search,driver_public_id,merchant_public_id`
- `app/Services/Driver/DriverAccountLedgerService.php` (C) — `+applyManualAdjustment()`; widen private `mutateBucket($reference)` to nullable (backward-compatible)
- `app/Http/Resources/MerchantResource.php` (B) — verify/add override + pickup + owner fields
- `routes/api.php`, `app/Providers/AppServiceProvider.php` (all) — append routes + limiters

---

# SLICE A — Foundations + Settings (Claude)

### Task A1: `GET /auth/me` enrichment

**Files:**
- Modify: `app/Http/Controllers/Api/Auth/MeController.php`
- Test: `tests/Feature/Auth/MeEnrichmentTest.php`

- [ ] **Step 1 — failing test**
```php
it('enriches me with roles, password flag, office assignments and counts', function () {
    $admin = makeAdmin(); // helper: user with 'admin' role
    sanctumActingAs($admin);

    $res = getJson('/api/auth/me')->assertOk();
    $res->assertJsonStructure([
        'user' => ['id'],
        'roles',
        'must_change_password',
        'office_assignments',
        'counts' => ['pending_orders', 'unread_notifications'],
    ]);
    expect($res->json('roles'))->toContain('admin');
});
```
- [ ] **Step 2 — run, expect FAIL** (`roles` key missing).
- [ ] **Step 3 — implement** (additive sibling keys; `UserResource` untouched):
```php
return response()->json([
    'user' => (new UserResource($user))->resolve($request),
    'roles' => $user->getRoleNames()->values(),
    'must_change_password' => (bool) $user->must_change_password,
    'office_assignments' => $user->activeOfficeAssignments()
        ->with('office')->get()
        ->map(fn ($a) => ['id' => $a->office->public_id, 'name' => $a->office->name])->values(),
    'counts' => [
        'pending_orders' => Order::where('status', OrderStatus::AwaitingDriver)->count(),
        'unread_notifications' => $user->unreadNotifications()->count(),
    ],
]);
```
- [ ] **Step 4 — run, expect PASS.**
- [ ] **Step 5 — commit** `feat(admin): enrich /auth/me with roles, office, counts`.

### Task A2: `GET /admin/reference`

**Files:**
- Create: `app/Http/Controllers/Api/Admin/ReferenceController.php`, `app/Http/Resources/Admin/ReferenceResource.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Admin/ReferenceTest.php`

- [ ] **Step 1 — failing test**
```php
it('returns offices, regions and enum catalogs for an admin', function () {
    $world = Tests\Support\TestWorld::create();
    sanctumActingAs(makeAdmin());
    $res = getJson('/api/admin/reference')->assertOk();
    $res->assertJsonStructure([
        'offices' => [['id', 'name']],
        'regions' => [['id', 'name']],
        'enums' => ['driver_status', 'account_status', 'order_status', 'strike_reason', 'moderation_reason', 'vehicle_type'],
    ]);
});

it('forbids non-admins', function () {
    sanctumActingAs(makeDriverUser());
    getJson('/api/admin/reference')->assertForbidden();
});
```
- [ ] **Step 2 — run, expect FAIL** (404 route).
- [ ] **Step 3 — implement controller** — offices = `OfficeLocation::query()->get(['public_id','name'])`; regions = `Region::query()->get(['id','name'])` (numeric id — reference-data exception); enums via a helper mapping each enum's `cases()` to `{value,label_en,label_ar}` using the enum `label()` for EN and `lang/ar` for AR (or value-only if no AR yet). Route in the `admin` group.
- [ ] **Step 4 — run, expect PASS.**
- [ ] **Step 5 — commit** `feat(admin): reference catalog endpoint`.

### Task A3: `GET /admin/map/overview`

**Files:**
- Create: `app/Http/Controllers/Api/Admin/MapOverviewController.php`, `app/Http/Resources/Admin/MapOverviewResource.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Admin/MapOverviewTest.php`

- [ ] **Step 1 — failing test**: seed `TestWorld`, an active driver with `current_location` + `activity_status=online`; assert `offices[]` (id,name,location) and `drivers[]` (id,name,activity_status,location:{lat,lng},active_load).
- [ ] **Step 2 — run, expect FAIL.**
- [ ] **Step 3 — implement**: offices = active `OfficeLocation`s with location; drivers = `DriverProfile::where('status',DriverStatus::Active)->whereIn('activity_status',[Online,OnOrder])->whereNotNull('current_location')->with('user')->get()`, each mapped with `current_location` → `{lat,lng}` (mirror `AdminOrderResource::pt()`), `active_load` = `Order::where('driver_id',$u->id)->whereIn('status',[Assigned,...InFlight])->count()`. Reads existing columns only.
- [ ] **Step 4 — run, expect PASS.**
- [ ] **Step 5 — commit** `feat(admin): map overview endpoint`.

### Task A4: `app/Support/SettingsCatalog.php` (editable allowlist)

**Files:**
- Create: `app/Support/SettingsCatalog.php`
- Test: `tests/Unit/Support/SettingsCatalogTest.php`

- [ ] **Step 1 — failing test**: `SettingsCatalog::keys()` contains exactly the confirmed keys; each entry has `type` in {decimal,integer,boolean,json} and an optional `max`/`min`.
- [ ] **Step 2 — run, expect FAIL.**
- [ ] **Step 3 — implement** — a `final class` returning a map (no `pricing.delivery_fee_base` — base fee is per-region `regions.base_fee`, out of scope here):
```php
public const KEYS = [
    'pricing.item_commission_rate' => ['type' => 'decimal', 'min' => 0, 'max' => 1],
    'pricing.driver_fee_cut_rate'  => ['type' => 'decimal', 'min' => 0, 'max' => 1],
    'pricing.free_km'              => ['type' => 'integer', 'min' => 0],
    'pricing.per_km_rate'          => ['type' => 'decimal', 'min' => 0],
    'payouts.clearance_hours'      => ['type' => 'integer', 'min' => 0],
    'payouts.min_amount'           => ['type' => 'decimal', 'min' => 0],
    'payouts.allow_partial'        => ['type' => 'boolean'],
    'settlement.reverse_window_hours' => ['type' => 'integer', 'min' => 0],
    'new_driver_max_liability'     => ['type' => 'decimal', 'min' => 0],
    // pricing.item_size_modifiers (json) — read-only here; separate editor.
];
```
- [ ] **Step 4 — run, expect PASS.** **Step 5 — commit** `feat(admin): settings catalog`.

### Task A5: `GET /admin/settings`

**Files:** Create `SettingsController@index`, `app/Http/Resources/Admin/SettingResource.php`; modify `routes/api.php`. Test `tests/Feature/Admin/SettingsReadTest.php`.

- [ ] **Step 1 — failing test**: admin GET returns each catalog key with its current `PlatformSetting::get` value grouped by namespace; non-admin forbidden.
- [ ] **Step 2 — FAIL. Step 3 — implement**: iterate `SettingsCatalog::KEYS`, `PlatformSetting::get($key, default)`, group by the prefix before the first `.` (or `risk` for `new_driver_max_liability`). **Step 4 — PASS. Step 5 — commit** `feat(admin): read platform settings`.

### Task A6: `PATCH /admin/settings`

**Files:** `SettingsController@update`, `app/Http/Requests/Admin/UpdateSettingsRequest.php`; `routes/api.php`. Test `tests/Feature/Admin/SettingsUpdateTest.php`.

- [ ] **Step 1 — failing test**:
```php
it('updates a whitelisted rate within range and rejects out-of-range', function () {
    sanctumActingAs($admin = makeAdmin());
    patchJson('/api/admin/settings', ['pricing.item_commission_rate' => 0.2])->assertOk();
    expect((float) PlatformSetting::get('pricing.item_commission_rate'))->toBe(0.2);
    patchJson('/api/admin/settings', ['pricing.item_commission_rate' => 1.5])->assertStatus(422);
});
it('rejects keys not in the catalog', function () {
    sanctumActingAs(makeAdmin());
    patchJson('/api/admin/settings', ['codes.enforce_pickup' => false])->assertStatus(422);
});
```
- [ ] **Step 2 — FAIL.**
- [ ] **Step 3 — implement**: request validates each submitted key ∈ `SettingsCatalog::KEYS`, casts per `type`, enforces min/max; controller writes via `PlatformSetting::set($key, $value, $admin->id)` (already audits `updated_by_admin_id` + busts cache). Return the refreshed settings (reuse `@index`).
- [ ] **Step 4 — PASS. Step 5 — commit** `feat(admin): update platform settings (audited, ranged)`.

### Task A7: Slice A verification

- [ ] `vendor/bin/pint` clean; `DB_DATABASE=delivary_app_testing vendor/bin/pest tests/Feature/Admin tests/Feature/Auth/MeEnrichmentTest.php` green; `php artisan route:list --path=admin` shows reference/map/settings; existing suite unchanged. Commit any Pint fixes.

---

# SLICE C — Driver admin reads + powers (Claude)

### Task C1: `driver_strikes.public_id` migration

**Files:** Create `database/migrations/2026_06_17_000100_add_public_id_to_driver_strikes_table.php`; modify `app/Models/DriverStrike.php`. Test `tests/Feature/Admin/Driver/StrikePublicIdTest.php`.

- [ ] **Step 1 — failing test**: a created `DriverStrike` has a non-null unique `public_id`; `getRouteKeyName()==='public_id'`.
- [ ] **Step 2 — FAIL.**
- [ ] **Step 3 — implement** additive migration (nullable → backfill → unique), `up()`:
```php
Schema::table('driver_strikes', fn (Blueprint $t) => $t->ulid('public_id')->nullable()->after('id'));
DriverStrike::withoutEvents(fn () => DriverStrike::whereNull('public_id')->get()
    ->each(fn ($s) => $s->forceFill(['public_id' => (string) Str::ulid()])->saveQuietly()));
Schema::table('driver_strikes', fn (Blueprint $t) => $t->unique('public_id'));
```
`down()` drops the unique + column. Add to model: `public_id` fillable, `creating` ULID boot, `getRouteKeyName()`.
- [ ] **Step 4 — PASS. Step 5 — commit** `feat(driver): add public_id to driver_strikes`.

### Task C2: `GET /admin/drivers/{driverUser}/account`

**Files:** Create `app/Http/Controllers/Api/Admin/Driver/AccountController.php`; modify `routes/api.php`. Test `tests/Feature/Admin/Driver/AdminDriverAccountTest.php`.

- [ ] **Step 1 — failing test**: admin GET returns `account` (cash/earnings/debt/max_cash_liability/lifetime_*) + `transactions[]`; 404 when the user has no driver account; non-admin forbidden.
- [ ] **Step 2 — FAIL.**
- [ ] **Step 3 — implement** — mirror `app/Http/Controllers/Api/Driver/AccountController.php` but resolve the target `User $driverUser` from the route (admin reads anyone): load `$driverUser->driverAccount` (404 if null) + last 30 `driverAccountTransactions`, return via existing `DriverAccountResource` + `DriverAccountTransactionResource`. Route under `admin/drivers/{driverUser:public_id}/account`.
- [ ] **Step 4 — PASS. Step 5 — commit** `feat(admin): driver account read endpoint`.

### Task C3: `DriverStrikeResource` + `GET /admin/drivers/{driverUser}/strikes`

**Files:** Create `app/Http/Resources/Driver/DriverStrikeResource.php`, `app/Http/Controllers/Api/Admin/Driver/StrikeController.php@index`; `routes/api.php`. Test `tests/Feature/Admin/Driver/AdminDriverStrikesTest.php`.

- [ ] **Step 1 — failing test**: seed 2 strikes (one voided, one active within 30d); GET returns both with `{id(public_id), reason, issued_by, order, fee_amount, is_voided, created_at}` and an `active_count`.
- [ ] **Step 2 — FAIL.**
- [ ] **Step 3 — implement** resource (expose `public_id` as `id`, order as `{id:public_id}` when loaded) + controller returning the driver's strikes newest-first plus `active_count` (`!is_voided && created_at >= now()->subDays(30)`).
- [ ] **Step 4 — PASS. Step 5 — commit** `feat(admin): driver strikes read endpoint`.

### Task C4: `DriverStrikeService` + `POST /admin/drivers/{driverUser}/strikes` (add manual)

**Files:** Create `app/Services/Driver/DriverStrikeService.php`, `app/Http/Requests/Driver/AddStrikeRequest.php`, `StrikeController@store`; `routes/api.php`. Test `tests/Feature/Admin/Driver/AddStrikeTest.php` + `tests/Unit/Services/Driver/DriverStrikeServiceTest.php`.

- [ ] **Step 1 — failing test**: POST `{reason:'manual_admin', fee:5}` creates a strike (`issued_by='admin'`, `issued_by_admin_id`), and because `fee>0` a `driver_account_transactions` `strike_fee` row exists; with `fee:0` no ledger row.
- [ ] **Step 2 — FAIL.**
- [ ] **Step 3 — implement** `DriverStrikeService::addManual(User $driver, DriverStrikeReason $reason, string $fee, int $adminId, ?string $notes)`: in a `DB::transaction`, create the strike; if `bccomp($fee,'0.00',2)===1` call `DriverAccountLedgerService::applyFee($driver, $fee, DriverAccountTransactionReason::StrikeFee, $strike, $adminId, $notes)`. Request validates `reason ∈ DriverStrikeReason`, `fee` numeric ≥ 0.
- [ ] **Step 4 — PASS. Step 5 — commit** `feat(admin): add manual driver strike (+optional fee)`.

### Task C5: `POST /admin/drivers/{driverUser}/strikes/{strike:public_id}/void`

**Files:** `app/Http/Requests/Driver/VoidStrikeRequest.php`, `StrikeController@void`, `DriverStrikeService::void()`; `routes/api.php`. Test `tests/Feature/Admin/Driver/VoidStrikeTest.php`.

- [ ] **Step 1 — failing test**: POST void `{void_reason:'emergency'}` flips `is_voided=true,voided_at,voided_by_admin_id`; **asserts driver account balances are UNCHANGED** (no fee reversal); voiding an already-voided strike is idempotent/422.
- [ ] **Step 2 — FAIL.**
- [ ] **Step 3 — implement** `void(DriverStrike $strike, string $reason, int $adminId)` — pure status flip, no ledger touch. Bind `{strike:public_id}` and scope it to the path driver.
- [ ] **Step 4 — PASS. Step 5 — commit** `feat(admin): void driver strike (status-only, no fee reversal)`.

### Task C6: `applyManualAdjustment()` + `POST /admin/drivers/{driverUser}/account/adjust`

**Files:** Modify `app/Services/Driver/DriverAccountLedgerService.php`; create `app/Http/Requests/Driver/AdjustAccountRequest.php`, `AccountController@adjust`; `routes/api.php`. Test `tests/Unit/Services/Driver/ManualAdjustmentTest.php` + `tests/Feature/Admin/Driver/AdjustAccountTest.php`.

- [ ] **Step 1 — failing test**:
```php
it('applies an audited manual adjustment via the ledger', function () {
    $driver = makeActiveDriverWithAccount(); // cash=0
    app(DriverAccountLedgerService::class)->applyManualAdjustment(
        $driver, DriverAccountBucket::DebtBalance, '-25.00', $adminId = makeAdmin()->id, 'goodwill'
    );
    $acct = $driver->driverAccount()->first();
    expect((string) $acct->debt_balance)->toBe('0.00'); // clamped ≥ 0 if it would go negative? see note
    expect(DriverAccountTransaction::where('driver_id',$driver->id)
        ->where('reason','manual_adjustment')->where('created_by_admin_id',$adminId)->exists())->toBeTrue();
});
```
- [ ] **Step 2 — FAIL.**
- [ ] **Step 3 — implement**: widen private `mutateBucket(?Model $reference)` to nullable and only set `reference_type/_id` when non-null (existing callers pass a Model — unaffected). Add:
```php
public function applyManualAdjustment(User $driver, DriverAccountBucket $bucket, string $amount, int $adminId, ?string $notes = null): void
{
    DB::transaction(function () use ($driver, $bucket, $amount, $adminId, $notes) {
        $account = DriverAccount::query()->where('driver_id', $driver->id)->lockForUpdate()->firstOrFail();
        $this->mutateBucket($account, $bucket, $amount, DriverAccountTransactionReason::ManualAdjustment, null, $adminId, $notes);
    });
}
```
Request validates `bucket ∈ DriverAccountBucket`, `amount` a signed 2-dp decimal, `note` optional. Controller calls the service with `$request->user()->id`.
> **Note** (decide in test): buckets are non-negative (Critical Rule 5). If an adjustment would drive a bucket below 0, reject with 422 rather than clamp silently — keep the assertion explicit.
- [ ] **Step 4 — PASS. Step 5 — commit** `feat(admin): audited manual driver account adjustment`.

### Task C7: Additive driver resource/filter fields

**Files:** Modify `app/Http/Resources/DriverProfileResource.php` (+`account_status`), `DriverProfileFullResource.php` (+`regions,last_active_at,deliveries_today,roles,orders_as_customer_count,notification_prefs`), `app/Http/Requests/Driver/IndexDriverRequest.php` (+optional `activity_status`) and `Admin/DriverController@index` to apply it. Test `tests/Feature/Admin/Driver/DriverResourceAdditionsTest.php`.

- [ ] **Step 1 — failing test**: list rows include `account_status`; `?activity_status=online` filters; detail includes the new fields (`regions[]`, `deliveries_today` int, `roles[]`, `orders_as_customer_count` int, `notification_prefs:{push,sms,email}`).
- [ ] **Step 2 — FAIL.**
- [ ] **Step 3 — implement** additive fields (read existing columns: `user.account_status`, `driver.regions`, `last_active_at`; `deliveries_today` = delivered orders today; `orders_as_customer_count` = `Order::where('sender_user_id',$user->id)->count()`; `roles` = `$user->getRoleNames()`; `notification_prefs` from `users.push_/sms_/email_notifications_enabled`). Add `activity_status` to `IndexDriverRequest` rules + a `->when($request->input('activity_status'), …)` in the controller. **Existing fields/sort unchanged.**
- [ ] **Step 4 — PASS. Step 5 — commit** `feat(admin): additive driver list/detail fields + activity filter`.

### Task C8: Slice C verification — Pint, targeted Pest, `route:list --path=admin/drivers`, `migrate:status`; strike-void test proves balances unchanged.

---

# SLICE B — Users / Orders / Merchants (Codex)

### Task B1: `GET /admin/users` directory

**Files:** Create `app/Http/Controllers/Api/Admin/UserDirectoryController.php`, `app/Http/Resources/Admin/UserDirectoryResource.php`, `app/Http/Requests/Admin/IndexUsersRequest.php`; `routes/api.php`. Test `tests/Feature/Admin/Users/UserDirectoryTest.php`.

- [ ] **Step 1 — failing test**: seed users (customer, driver, merchant, banned); admin GET paginates; `?search=`, `?account_status=`, `?role=driver` filter; rows expose `{id, name, account_status, roles[], phone_verified, email_verified, orders_count, joined, driver_public_id, merchant_public_id}`; non-admin forbidden.
- [ ] **Step 2 — FAIL.**
- [ ] **Step 3 — implement**: query `User` with `->with('roles','driverProfile','merchantProfile')`, filters — `account_status` exact; `role` via `whereHas('roles', name=…)`; `search` over `first_name/last_name/phone_number/email/public_id`; `orders_count` via `withCount(['ordersAsSender'])` (add the relation if missing — additive). Resource maps fields; `roles` = `getRoleNames()`. Paginate 25. Route in `admin/users` group (keep existing `lookup`).
- [ ] **Step 4 — PASS. Step 5 — commit** `feat(admin): users directory index`.

### Task B2: `GET /admin/users/{user}` detail

**Files:** Create `UserDetailResource`, `UserDirectoryController@show`; `routes/api.php`. Test `tests/Feature/Admin/Users/UserDetailTest.php`.

- [ ] **Step 1 — failing test**: detail returns verification flags, roles, links, `notification_prefs` (read-only), and `orders_as_customer` summary; moderation history stays its existing endpoint.
- [ ] **Step 2 — FAIL. Step 3 — implement** resource (additive; reuse `account_status`, prefs columns, `getRoleNames`, profile links). **Step 4 — PASS. Step 5 — commit** `feat(admin): user detail endpoint`.

### Task B3: Orders additive filters

**Files:** Modify `app/Http/Requests/Order/AdminListOrdersRequest.php` (+optional `search,driver_public_id,merchant_public_id`) and `Api/Admin/OrderController@index` to apply them. Test `tests/Feature/Admin/Orders/AdminOrderFiltersTest.php`.

- [ ] **Step 1 — failing test**: `?driver_public_id=` returns only that driver's orders; `?merchant_public_id=` only that merchant's; `?search=` matches order `public_id`/party name. Existing `status`/`type` filters still pass their current tests unchanged.
- [ ] **Step 2 — FAIL.**
- [ ] **Step 3 — implement**: add the three optional rules; in the controller resolve `driver_public_id`→`users.id` and `merchant_public_id`→`merchant_profiles.id` via `PublicIdResolver`, and add `->when(...)` clauses + a `search` `where` group. No change to existing behaviour when params absent.
- [ ] **Step 4 — PASS. Step 5 — commit** `feat(admin): additive order filters (driver/merchant/search)`.

### Task B4: Merchant resource coverage (verify/additive)

**Files:** Read `app/Http/Resources/MerchantResource.php`; if missing, add `commission_rate_override`, `driver_fee_cut_override`, default pickup, owner embed (`name/phone/account_status/roles`). Test `tests/Feature/Admin/Merchants/MerchantResourceTest.php`.

- [ ] **Step 1 — failing test**: admin merchant show exposes the override rates (null when default), default pickup, and owner block.
- [ ] **Step 2 — run.** If already present → mark **EXISTS**, no change, delete the redundant test. Else **Step 3 — implement** additively. **Step 4 — PASS. Step 5 — commit** `feat(admin): merchant resource override/pickup/owner fields` (or `chore: confirm merchant resource coverage`).

### Task B5: Slice B verification — Pint, `DB_DATABASE=delivary_app_testing_codex vendor/bin/pest tests/Feature/Admin`, route checks, existing suite unchanged.

---

# SLICE D — Admin driver onboarding (Codex)

Reuses `DriverOnboardingService` / `DriverDocumentService` / phone-verify under an admin gate. Drives the **full** lifecycle — no shortcut to `pending_approval` (spec §4.3).

### Task D1: `POST /admin/drivers` (onboard / attach) + lookup

**Files:** Create `app/Http/Controllers/Api/Admin/Driver/OnboardingController.php`, `app/Http/Requests/Driver/AdminOnboardDriverRequest.php`; `routes/api.php`. Test `tests/Feature/Admin/Driver/AdminOnboardTest.php`.

- [ ] **Step 1 — failing test**: `mode=existing` (by `user_public_id`) attaches a `pre_registered` profile + vehicle + office; `mode=new` creates user + profile; result is **`pre_registered`** (NOT `pending_approval`); attaching to an already-driver user 422; banned user rejected.
- [ ] **Step 2 — FAIL.**
- [ ] **Step 3 — implement**: controller authorises `role:admin`, resolves/creates the user, then calls the existing `DriverOnboardingService` onboarding path (same one office uses) with the admin as actor. Do **not** add a new state path. Mirror office `lookup` for the user picker.
- [ ] **Step 4 — PASS. Step 5 — commit** `feat(admin): admin driver onboarding (reuses office lifecycle)`.

### Task D2: `POST /admin/drivers/{driverUser}/verify-phone`, `documents` (+delete), `submit`

**Files:** `OnboardingController` methods; `routes/api.php`. Tests in `tests/Feature/Admin/Driver/AdminOnboardLifecycleTest.php`.

- [ ] **Step 1 — failing test**: full chain — onboard → upload required docs (via `DriverDocumentService`) → verify phone (OTP) → submit → state is `pending_approval`; submitting without phone-verification or required docs is rejected exactly as the office flow rejects it.
- [ ] **Step 2 — FAIL.**
- [ ] **Step 3 — implement**: thin admin-gated wrappers delegating to the same services the office controllers use. Reuse their guards (no duplicated lifecycle rules).
- [ ] **Step 4 — PASS. Step 5 — commit** `feat(admin): admin onboarding documents + phone-verify + submit`.

### Task D3: Slice D verification — Pint, targeted Pest, `route:list --path=admin/drivers` shows onboard/verify-phone/documents/submit; the existing `approve`/`reject` still work; office onboarding tests unchanged.

---

## Cross-cutting closeout (after all slices merge + smokes pass)

- [ ] Update `docs/CLAUDE.md` "Current Project State" + endpoint tables.
- [ ] Add `docs/SYSTEM_SPECIFICATION.md §17.18 Dashboard Support A`.
- [ ] Append `docs/CODEX.md` log entries (Codex: B+D; Claude: A+C).
- [ ] `/security-review` (touches money via manual adjustment + onboarding).
- [ ] Update memory `status_milestones_handoff`.

## Plan self-review notes

- **Spec coverage:** every §4 row maps to a task (A1–A6 foundations+settings; C1–C7 drivers; B1–B4 users/orders/merchants; D1–D2 onboarding). Deferred items (finance/summary/activity, notif-pref editing, admin-created orders) intentionally have no task.
- **Additive proof:** the only schema add is `driver_strikes.public_id` (C1); the only existing-service change is widening `mutateBucket`'s `$reference` to nullable + adding `applyManualAdjustment` (C6) — backward-compatible. All else is new files or new fields/optional params. Slice verifications require the existing suite to stay green unchanged.
- **Type consistency:** `applyManualAdjustment(User,DriverAccountBucket,string,int,?string)` and `DriverStrikeService::addManual/void` signatures are used identically across their tasks; reasons use the existing `DriverAccountTransactionReason::{StrikeFee,ManualAdjustment}` / `DriverStrikeReason` enums.
