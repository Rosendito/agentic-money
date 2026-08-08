---
id: TASK-002
title: CI pipeline validating against SQLite and PostgreSQL
status: done
created_at: 2026-08-07
---

# TASK-002: CI pipeline validating against SQLite and PostgreSQL

## Intention

Make CI green and authoritative. The pipeline must run the full test suite twice on every push and
pull request — once on SQLite and once on PostgreSQL — so the ledger schema is continuously
validated by an engine with true `DECIMAL(38, 18)` enforcement, while the zero-configuration SQLite
path developers use daily is proven not to rot.

The pipeline is currently red before it reaches a single test, so repairing it and adding the
second engine are one deliverable: a CI fix nobody can watch turn green is not verifiable.

## Context

ADR-001 (as amended) stores monetary values as CHECK-constrained TEXT-affinity columns on SQLite
because its NUMERIC affinity coerces decimals to floats. That amendment made CI against a real
decimal engine the authoritative enforcement layer and deferred it to this task.

Three independent defects keep the workflow from running today. They surface one after the other,
so fixing only the first does not produce a green build:

1. **PHP version.** `.github/workflows/tests.yml` pins PHP 8.3, but `composer.lock` was resolved on
   PHP 8.5 and its packages require `>=8.4.1`. Composer refuses to install. Compounding this,
   `composer.json` declares `"php": "^8.3"`, which the lock file contradicts — the declared floor
   must match the real one or the same misconfiguration can return.
2. **pnpm is absent.** The `composer setup` script runs `pnpm install` and `pnpm run build`, but the
   workflow never installs pnpm, and no pnpm version is pinned anywhere in `package.json`.
3. **`ci:check` calls npm.** The script invokes `npm run lint:check`, `npm run format:check`, and
   `npm run types:check`, contradicting the project's pnpm-only rule. The workflow also installs
   Node 22 while `package.json` pins Node 24.18.0 through Volta.

## Required reading

- [Ledger knowledge base](../README.md)
- [Domain architecture](../09-domain-architecture.md)
- [ADR-001](../decisions/ADR-001-decimal-precision-and-rounding.md)

## Rules that must remain true

- ADR-001 (per-engine exact decimal representation)
- `LIF-011`, `LIF-012`
- The project uses pnpm for every JavaScript operation; npm and yarn must not appear in any script
  or workflow step.

## Design and hidden risks

### Version decisions

- Raise `composer.json` to `"php": "^8.4"` — the real floor the lock file already imposes. Do not
  raise it to `^8.5`; that pins the project to one developer's machine for no present benefit.
  Refresh the lock hash after the change so CI does not warn about an outdated lock file.
- Run CI on **PHP 8.4**, not 8.5. Local development runs 8.5, so testing the declared minimum is
  what catches accidental use of an 8.5-only feature.
- Pin **PostgreSQL 17**.
- Align Node with the Volta pin (24) and pin the pnpm version by adding a `packageManager` field to
  `package.json`, so the workflow and local machines cannot drift apart.

### Test execution

- Tests run in parallel everywhere. Update the `test` composer script to pass `--parallel` so local
  runs and CI share one command.
- On SQLite the suite uses `:memory:`. Laravel detects this and skips per-process database creation
  entirely (`TestDatabases.php:161`), because each process already owns an isolated in-memory
  database. Nothing needs configuring for the SQLite leg.
- On PostgreSQL, Laravel creates one database per process by appending `_test_{token}` to the
  configured name (`TestDatabases.php:208`) via `Schema::createDatabase()`. The CI database user
  therefore needs `CREATEDB`; the official Postgres service container's `postgres` superuser has it.
- `phpunit.xml` hardcodes `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` as `<env>` entries.
  PHPUnit does not overwrite variables that already exist in the real environment unless
  `force="true"` is set, so the PostgreSQL leg can override both from the workflow's job
  environment. Do not add `force="true"` to those entries — it would break this.
- `composer setup` is a developer-onboarding script: it copies `.env`, generates a key, and runs
  `php artisan migrate --force`. Migrating up front is redundant in CI because `RefreshDatabase`
  migrates per process. Use a leaner CI path if `composer setup` fights the matrix, but do not
  degrade the local onboarding experience to do it.

