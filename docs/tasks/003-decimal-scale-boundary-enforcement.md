---
id: TASK-003
title: Enforce decimal scale at the application boundary
status: ready
created_at: 2026-08-08
---

# TASK-003: Enforce decimal scale at the application boundary

## Intention

Make over-scale monetary input behave identically on every database engine, and make that behavior
a deliberate, named decision rather than an accident of the storage engine.

## Context

TASK-002 put PostgreSQL in CI precisely so engine-level precision behavior would stop being
theoretical. It immediately surfaced a divergence, confirmed empirically against a live PostgreSQL
instance during validation:

| Input (19 fractional digits) | SQLite | PostgreSQL |
| --- | --- | --- |
| `'1.1234567890123456789'` | rejected by CHECK constraint | accepted, silently stored as `1.123456789012345679` |

Malformed and non-numeric input (`'12.34.56'`, `'not-a-number'`) is rejected by both engines, so
that half of the behavior already agrees.

Two problems follow from the over-scale row:

1. **Rounding happens at an unnamed boundary.** `LIF-012` requires that quote calculation,
   functional conversion, provider precision, settlement, and display each declare their rounding
   mode and scale. A write to disk is none of those. PostgreSQL is applying round-half-even at a
   boundary nobody declared.
2. **The authoritative engine is the permissive one.** ADR-001 designates PostgreSQL/MySQL as the
   authoritative precision enforcement and SQLite's CHECK constraints as "the local safety net".
   For this case the relationship is inverted: the safety net is stricter than the authority. The
   skip message in `tests/Feature/Domain/Ledger/PostingTest.php` asserts that MySQL/PostgreSQL
   "enforce this natively through DECIMAL(38, 18)", which is true for syntax and false for scale.

The ledger currently has no application-level guard, so whichever engine is underneath decides.

## Decision

**Resolved (2026-08-08, product decision by the book owner): reject over-scale input at the
application boundary.** A monetary value carrying more than 18 fractional digits raises an error;
it is never rounded on the way to storage. Storage is not a rounding boundary.

Rationale: a 19-decimal amount reaching the ledger is a bug or a mis-scaled feed, and absorbing it
quietly is the same class of harm `LIF-011` forbids for balance tolerance. A provider that
legitimately emits more precision is handled at the *provider precision* boundary already named in
`LIF-012`, inside that adapter, with its rounding mode declared and tested.

Rounding at a new ingestion boundary was considered and rejected: it adds one more place where a
value silently changes. Leaving the divergence in place was rejected outright, since it makes
correctness depend on which engine happens to be running.

This decision is already recorded in [ADR-001](../decisions/ADR-001-decimal-precision-and-rounding.md)
(amendment of 2026-08-08). The executor implements it; it does not re-open it.

## Required reading

- [Ledger knowledge base](../README.md)
- [Domain architecture](../09-domain-architecture.md)
- [ADR-001](../decisions/ADR-001-decimal-precision-and-rounding.md)
- [Integrity and lifecycle](../07-integrity-and-lifecycle.md)
- [TASK-002](002-ci-real-database-validation.md), whose validation section records the finding

## Rules that must remain true

- ADR-001 (per-engine exact decimal representation; values cross boundaries as decimal strings or
  decimal value objects, never binary floats)
- `LIF-011` (no silent tolerance), `LIF-012` (rounding only at named boundaries with a declared mode)

## Design and hidden risks

- The guard belongs where every write passes through, so no caller can bypass it by constructing a
  model directly. Placing it only in a form request or a service method would leave factories,
  seeders, and future importers unguarded — and factories are exactly what the regression tests use.
- Do not reach for float arithmetic to count fractional digits. Scale must be determined from the
  decimal string or decimal value object, per ADR-001's prohibition on `float`/`double` for
  monetary values.
- Every monetary column that exists today is in scope — `native_quantity` and `functional_amount` —
  and a guard covering only one of them leaves the divergence alive on the other. Rate columns do not
  exist yet; the guard must be the path any future monetary or rate column also passes through, so
  quotes cannot reintroduce the divergence later.
- **The application guard is necessarily the only boundary on PostgreSQL, and that is not a
  violation of `SCP-015`.** A `DECIMAL(38, 18)` column rounds the value before any CHECK constraint
  can observe it, so no database-level rejection of over-scale input is possible there. SQLite keeps
  its CHECK constraint as a second layer. Record this explicitly so the guard is not read as relying
  on a model observer where a database constraint was available.
- **The existing driver skip is too broad.** `skipUnlessSqlite()` skips all three constraint tests
  on PostgreSQL, but two of them (malformed syntax, non-numeric) pass there unchanged. Narrow the
  skip to the over-scale case only, so PostgreSQL stops silently forfeiting coverage it already
  satisfies.
- Regression coverage must run on **both** engines and assert identical behavior. A test that only
  proves the guard works on SQLite reproduces the original defect.

## Acceptance criteria

- [ ] Over-scale monetary input is rejected with a clear error on both SQLite and PostgreSQL, and
      the two engines behave identically.
- [ ] The guard cannot be bypassed by writing through a factory, seeder, or model directly.
- [ ] Both existing monetary columns, `native_quantity` and `functional_amount`, are covered.
- [ ] The skip in `PostingTest.php` is narrowed to the over-scale case, and its message no longer
      claims PostgreSQL enforces scale natively.
- [ ] Regression tests assert the behavior on both engines and pass in both CI matrix legs.
- [ ] No new rounding boundary is introduced. `LIF-012`'s five named boundaries stay as they are;
      this task adds a rejection, not a rounding step.

## Out of scope

- The maximum permitted explicit rounding adjustment, which ADR-001 defers to the posting-kernel
  decision.
- Changing the stored precision or scale away from 38/18.
- Provider-specific precision handling in import adapters; this task fixes the ledger's own
  boundary, not any particular feed.
- MySQL. ADR-001 names it as an alternative authoritative engine, but CI runs PostgreSQL and adding
  a third matrix leg is not justified by this finding.

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
