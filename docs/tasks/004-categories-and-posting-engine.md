---
id: TASK-004
title: Categories and the posting engine
status: draft
created_at: 2026-08-08
---

# TASK-004: Categories and the posting engine

## Intention

Make the ledger able to record an economic event. After this task the application can register an
opening balance, an income, and an expense as balanced journal transactions, classify income and
expense postings by category, and refuse anything that would violate a ledger invariant. This is the
first slice that turns the schema into a working product.

## Context

TASK-001 created the schema, models, and factories, but no application code writes to them. Nothing
can register a transaction today, and the schema has no category table because
[ADR-003](../decisions/ADR-003-expense-classification-boundary.md) was decided afterwards.

Every later slice — reversals, exchanges, obligations, imports, reports — publishes through this
posting boundary, so its guarantees are the guarantees of the whole ledger.

## Required reading

> Filled when the task is planned.

## Rules that must remain true

> Filled when the task is planned.

## Design and hidden risks

> Filled when the task is planned.

## Acceptance criteria

> Filled when the task is planned.

## Out of scope

- Cross-instrument exchanges, realized FX results, and cost-basis tracking.
- Quotes and valuation policies.
- Reversals and corrections.
- Obligations.
- HTTP, Inertia, or dashboard delivery.

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