### Test Impact Analysis (local only)

- Enable Pest 5's Tia Engine for local runs with `--tia --locally`. That flag activates impact
  analysis on developer machines and disables itself on CI, which keeps one shared command while
  respecting Pest's own guidance that CI must always execute the full suite on a clean checkout.
- **Prerequisite:** TIA requires a coverage driver (PCOV or Xdebug) to record its dependency graph.
  Neither is installed on the current development machine. Document `pecl install pcov` in the
  developer setup notes, and make sure the suite still runs — just without replay — when no driver
  is present.
- **Unverified:** `php artisan test` does not declare `--tia` among its own options. Confirm
  empirically that Laravel's test command forwards the flag to Pest. If it does not, either invoke
  `vendor/bin/pest --parallel` directly (verifying that per-process databases still work through the
  Pest Laravel plugin) or keep TIA in a separate local-only script. Report which path was taken.
- Add the TIA cache directory to `.gitignore`. Snapshot and shard data under `tests/.pest/` that is
  meant to be shared must stay tracked; the per-machine dependency graph must not.

## Acceptance criteria

- [x] The workflow runs the full suite on both SQLite and PostgreSQL 17 on every push to `main`
      and every pull request, and both legs must pass for the build to be green. *(Reworded by the
      planner on 2026-08-08 to match the intended trigger scope; see validation finding 2.)*
- [x] The 18-fractional-digit round-trip and constraint tests pass on PostgreSQL.
- [x] Tests run with `--parallel` in both legs and in the shared local command.
- [x] Static analysis, formatting, and frontend checks run in the same pipeline, using pnpm only.
- [x] A failing test, type error, or formatting violation fails the pipeline. Demonstrate this, do
      not assume it.
- [x] `composer.json` declares `"php": "^8.4"` and the lock file is consistent with it.
- [x] `package.json` pins the pnpm version, and the workflow's Node version matches the Volta pin.
- [x] TIA is provably inactive on CI (by design, via `--no-tia`); local activation via `--tia
      --locally` remains implemented but unproven, since no machine in evidence has a coverage
      driver installed.
- [x] Developer setup notes record the PCOV prerequisite.

## Out of scope

- Changing the local development database away from SQLite.
- **Test sharding.** Pest 5 supports time-balanced sharding, but the suite is currently small enough
  that splitting it across machines costs more in container startup than it saves, on top of a
  matrix that already runs two jobs. Revisit when the CI test step exceeds roughly five minutes.
- A CI workflow that records a shared TIA baseline on merges to `main`. Worth doing once the suite
  is large enough for developers to feel the first cold run; not now.
- Mutation testing, browser testing, and profanity checks.
- Deployment, hosting, or production database provisioning.

## Execution

> Filled by the executor.

- **Summary:** Fixed the three CI defects (PHP 8.3→8.4 pin, missing pnpm, `npm` calls in
  `ci:check`), raised `composer.json` to `"php": "^8.4"` with a refreshed lock hash, and added a
  `db: [sqlite, pgsql]` matrix with a PostgreSQL 17 service container. `composer test` now runs
  `php artisan test --tia --locally --parallel`; `package.json` pins `packageManager: pnpm@10.33.4`
  and the workflow's Node version matches the Volta pin (24.18.0). Along the way, fixed two defects
  that blocked the acceptance criteria from being satisfiable: two test files broke `--parallel`
  (redundant `use RuntimeException;` in the global namespace, a no-op PHP warning that ParaTest
  promotes to a fatal error), and `DeletionRestrictionTest` asserted a row still existed after an
  expected `QueryException` in the same test method — PostgreSQL aborts the whole transaction after
  any error (unlike SQLite), so the follow-up query failed with "current transaction is aborted"
  even though the delete was correctly rejected. Both are fixed with a `DB::transaction()` wrapper
  around each delete-and-assert.

