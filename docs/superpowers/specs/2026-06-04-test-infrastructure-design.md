# Test Infrastructure Milestone — Design Spec

**Date:** 2026-06-04
**Status:** Approved (design); awaiting user review of this written spec
**Note:** Educational milestone — implementation will be narrated (each test's type, level, and what it can/can't catch explained as it's written).

---

## 0. Context

The project already has a healthy automated test setup: Pest, a dedicated test database (`phpunit.xml` sets `DB_DATABASE=delivary_app_testing`, plus `array` cache/mail, `sync` queue, `bcrypt rounds=4`, `null` broadcaster), and a `tests/Unit` + `tests/Feature` tree that mirrors `app/`. Merged `main` is green at **Pest 163/163**.

The gap is the **end-to-end / integration scenarios**: they live as **Tinker scripts** (`scripts/orders-e2e.php` 32 scenarios, `scripts/staff-e2e.php` 6, `scripts/moderation-e2e.php` 6) that must be run by hand, print `echo "PASS"` instead of real assertions, run against the **dev** DB (rolled back), and are **not** part of the Pest suite or any CI. Two consequences: they can silently rot, and nothing proves the system green on a clean machine.

This milestone closes that gap: convert the three smokes into Pest tests, fix a worktree-DB clash that the parallel Claude/Codex workflow exposed, and add CI.

---

## 1. Locked decisions (from brainstorming)

| # | Decision | Rationale |
|---|---|---|
| 1 | **Convert all 3 smokes to Pest** under `tests/Feature/Smoke/` | Project already uses Laravel feature-test structure; these boot the app + hit the real (test) DB + exercise multiple services — "integration-style" but they fit cleanly as feature tests |
| 2 | **Order conversion: staff + moderation first (warm-ups), then orders in 4 batches** | Small ones teach the pattern; the 32-scenario orders smoke is split so no single PR/file is painful to review |
| 3 | **Per-worktree test DB** via overridable `DB_DATABASE` | Parallel worktrees sharing one `delivary_app_testing` let one `RefreshDatabase` run wipe/migrate while the other is mid-run — a real correctness issue, not a nicety |
| 4 | **Keep the Tinker scripts** | Useful manual debugging tool (run against dev data); Pest becomes the source of truth that CI runs |
| 5 | **Add CI (GitHub Actions)** running Pest, not the Tinker scripts | The payoff: every push/PR proves migrations + Pint + Pest green on a clean machine with Postgres/PostGIS |
| 6 | **`scripts/realtime-smoke.php` is OUT of scope** | Realtime has special broadcast/`$afterCommit` behavior (can't use a rollback harness); convert it later as a separate small `RealtimeSmokeTest`, or leave queued |

### Out of scope
- Converting `realtime-smoke.php` (separate follow-up).
- Code-coverage reporting / thresholds (future).
- Mutation testing, parallel test execution tuning, Dusk/browser tests.
- Deleting the Tinker scripts.

---

## 2. Per-worktree test database (#3)

Today `phpunit.xml` hardcodes `<env name="DB_DATABASE" value="delivary_app_testing"/>`, so all worktrees share one test DB and a `RefreshDatabase` run in one can wipe/migrate it under another.

**Goal:** each worktree can run Pest against **its own** test DB without editing tracked files — while two hard safety constraints hold:
1. **CI and fresh clones default safely** to `delivary_app_testing` with zero setup.
2. A missing/forgotten config must **never** fall through to the **dev** database (`delivary_app`). Tests must always target *a* test DB.

**Mechanism (finalize + verify in the plan):** the leading option is a **gitignored `.env.testing`** (with a committed `.env.testing.example` defaulting to `delivary_app_testing`) that a developer copies and edits to e.g. `DB_DATABASE=delivary_app_testing_claude` / `_codex`. Because PHPUnit's `<env>` entries are applied before Laravel loads `.env.testing` (and Laravel's dotenv is immutable — first value wins), the plan must pick a precedence that actually lets `.env.testing` override while preserving the two safety constraints (e.g. drop `DB_DATABASE` from `phpunit.xml` but keep an explicit safe default elsewhere, or use `force`/load-order so the committed test default still applies when no `.env.testing` exists). The plan **must include a test/check** that (a) two worktrees with different `.env.testing` don't interfere, and (b) with no `.env.testing`, the suite targets `delivary_app_testing`, not `delivary_app`.
- Each developer creates their per-worktree DB once (`createdb delivary_app_testing_claude`, or via the docker postgres container).
- Document the setup in `docs/CLAUDE.md` (testing section) + `.env.testing.example`.

*Lesson: test isolation isn't only per-test (`RefreshDatabase`) — it's also per-environment (which DB / which worker), and the safe default matters as much as the override.*

---

## 3. Smoke → Pest conversion (#1, #2)

**Folder:** `tests/Feature/Smoke/` — `StaffLifecycleTest`, `ModerationLifecycleTest`, then `Orders*Test` (batched, §4).

**Conversion rules (faithful, not a rewrite):**
- Each Tinker scenario (delimited today by `echo "Scenario N: ..."`) becomes one `it('...')` block with a descriptive name.
- `uses(RefreshDatabase::class)` per file — drops the manual `DB::beginTransaction()/rollBack()` harness; Pest resets the DB per test, giving each scenario a clean slate (stronger isolation than the smokes' shared-transaction approach).
- Replace every `$assert(cond, 'msg')` with a real Pest expectation: `expect($order->status)->toBe(OrderStatus::Delivered)`, `$this->assertDatabaseHas(...)`, etc.
- Keep driving the **real services** (`CreationService`, `ClaimService`, `CodeVerificationService`, `SettlementService`, …) exactly as the smokes do — this is what makes them integration tests. Add an HTTP-level assertion only where it adds clear value (most lifecycle steps have no controller seam worth re-testing here; those are covered by existing endpoint feature tests).
- Reuse existing factories + the shared `tests/Pest.php` helpers (`makeOnlineDriverAt`, `makeOrderAt`) where applicable; extract shared setup (region/office/driver bootstrap) into a small helper rather than copy-pasting the smoke's preamble into every test.
- Error scenarios (e.g. excess-cash retrieval, after-pickup cancel) convert to `expect(fn () => ...)->toThrow(OrderDomainException::class)` (or `->throws(...)`).

**Definition of faithful:** the converted suite asserts at least everything the corresponding smoke asserted (same scenario count or more, same invariants).

---

## 4. Orders batching (#2)

`staff-e2e` (6) and `moderation-e2e` (6) convert first as warm-ups (one PR each, or one combined warm-up PR). Then the 32 order scenarios split into **4 batches**, each its own file + PR/commit:

| Batch | File | Scenarios (from `orders-e2e.php`) |
|---|---|---|
| A | `OrdersHappyPathTest` | creation, online/broadcast, claim, pickup, arrived-dropoff, delivery, fee/earnings credit (scenarios ~1) |
| B | `OrdersExceptionsTest` | escalation tiers + no-driver timeout, retry, free/fee cancellations, admin assign/unassign, driver-fault unassign+strike, after-pickup cancel rejection (scenarios ~2–8) |
| C | `OrdersReturnFlowTest` | failed delivery → return-to-office, receive-return, retrieval w/ storage, redirect, waiver, admin-fail-from-picked-up, abandonment cron, excess-cash error, auto-offline (scenarios ~9–17) |
| D | `OrdersSettlementTest` | settlement match/empty/excess/shortage/zero-net, earning flips, clearance cron, payout happy/partial/mismatch/below-min, reversal happy/blocked/debt-restore (scenarios ~18–32) |

Exact scenario→batch mapping is finalized in the implementation plan; the goal is reviewable chunks, not perfection.

---

## 5. CI (GitHub Actions) (#5)

New `.github/workflows/ci.yml`, triggered on `push` + `pull_request`:
- **Services:** a Postgres image **with PostGIS** (e.g. `postgis/postgis:16-3.4`) as a service container; env `POSTGRES_DB=delivary_app_testing`, `POSTGRES_USER=postgres`, `POSTGRES_PASSWORD=secret`; health-check until ready.
- **Steps:** checkout → `shivammathur/setup-php@v2` (PHP 8.3, required extensions incl. `pdo_pgsql`, `bcmath`, `gd`/`exif` if media needs them) → cache + `composer install --no-interaction --prefer-dist` → copy `.env` (`cp .env.example .env`) + `php artisan key:generate` → `php artisan migrate --force` → `vendor/bin/pint --test` → `vendor/bin/pest`.
- CI uses the committed `delivary_app_testing` default (no `.env.testing`), so it's hermetic.
- The workflow runs **Pest only** — never the Tinker scripts.

*Lesson: CI is the same suite you run locally, executed on a clean machine — it catches "works on my machine" gaps (missing migration, env assumption, PostGIS extension).*

---

## 6. Verification

This milestone's "tests" are the converted tests themselves. Verification is meta:
- Each converted file's scenario count ≥ its smoke's, all green.
- Full `vendor/bin/pest` green locally (target: 163 existing + ~44 converted scenarios).
- `vendor/bin/pint --test` clean.
- CI workflow green on the PR (the real proof).
- The 3 Tinker scripts still run (kept for manual use) — quick sanity, not gated.

---

## 7. File map

**New:**
- `tests/Feature/Smoke/StaffLifecycleTest.php`
- `tests/Feature/Smoke/ModerationLifecycleTest.php`
- `tests/Feature/Smoke/OrdersHappyPathTest.php`
- `tests/Feature/Smoke/OrdersExceptionsTest.php`
- `tests/Feature/Smoke/OrdersReturnFlowTest.php`
- `tests/Feature/Smoke/OrdersSettlementTest.php`
- `tests/Feature/Smoke/SmokeWorld.php` (or additions to `tests/Pest.php`) — shared region/office/driver bootstrap helper
- `.github/workflows/ci.yml`
- `.env.testing.example`

**Modified:**
- `phpunit.xml` (allow `.env.testing` override; keep committed default)
- `.gitignore` (ignore `.env.testing`)
- `docs/CLAUDE.md` (testing section: per-worktree DB instructions; mark milestone)
- `docs/SYSTEM_SPECIFICATION.md` (§17.16 milestone record)

**Unchanged (kept):** `scripts/orders-e2e.php`, `scripts/staff-e2e.php`, `scripts/moderation-e2e.php`, `scripts/realtime-smoke.php`.

---

## 8. Done criteria

- [ ] `tests/Feature/Smoke/` holds Pest equivalents of staff (6), moderation (6), orders (32) smokes — each scenario a named `it()` with real assertions, `RefreshDatabase`.
- [ ] Shared bootstrap helper (no copy-pasted preamble).
- [ ] Per-worktree test DB supported via gitignored `.env.testing` + `.env.testing.example` + docs; CI uses the default.
- [ ] `.github/workflows/ci.yml` runs migrate + Pint + Pest with a PostGIS service; green on the PR.
- [ ] Full Pest suite green; Pint clean.
- [ ] Tinker scripts retained and still runnable.
- [ ] `realtime-smoke.php` explicitly left for a follow-up.
- [ ] Docs updated (§17.16 + CLAUDE.md testing section).
