---
id: TASK-005
title: Reversal, correction, and reclassification
status: draft
created_at: 2026-08-08
---

# TASK-005: Reversal, correction, and reclassification

## Intention

Give the ledger a safe way to be wrong. After this task a posted transaction can be reversed, a
mistaken event can be corrected by reversal plus replacement without ever editing posted data, and a
miscategorized posting can be reclassified without touching accounting facts. Balances and reports
must stay correct through all three operations.

Reversal exists for wrong economic facts: the amount, the date, the account, the instrument.
Reclassification exists for wrong labels. Keeping the two paths separate is what stops the
accounting correction mechanism from being used to fix a typo, and stops a label change from ever
altering a balance.

## Context

TASK-004 can register transactions but not undo them, so the first mistake would be unfixable without
destructive editing. The ledger already forbids destructive deletion, so the corrective path has to
exist before real data accumulates.

[ADR-003](../decisions/ADR-003-expense-classification-boundary.md) decided that reclassification is
not a reversal and requires a history that is never deleted.

## Required reading

> Filled when the task is planned.

## Rules that must remain true

> Filled when the task is planned.

## Design and hidden risks

> Filled when the task is planned.

## Acceptance criteria

> Filled when the task is planned.

## Out of scope

- Cost-basis recalculation triggered by backdated acquisitions.
- Cross-instrument exchanges and realized FX results.
- User interface for choosing what to reverse.

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