- **Important decisions or deviations:**
  1. **`--tia` forwarding (resolved, no fallback needed):** `php artisan test` does forward `--tia`
     and `--locally` through to Pest despite not declaring them — confirmed empirically (see
     Verification). No `vendor/bin/pest` fallback or separate script was needed.
  2. **How `--tia --locally` "disables itself on CI":** it does not auto-detect CI via env vars;
     `Environment::name()` only switches away from `local` when Pest is invoked with an explicit
     `--ci` flag, which nothing here passes. Instead, disablement is a side effect of the existing
     `coverage: none` on `shivammathur/setup-php`: with no PCOV/Xdebug installed, TIA always
     reports itself skipped and the full suite runs, on every CI invocation, regardless of the
     `--tia --locally` flags being present in the shared `composer test` script. Confirmed on the
     pushed branch's own CI logs (see Verification) — this is the intended shared-command design,
     not a workaround.
  3. **`composer ci:setup` (new, CI-only) vs. `composer setup` (developer onboarding, unchanged):**
     the CI leg skips `migrate --force` (redundant — `RefreshDatabase` migrates per parallel
     process, and running it up front against whichever DB engine is not yet configured for the
     matrix leg served no purpose). It still runs `pnpm install` and `pnpm run build` — an initial
     leaner version dropped `pnpm run build`, which broke both matrix legs with
     `ViteManifestNotFoundException` because `ExampleTest` renders the Inertia home route. Restored
     it after that empirical failure.
  4. **`workflow_dispatch` added to the workflow's `on:` block (not in the original design notes):**
     the existing triggers are `push` to `main` only and `pull_request`. Per instructions I could
     not open a PR, and pushing a feature branch does not trigger `push` (main-only) or
     `pull_request` (no PR exists). `workflow_dispatch` is additive, does not change trigger scope
     for `push`/`pull_request`, and was the only way to run the real workflow on the branch as the
     task's own verification method (`gh run watch`) requires. Flagging this as a deviation from
     the task's literal scope for visibility, not asking for retroactive approval to keep it — it
     is a standard, low-risk convention and I judged it in-bounds as CI-adjacent tooling, but the
     Planner/Reviewer should confirm.
  5. **`DeletionRestrictionTest` fix:** in scope because it's exactly the class of engine-specific
     bug ADR-001 expects the PostgreSQL leg to catch, it's a mechanical test-only change (no domain
     logic touched), and without it the PostgreSQL leg cannot pass — leaving it broken would make
     the task's core acceptance criterion ("both legs must pass") unsatisfiable.
  6. **TIA cache location:** normally lives at `$HOME/.pest/tia/<project-key>`, entirely outside the
     repository; it only falls back to `<project-root>/.pest/tia` when no `HOME` env var is set.
     Added `/.pest` to `.gitignore` to cover that fallback case, since `tests/.pest/` (snapshots,
     shard data) is a different, already-tracked path and must stay tracked.
  7. Local machine has no PCOV/Xdebug, so TIA replay could not be observed locally beyond the
     "skipped" message — documented as an expected, not a bug, per task instructions. Recorded the
     `pecl install pcov` prerequisite in `README.md`.

