---
id: TASK-002
title: CI pipeline validating against SQLite and PostgreSQL
status: ready
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

- [x] The workflow runs the full suite on both SQLite and PostgreSQL 17 on every push and pull
      request, and both legs must pass for the build to be green.
- [x] The 18-fractional-digit round-trip and constraint tests pass on PostgreSQL.
- [x] Tests run with `--parallel` in both legs and in the shared local command.
- [x] Static analysis, formatting, and frontend checks run in the same pipeline, using pnpm only.
- [x] A failing test, type error, or formatting violation fails the pipeline. Demonstrate this, do
      not assume it.
- [x] `composer.json` declares `"php": "^8.4"` and the lock file is consistent with it.
- [x] `package.json` pins the pnpm version, and the workflow's Node version matches the Volta pin.
- [x] TIA is active for local runs and provably inactive on CI.
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

## Validation

> Filled by the validator.

- **Verdict:** Pending.
- **Findings:** Pending.
- **Evidence:** Pending.
- **Follow-ups:** None.
