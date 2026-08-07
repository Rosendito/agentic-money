---
id: TASK-002
title: CI pipeline validating against a real decimal database
status: draft
created_at: 2026-08-07
---

# TASK-002: CI pipeline validating against a real decimal database

## Intention

Run the full test suite in CI against PostgreSQL or MySQL so the ledger schema is continuously
validated by an engine with true `DECIMAL(38, 18)` enforcement. Local development and parallel
tests stay on zero-configuration SQLite; CI becomes the authoritative validator for precision,
range, scale, and constraint behavior that SQLite cannot enforce.

## Context

ADR-001 (as amended) stores monetary values as CHECK-constrained TEXT-affinity columns on SQLite
because its NUMERIC affinity coerces decimals to floats. That amendment made CI against a real
decimal engine the authoritative enforcement layer and deferred it to this task.

## Required reading

- [Ledger knowledge base](../README.md)
- [Domain architecture](../09-domain-architecture.md)
- [ADR-001](../decisions/ADR-001-decimal-precision-and-rounding.md)

## Rules that must remain true

- ADR-001 (per-engine exact decimal representation)
- `LIF-011`, `LIF-012`

## Design and hidden risks

- Pick one engine (PostgreSQL preferred) and pin its version; the migrations already branch per
  driver, so the suite must pass unmodified.
- SQLite-only behavior (CHECK-constraint error messages, driver branches in migrations) may need
  driver-aware test assertions; keep those branches minimal and explicit.
- The pipeline must fail loudly on `php artisan test`, `vendor/bin/pint --test`, and
  `composer run types:check`.

## Acceptance criteria

- [ ] CI workflow runs migrations and the full test suite against the chosen engine on every push
      and pull request.
- [ ] The 18-fractional-digit round-trip and constraint tests pass on that engine.
- [ ] Static analysis and formatting checks run in the same pipeline.
- [ ] A failing test, type error, or formatting violation fails the pipeline.

## Out of scope

- Changing the local development or parallel-test database away from SQLite.
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
