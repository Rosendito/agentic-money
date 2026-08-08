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

- [ ] The workflow runs the full suite on both SQLite and PostgreSQL 17 on every push and pull
      request, and both legs must pass for the build to be green.
- [ ] The 18-fractional-digit round-trip and constraint tests pass on PostgreSQL.
- [ ] Tests run with `--parallel` in both legs and in the shared local command.
- [ ] Static analysis, formatting, and frontend checks run in the same pipeline, using pnpm only.
- [ ] A failing test, type error, or formatting violation fails the pipeline. Demonstrate this, do
      not assume it.
- [ ] `composer.json` declares `"php": "^8.4"` and the lock file is consistent with it.
- [ ] `package.json` pins the pnpm version, and the workflow's Node version matches the Volta pin.
- [ ] TIA is active for local runs and provably inactive on CI.
- [ ] Developer setup notes record the PCOV prerequisite.

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

- **Summary:** Pending.
- **Important decisions or deviations:** None.
- **Verification:** Pending.
- **Commit:** Pending.

## Validation

> Filled by the validator.

- **Verdict:** Pending.
- **Findings:** Pending.
- **Evidence:** Pending.
- **Follow-ups:** None.