- **Verification:**
  - Local (SQLite, this machine, PHP 8.5.8): `composer ci:check` — 40/40 tests passed, Pint passed,
    PHPStan passed (0 errors, run with `--memory-limit=512M`; this machine's default 128M
    `memory_limit` is too low for PHPStan's parallel workers — a pre-existing local `php.ini`
    constraint unrelated to this task, not fixed here since it is a per-machine setting), pnpm
    `lint:check`/`format:check`/`types:check` all passed.
  - Local (PostgreSQL 17, `docker run postgres:17` on port 55432, `CREATEDB`-capable `postgres`
    superuser): `php artisan test --parallel --compact` — 37 passed, 3 correctly skipped
    (SQLite-only CHECK-constraint tests via `skipUnlessSqlite()`), including the 18-fractional-digit
    round-trip test (`PostingTest.php:11`). This surfaced the `DeletionRestrictionTest` transaction-
    abort bug live before it was fixed.
  - Real CI on the pushed branch (`ci/task-002-dual-database-pipeline`), triggered via
    `workflow_dispatch` since no PR exists:
    - Run [31276272927](https://github.com/Rosendito/agentic-money/actions/runs/31276272927) —
      **success**. `ci (sqlite)`: 40 passed (81 assertions). `ci (pgsql)`: 3 skipped, 37 passed (78
      assertions). Both legs green.
    - Demonstrated the pipeline goes red: committed a deliberate test failure
      (`tests/Unit/ExampleTest.php`, `e067783`), triggered
      run [31276365833](https://github.com/Rosendito/agentic-money/actions/runs/31276365833) —
      **failure**, both `ci (sqlite)` and `ci (pgsql)` failed with exit code 1 on "Run CI Checks".
      Reverted immediately (`34c7a16`).
    - Confirmed green again after the revert:
      run [31276435111](https://github.com/Rosendito/agentic-money/actions/runs/31276435111) —
      **success**, both legs.
    - Final confirmation on the exact commit under review (`e19f47c`, docs-only change on top):
      run [31276569207](https://github.com/Rosendito/agentic-money/actions/runs/31276569207) —
      **success**, both legs.
    - Confirmed TIA is inactive on CI directly from the run 31276435111 log: both legs print
      "Running in TIA mode, however TIA is skipped as it needs ext-pcov or Xdebug." and then run
      the full suite (40 passed / 37 passed+3 skipped).
    - Formatting violation (`class    User` spacing) and a type error (a method declared to return
      `int` while returning a string) were each demonstrated locally to fail `composer lint:check`
      / `composer types:check` with exit code 1, then reverted; not pushed to CI separately since
      the mechanism (Pint/PHPStan exit codes gating `composer test`) is identical in both
      environments and the deliberate-test-failure run already proved the pipeline gates on exit
      codes end-to-end on CI.
  - `--parallel` verified locally on both engines (SQLite and the local Docker PostgreSQL 17) and
    on both CI legs (see run logs above).

- **Commit:** `734564d` (parallel-test fix), `f411bd7` (CI defects + PostgreSQL matrix),
  `8504419` (DeletionRestrictionTest portability fix), `f31f686` (PCOV prerequisite docs),
  `a4e098d` (`workflow_dispatch`), `6de237a` (restore `pnpm run build` in `ci:setup`), `e067783` /
  `34c7a16` (deliberate-failure demonstration and revert), on branch
  `ci/task-002-dual-database-pipeline`, pushed to `origin`. Not merged; no pull request opened, per
  instructions.

### Follow-up: address reviewer finding P2 (TIA disablement on CI made explicit)

- **Summary:** Per the validator's P2 finding, `--tia --locally` alone does not disable TIA on CI —
  `Tia::isEnabledForRun()` returns `true` as soon as an explicit `--tia` argument is present, before
  its `--ci` check runs, and `--ci` would not have helped anyway. CI disablement was previously an
  incidental side effect of `coverage: none` leaving no PCOV/Xdebug driver. Made it explicit instead:
  - Extracted the shared config-clear/lint/types steps of the `test` composer script into a new
    `pretest` script, so `test` (developer path, `--tia --locally`) and `ci:check` (CI path) share
    that prefix without duplicating it.
  - `ci:check` now runs `@php artisan test --no-tia --parallel` directly, instead of calling `@test`
    (which hardcoded `--tia --locally`). `--no-tia` is Pest's own flag for forcing TIA off
    (`Tia::NO_OPTION` in `vendor/pestphp/pest/src/Plugins/Tia.php`), so TIA is off on CI by
    construction, independent of whether a coverage driver is ever added to the workflow.
  - Added a comment at the `coverage: none` line in `.github/workflows/tests.yml` noting that TIA
    disablement no longer depends on it.
  - Corrected acceptance criterion 8: unchecked the "local" half, since no machine in evidence has
    PCOV/Xdebug and local `--tia --locally` runs have only ever shown the skip message, never a real
    replay. Did not install PCOV — that is the developer's own machine and choice.
- **Verification:**
  - Local: `php artisan test --no-tia` prints no TIA output at all (grep for "tia" in its output
    returns nothing), while `php artisan test --tia --locally` still prints `"Running in TIA mode,
    however TIA is skipped as it needs ext-pcov or Xdebug."` — confirms `--no-tia` is a hard
    disable, not dependent on the coverage driver.
  - Local: `composer ci:check` — pnpm `lint:check`/`format:check`/`types:check`, Pint, PHPStan, and
    40/40 tests all pass with the new `pretest`/`ci:check` script wiring.
  - CI: pushed to `origin/ci/task-002-dual-database-pipeline` and re-ran via `workflow_dispatch`.
    Run [31277514670](https://github.com/Rosendito/agentic-money/actions/runs/31277514670) on
    commit `07ed90d` (the code fix) — **success**, `ci (sqlite)`: 40 passed (81 assertions),
    `ci (pgsql)`: 3 skipped, 37 passed (78 assertions). Grepped the full log for "Running in TIA
    mode" / "skipped as it needs ext-pcov or Xdebug" — absent on both legs, confirming `--no-tia`
    suppresses TIA entirely rather than merely reporting it skipped.
  - After the follow-up documentation commit (`dd379b4`), re-ran once more so the final green run
    covers the actual branch tip, not a prior commit: run
    [31277610955](https://github.com/Rosendito/agentic-money/actions/runs/31277610955) — headSha
    `dd379b4904c80af7d6c486c384fd10f5f63fa99c` (branch tip at push time) — **success**, both
    `ci (sqlite)` and `ci (pgsql)` legs.
  - `vendor/bin/pint --dirty --format agent` run before finalizing.
- **Commit:** `07ed90d`.

## Validation

> Filled by the validator.

- **Verdict:** changes_requested (independent review of `61c5fa4` vs `main`, 2026-08-08).

- **Findings:**
  1. **[P2] Acceptance criterion 8 is overclaimed and its CI guarantee is incidental.**
     `composer test` passes `--tia --locally`, but `--locally` does not gate an explicit `--tia`:
     in `vendor/pestphp/pest/src/Plugins/Tia.php` (`handleArguments`, ~L468-470) `$cliEnabled`
     is true whenever `--tia` is present, and the `Environment::name() === LOCAL` check applies
     only to the config-driven `$alwaysEnabled` path; `isEnabledForRun()` (~L320-332) likewise
     returns true on `--tia` before its `--ci` check. `Environment::name()` defaults to `local`
     and flips only on an explicit `--ci` flag — and even `--ci` would not disable an explicit
     `--tia`. TIA is inactive on CI today solely because `coverage: none` leaves no coverage
     driver (confirmed in run 31276569207 logs: "TIA is skipped as it needs ext-pcov or
     Xdebug"), yet nothing in `.github/workflows/tests.yml` marks that line as load-bearing.
     Enabling coverage on CI later would silently switch TIA on (record mode immediately;
     replay once any cache/baseline persistence is added), violating Pest's rule that CI runs
     the full suite. Separately, "TIA is active for local runs" was never demonstrated: no
     machine in evidence has PCOV/Xdebug, so every observed run printed the skip message.
     Resolve by making CI disablement explicit (e.g. `--no-tia` on the CI invocation — note
     `--ci` would not work — or at minimum a comment in the workflow marking `coverage: none`
     as load-bearing), and by either demonstrating local replay with a coverage driver or
     annotating criterion 8 as partially unverified instead of checked.
  2. **[P3] Push trigger remains `main`-only while criterion 1 says "every push".** The
     workflow keeps the pre-existing `push: branches: [main]` filter — the reason the executor
     needed `workflow_dispatch` at all. Standard for this repo's flow, but the planner should
     confirm the criterion's conventional reading (pushes to main + all PRs) is the intent.
  3. **[P3] The PostgreSQL service container also boots on the SQLite matrix leg** (services
     are unconditional per job). Harmless waste today; conditional services or a per-leg job
     split can wait until CI time matters.
  4. **[P3] `composer ci:setup` duplicates four lines of `composer setup`;** a future setup
     step added to one can silently miss the other. Authorized by the task's "leaner CI path"
     note; acceptable at this size.

- **Evidence:**
  - Diff surface `main...61c5fa4` inspected in full (10 files); worktree clean; delta between
    green run commit `e19f47c` and HEAD `61c5fa4` is exactly 3 added lines in this document —
    documentation-only, confirmed via `git diff e19f47c 61c5fa4`.
  - `gh run list`/`view`: run 31276569207 (`e19f47c`) success — `ci (sqlite)` 40 passed /
    81 assertions, `ci (pgsql)` 3 skipped / 37 passed / 78 assertions; red-run demonstration
    31276365833 (`e067783`) failed both legs; `git diff e067783^ 34c7a16` is empty (exact
    revert). The 3 pgsql skips are the only skip sites in the suite (grep), so the
    18-fractional-digit round-trip ran and passed on PostgreSQL.
  - The pgsql leg reporting 3 skips proves the workflow env overrode `phpunit.xml`'s
    `DB_CONNECTION=sqlite`; no `force="true"` present on any `<env>` entry.
  - Local re-verification on this machine: `php artisan test --parallel --compact` 40/40;
    `vendor/bin/pint --test` passed; `phpstan analyse` 0 errors; `pnpm run lint:check`,
    `format:check`, `types:check` all exit 0; `composer validate` valid; no `npm`/`yarn`
    invocation remains in `composer.json` or the workflow.
  - `use RuntimeException;` removal: confirmed the files are global-namespace and the
    statement emits "use statement with non-compound name ... has no effect" (reproduced with
    `php -r`); removal is a semantic no-op, `RuntimeException::class` still resolves. The
    exact ParaTest-fatal mechanism was not reproduced but is immaterial given the no-op.
  - `DB::transaction()` wrapping in `DeletionRestrictionTest`: sound and non-weakening. Inside
    RefreshDatabase's outer transaction it creates a savepoint; PostgreSQL's abort-on-error is
    contained, the `QueryException` still propagates to `toThrow`, and the follow-up
    `exists()` assertion still runs. If the `restrictOnDelete()` constraints (confirmed in
    both ledger migrations) were removed, the delete would succeed and `toThrow` would fail —
    the test still protects the invariant.
  - PostgreSQL numeric semantics verified empirically (pg16 container, stateless SELECT):
    `'1.1234567890123456789'::numeric(38,18)` returns `1.123456789012345679` (silent
    round-half-up, not rejection); `'12.34.56'::numeric(38,18)` raises invalid input syntax.
  - `workflow_dispatch`: sound. Repo is public, but dispatch requires write access, workflow
    permissions remain `contents: read`, and no secrets are exposed (service password is a
    throwaway container credential). Additive; planner should ratify keeping it.
  - TIA cache: `Storage::tempDir()` resolves under `$HOME/.pest` with a project-root `/.pest`
    fallback; `.gitignore` now covers the fallback and `tests/.pest` stays tracked.

- **Follow-ups:**
  1. **PostgreSQL precision coverage gap (pre-existing, exposed by this task).** The three
     SQLite-only tests in `tests/Feature/Domain/Ledger/PostingTest.php:83-126` have no
     PostgreSQL equivalent, and the skip message's claim that PostgreSQL "enforces this
     natively" is wrong for overscale input: `numeric(38,18)` silently rounds an
     over-18-fractional-digit value (verified above) instead of rejecting it, an unnamed
     rounding boundary in tension with ADR-001/LIF-012, and a live SQLite-vs-PostgreSQL
     behavioral divergence on the engine ADR-001 designates as authoritative. The malformed
     and non-numeric cases would already pass on pgsql (invalid input syntax) and only need
     the skip narrowed; the overscale case needs a product/kernel decision (likely an
     application-boundary guard) plus a pgsql test pinning the chosen behavior. Not blocking:
     pre-dates this branch and exceeds the CI-plumbing scope.
  2. Decide whether the `main`-only push trigger matches criterion 1's intent (finding 2) and
     whether `workflow_dispatch` stays.

## Closure

Planner resolution, 2026-08-08, on the book owner's direction:

- **Finding P2 (TIA):** resolved by the executor's follow-up (`07ed90d`) — CI now passes `--no-tia`
  explicitly, verified green on runs 31277514670 and 31277610955 with no TIA output on either leg.
  Criterion 8 was corrected to acknowledge local replay remains unverified without a coverage
  driver.
- **Finding 2 (push trigger):** ratified as `main`-only pushes plus all pull requests. That is the
  intended scope; criterion 1 was reworded to say so instead of changing the workflow.
- **`workflow_dispatch`:** ratified and kept. The validator confirmed it is additive, requires
  write access, and exposes no secrets; manual pipeline runs are useful.
- **Finding 1 (PostgreSQL over-scale precision gap):** spun off as
  [TASK-003](003-decimal-scale-boundary-enforcement.md) with the product decision recorded in
  ADR-001's 2026-08-08 amendment.
- The branch work was squash-merged to `main` as `569e9f4`. Task closed as **done**.
