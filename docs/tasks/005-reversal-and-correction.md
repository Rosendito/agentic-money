---
id: TASK-005
title: Reversal, correction, and reclassification
status: ready
rigor: strict
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

- [Ledger knowledge base](../README.md)
- [Domain architecture](../09-domain-architecture.md)
- [Ledger model](../03-ledger-model.md) — `LED-014`, validation responsibilities
- [Transaction scenarios](../06-transaction-scenarios.md) — `SCN-REV-001`; read `SCN-REF-001` to
  understand what a reversal is *not* (a refund is an ordinary economic event, out of scope here)
- [Integrity and lifecycle](../07-integrity-and-lifecycle.md) — `LIF-003`, `LIF-004`, `LIF-005`,
  `LIF-022`
- [Reporting semantics](../08-reporting-semantics.md) — reversal-safe reporting expectations
- [ADR-003](../decisions/ADR-003-expense-classification-boundary.md) — reclassification and the
  classification history
- [TASK-004](004-categories-and-posting-engine.md) — the kernel this task publishes through,
  including both validation rounds (the Draft→Posted sequencing and the guard behavior matter here)

## Rules that must remain true

- `LED-001`, `LED-014` (reversal is a new event; queries never omit the original), `LED-015` (the
  reversal publishes through the same kernel)
- `LIF-003` (nothing about the original changes), `LIF-004` (reversal shape and immutability),
  `LIF-005` (correction is reversal plus replacement in a correction group), `LIF-008`/`LIF-009`
  (idempotency for the new commands), `LIF-016` (book isolation)
- `LIF-022` and ADR-003 (reclassification creates no postings and appends to a never-deleted
  history)
- `ARC-012`/`ARC-013` (invariants in the action; events after commit)

## Design and hidden risks

- **Reversal publishes through the kernel.** `ReverseJournalTransactionAction` loads the posted
  original, builds the exact mirror (same accounts and instruments, negated native quantities and
  functional amounts per `SCN-REV-001`), and calls `PostJournalTransactionAction`. The mirror of a
  balanced transaction balances by construction, but do not skip the kernel's checks on that
  argument — they are the guarantee, not the redundancy.
- **What is reversible:** only `Posted` transactions; a transaction with a complete posted reversal
  cannot be reversed again; a reversal itself cannot be reversed in this slice (a mistaken reversal
  is corrected by posting a replacement equal to the original, through the correction flow).
  `Reversed` remains a derived condition, never a stored status.
- **Double-reversal race:** two concurrent reversals of the same original must not both post.
  Enforce with a partial unique index on `reverses_transaction_id` (where not null), mirroring how
  TASK-004 handled system-account uniqueness; the action treats the constraint violation as the
  already-reversed case.
- **ACC-006 does not apply to reversals.** Reversing an income after the balance was spent must
  drive the asset negative — that is the true state, and `VAL-010`/`RPT-024` already require
  negative balances to be representable and surfaced. Build the exemption into the reversal path
  deliberately and test it; do not weaken the default for the ordinary intent actions.
- **Categories mirror.** Each reversal posting carries the same category as the posting it mirrors,
  so category spending reports show gross and reversal and net to zero, consistent with
  `SCN-REF-001`'s reporting expectation. The kernel's category-placement rule already permits this
  (same account types as the original).
- **Reversal metadata:** own effective and recorded times plus an explicit reason (`LIF-004`); the
  reason lives in the transaction description. Backdating a reversal's effective time follows the
  same rules as any posting; no cost-basis recalculation exists yet (out of scope).
- **Correction group (`LIF-005`):** correcting means reverse plus post a replacement, all linked
  through the correction-group column TASK-001 shipped. The replacement is an ordinary command with
  its own idempotency key (`SCN-REV-001`). Decide the group's shape simply: original, reversal, and
  replacement share one group identifier; a `CorrectJournalTransactionAction` may orchestrate both
  steps in one database transaction, but each posted transaction stays a complete, individually
  valid event.
- **Reclassification (`LIF-022`, ADR-003):** `ReclassifyPostingAction` changes a posted posting's
  category with no postings created and no monetary field touched. Every change appends a row to a
  new book-scoped classification-history table (posting reference, previous category, new category,
  changed at) that nothing deletes — guard the model against update/delete like the ledger tables,
  covering all three verbs (`creating` is legitimate only through the action). Setting the same
  category is a no-op that appends nothing. Cross-book categories die at the database through the
  same composite-FK pattern as TASK-004. Only postings whose account type admits a category
  (Income/Expense) are reclassifiable — reuse the kernel's rule, not a copy of it.
- **Initial classification writes no history.** The history starts at the first reclassification,
  whose "previous" value is the category assigned at posting time (which may be null). Reports read
  the posting's current category; the history exists for audit and point-in-time reconstruction.
- **Events:** `JournalTransactionReversed`, dispatched after commit like `JournalTransactionPosted`.
  No listener ships in this task. Reclassification dispatches no event until a consumer exists
  (`ARC-002` spirit — no speculative machinery).
- **Migrations:** the classification-history table is new; the partial unique index amends an
  existing table. Follow `.ai/rules/migrations.md` for whether to rewrite in place or add — the
  project status is still MVP and nothing has run in a real environment.

## Acceptance criteria

- [ ] `SCN-REV-001`: reversing a posted transaction creates a posted mirror (same accounts,
      negated native and functional amounts, link to the original, reason recorded), the combined
      financial effect is zero, and both transactions remain visible to balance derivation
      (`LED-014`) (tests).
- [ ] Draft and cancelled transactions cannot be reversed; a second reversal of the same original
      is rejected, including under the concurrent race via the database constraint, on both
      engines (tests).
- [ ] A reversal is itself immutable and cannot be reversed (tests).
- [ ] Correction flow: reverse plus replacement share a correction group with the original; the
      replacement uses its own idempotency key; a failure between the two steps leaves either
      nothing or a complete reversal, never a half-linked state (tests).
- [ ] Reversing an income that was already spent drives the asset's native balance negative and is
      not blocked by the overdraft default (test).
- [ ] Reversal postings carry the mirrored posting's category, and category spending nets to zero
      across original plus reversal (test).
- [ ] Reclassifying a posted posting creates no postings, changes no balance, appends a history
      row retaining the previous category, and the history rejects update and delete; reclassifying
      to the same category appends nothing (tests, per ADR-003's validation notes).
- [ ] A category from another book is rejected at the database for both the posting reference and
      the history row (tests, both engines).
- [ ] `JournalTransactionReversed` dispatches only after commit; a rolled-back reversal dispatches
      nothing (test).
- [ ] Full suite green on SQLite and PostgreSQL 17 (compose service), `vendor/bin/pint --dirty
      --format agent` clean, `composer run types:check` clean.

## Out of scope

- Cost-basis recalculation triggered by backdated acquisitions (exchange slice, ADR-004).
- Cross-instrument exchanges and realized FX results.
- Refunds as economic events (`SCN-REF-001` posts through ordinary actions when that slice needs
  it; a refund is not a reversal).
- Reversing a reversal.
- Partial reversals (a reversal always mirrors the complete transaction).
- Reclassification events, listeners, or read models beyond what the tests require.
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
