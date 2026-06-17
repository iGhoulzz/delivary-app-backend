# Claude + Codex Collaboration Workflow

> The single source of truth for **how** we build a milestone — roles, worktrees, branches,
> slicing, review, verification, and closeout. This consolidates the process that was previously
> scattered across `docs/CLAUDE.md`, `docs/CODEX.md`, and the `SYSTEM_SPECIFICATION.md §17`
> milestone notes. `docs/CODEX.md` remains the **running implementation log** (one entry per
> slice); this doc is the **process**.

---

## 1. Two agents, two worktrees

| | Claude | Codex |
|---|---|---|
| Worktree | `C:\Users\User\Desktop\delivary-app` (main) | `C:\Users\User\Desktop\delivary-app-codex` |
| Typical branch prefix | `feat/…`, milestone-named | `codex/…` |
| Strengths used for | Domain core, financial/stateful services, policies, cross-review | Mechanical/parallel slices, HTTP wiring, scaffolding, second-opinion review |
| Test DB | `delivary_app_testing` | `delivary_app_testing_codex` (per-worktree, see §4) |

Both agents are senior Laravel engineers following `docs/CLAUDE.md` (strict types, `final`,
FormRequests, services-not-controllers, Pest). Neither merges to `main` without the other's
review.

## 2. The milestone pipeline

```
brainstorm ──> spec ──> plan (sliced) ──> execute slices ──> verify ──> cross-review ──> merge ──> docs closeout
 (skill)      specs/      plans/          (worktrees)        (gates)      (PRs)          (order)     (CLAUDE/§17/CODEX)
```

1. **Brainstorm** (`superpowers:brainstorming`) → a design doc in `docs/superpowers/specs/YYYY-MM-DD-<topic>-design.md`. Locked decisions live here.
2. **Plan** (`superpowers:writing-plans`) → `docs/superpowers/plans/YYYY-MM-DD-<topic>.md`, divided into **slices** with explicit ownership and merge order.
3. **Execute** each slice in its owner's worktree (TDD, frequent commits).
4. **Verify** against the gates in §7 before opening a PR.
5. **Cross-review** — the other agent reviews the PR (§6).
6. **Merge** in dependency order (§5).
7. **Closeout** the docs (§8).

## 3. Branch & PR conventions

- One branch per slice. Claude: `feat/<milestone>` or `<milestone>/<slice>`. Codex: `codex/<milestone>-<slice>`.
- Never commit milestone work directly to `main`. If you find yourself on `main`, branch first.
- One PR per slice. PR body ends with the standard Claude Code trailer.
- Keep slices small enough to review in one sitting.

## 4. Worktrees & per-worktree test DB

- Codex works in a **separate git worktree** (`delivary-app-codex`) so the two agents never fight over the working tree.
- **Test DB isolation is mandatory** — `phpunit.xml` defaults to `delivary_app_testing` with `force="false"`, so each worktree overrides it by **exporting** `DB_DATABASE` before running Pest (an exported var wins; `.env.testing` does **not**, because phpunit sets the var before Laravel loads it):
  ```bash
  # Codex worktree, once:  createdb delivary_app_testing_codex
  DB_DATABASE=delivary_app_testing_codex vendor/bin/pest
  ```
- The dev DB (`delivary_app`) is never touched by tests (`tests/Feature/Smoke/TestEnvironmentTest` guards this).
- Postgres/PostGIS runs in Docker on `127.0.0.1:5432`. If `migrate:status` fails, the container is down — start Docker Desktop first.

## 5. Slicing & dependency ordering

- A milestone is split into **slices** (`Slice A`, `Slice B`, …). Each slice has **one owner** and, where useful, its own plan file: `…-<slice>-<owner>.md` (e.g. `2026-05-20-staff-crud-slice-a-claude.md`).
- **Pick boundaries that minimise shared files.** Owners must not edit each other's files in the same cycle.
- **When a slice depends on another's core:**
  1. The dependent slice does **Phase 1** first — independent scaffolding with typed **stubs** (e.g. `RuntimeException` placeholders), routes left unwired, dependent tests marked skipped.
  2. The **base** slice merges to `main` first.
  3. The dependent slice **rebases onto `origin/main`** and does **Phase 2** — swap stubs for the real core, wire routes, un-skip tests, integrate.
  *(This is exactly how Staff CRUD and Account Moderation ran — see `docs/CODEX.md`.)*

## 6. Cross-review

- **Every slice is reviewed by the other agent before merge.** Reviewer uses `superpowers:requesting-code-review` discipline; the author receiving feedback uses `superpowers:receiving-code-review` (verify each point against the code, no performative agreement, push back with technical reasoning).
- The reviewer leaves **explicit review notes** (in the PR and/or a "Review note for Claude/Codex" block in `CODEX.md`). Real example: Claude's review of Staff Slice B caught a blocking `suspend`/`deactivate` assignment-lifecycle inversion before merge.
- **Merge order = dependency order.** The base/independent slice merges first; the dependent slice rebases and merges second.
- A post-merge **security review** (`/security-review`) is run on milestones that touch auth, money, or moderation.

## 7. Verification gates (before any PR / "done" claim)

Run from the slice's worktree. Never claim done without the output (`superpowers:verification-before-completion`).

```bash
vendor/bin/pint                          # PSR-12 + Laravel preset — must be clean
DB_DATABASE=<worktree_db> vendor/bin/pest  # full suite green (or the targeted feature dirs)
php artisan route:list --path=api        # new routes present
php artisan migrate:status               # no unexpected pending migrations
```

- **Smoke scripts** (`scripts/*-e2e.php`) and the Pest smokes in `tests/Feature/Smoke/` are the end-to-end source of truth. Run smokes with `BROADCAST_CONNECTION=null` unless Reverb (port 8080) is actually running:
  ```bash
  $env:BROADCAST_CONNECTION='null'; php artisan tinker --execute="require base_path('scripts/orders-e2e.php');"
  ```
- **Additive-only milestones** carry an extra gate: the existing suite must stay green with **zero changes to existing test expectations** — that is the proof nothing was refactored.

## 8. Closeout (after smoke passes — not before)

1. `docs/CODEX.md` — append the slice's implementation-log entry (files added/updated, behaviour, verification results). Codex owns its entries; Claude appends its own.
2. `docs/CLAUDE.md` — update **"Current Project State"** (cumulative status + endpoint table).
3. `docs/SYSTEM_SPECIFICATION.md §17` — add the milestone subsection.
4. Mark the spec/plan implemented.
5. Update agent memory handoff (`status_milestones_handoff`).

## 9. One-screen checklist

- [ ] Spec exists + reviewed (`specs/`)
- [ ] Plan exists + sliced with owners + merge order (`plans/`)
- [ ] Each agent on its own branch in its own worktree, isolated test DB
- [ ] TDD, frequent commits, follow `docs/CLAUDE.md` style
- [ ] Phase 1 (stubs) before a dependency merges; Phase 2 (rebase) after
- [ ] Verification gates green (Pint / Pest / routes / migrate / smokes)
- [ ] Cross-review done; reviewer notes addressed; merged in dependency order
- [ ] Security review if auth/money/moderation touched
- [ ] Docs closeout (CODEX log, CLAUDE state, §17, memory)
