---
id: TASK-007
title: Enforce total decimal precision at the application boundary
status: ready
created_at: 2026-08-08
---

# TASK-007: Enforce total decimal precision at the application boundary

## Intention

Close the remaining axis of engine-dependent monetary input: total precision. TASK-003 made the
application boundary the sole authority on fractional scale; this task does the same for the
integer part, so a value that PostgreSQL's `DECIMAL(38, 18)` cannot hold is rejected identically
on every engine instead of being stored by SQLite and rejected by PostgreSQL.

## Context

TASK-003's third validation round found and empirically confirmed the divergence (see its
Validation follow-up 1): with scale fixed at 18, `DECIMAL(38, 18)` leaves 20 integer digits.
Values with 19 and 20 integer digits are accepted by both engines; a 21-integer-digit value is
stored by SQLite (its CHECK constraint never limited integer digits) and rejected by PostgreSQL.
This is the same class of defect TASK-003 closed for scale, one axis over, and
`App\Domain\Money\ValueObjects\MonetaryDecimal` is now the natural single home for the guard.

The practical exposure is low — 20 integer digits is around 100 quintillion units — but the rule
at stake is not about likelihood: correctness must not depend on which engine is underneath, and
storage must reject what it cannot represent rather than let one engine absorb it.

## Required reading

- [Ledger knowledge base](../README.md)
- [Domain architecture](../09-domain-architecture.md)
- [ADR-001](../decisions/ADR-001-decimal-precision-and-rounding.md) (as amended)
- [Integrity and lifecycle](../07-integrity-and-lifecycle.md) — `LIF-011`, `LIF-012`
- [TASK-003](003-decimal-scale-boundary-enforcement.md), whose Validation section records the
  finding and the empirical 20-versus-21-digit evidence

## Rules that must remain true

- ADR-001 as amended (reject at the boundary, never round or truncate on the way to storage)
- `LIF-011` (no silent tolerance), `LIF-012` (no unnamed rounding or truncation boundary)

## Design and hidden risks

- The guard belongs in `MonetaryDecimal::fromString()`, next to the scale check, so every path
  that already passes through the value object and the `MonetaryScale` cast inherits it. Do not
  add a second validation site.
- Measure integer digits on the canonical form (after sign and leading-zero normalization), not
  on the raw literal: `'0000000000000000000001.5'` is one integer digit, not twenty-two.
- Reject when the integer part exceeds 20 digits (38 total minus 18 scale). Follow the existing
  exception style — either extend `ExcessiveDecimalScale` semantics or add a sibling exception;
  keep the message as precise as the existing ones.
- SQLite's CHECK constraint may optionally gain the matching integer-digit bound for
  defense-in-depth, but per the amended ADR-001 the application boundary is the authority; do not
  treat the CHECK as the fix.

## Acceptance criteria

- [ ] A value with 20 integer digits (canonical form) is accepted; 21 raises the boundary
      exception, identically on SQLite and PostgreSQL, proven by tests at both the value-object
      and Posting-model level.
- [ ] Leading zeros do not count toward the limit (test).
- [ ] The existing scale behavior is unchanged (existing suite still green on both engines).
- [ ] `php artisan test --compact` (SQLite), `composer test:pgsql` (compose service),
      `vendor/bin/pint --dirty --format agent`, and `composer run types:check` pass.

## Out of scope

- Any change to scale-18 semantics or normalization from TASK-003.
- Rounding, truncation, or configurable precision limits.
- Quotes, valuation, or any posting-engine behavior (TASK-004 owns that slice).

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
