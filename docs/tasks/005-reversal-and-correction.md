---
id: TASK-005
title: Reversal, correction, and reclassification
status: blocked
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

**Gate:** this task is `blocked` until [TASK-008](008-posting-kernel-hardening.md) is done and
merged. TASK-008 changes the Posted transition, the immutability mechanism, and the ACC-006
concurrency contract; this plan's final shape was reviewed against that outcome and must be
re-checked by the planner against TASK-008's *executed* result before moving to `ready`. An
external plan review (2026-08-08, third model) raised seven findings; the decisions below resolve
them.

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
- [TASK-008](008-posting-kernel-hardening.md) — **as executed and validated**, not as planned: its
  immutability mechanism, Posted-transition validation, ACC-006 locking pattern, and time
  normalization are the contract this task builds on

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
- **Correction group without mutating the original (`LIF-005`).** The original is posted and
  immutable, so it can never be updated to join a group. Decision: **the group identifier is the
  original transaction's id.** The reversal already points at the original through
  `reverses_transaction_id`; the replacement stores `correction_group_id = original id` at
  creation. Membership of the original is implicit — it is the id — so no posted row is ever
  edited, no annotation becomes mutable, and no new table is needed. Group queries derive the trio
  from those two immutable links.
- **Correction is atomic.** Decision: `CorrectJournalTransactionAction` wraps reversal and
  replacement in **one database transaction — both post or neither does.** There is no resumable
  half-state: a failure anywhere rolls back everything, and the retry starts clean. The correction
  command carries its own idempotency key; its canonical payload embeds the original id and both
  child payloads, so a replay returns the existing pair and a mutated retry conflicts.
- **Idempotency semantics for reversal are three distinct cases (`LIF-009`):** same key + same
  canonical payload returns the existing reversal (replay, not an error); same key + different
  payload raises `IdempotencyConflict`; different key + already-reversed original raises the
  already-reversed rejection. The kernel's canonical payload must incorporate
  `reverses_transaction_id` and `correction_group_id` — today it does not, and without them a
  reversal and an ordinary transaction with identical postings would collide.
- **Reclassification (`LIF-022`, ADR-003):** `ReclassifyPostingAction` changes a posted posting's
  category with no postings created and no monetary field touched. Every change appends a row to a
  new book-scoped classification-history table (posting reference, previous category, new category,
  changed at) that nothing deletes. Setting the same category is a no-op that appends nothing.
  Cross-book categories die at the database through the same composite-FK pattern as TASK-004.
- **Reclassification is concurrency-safe.** The category update and the history append happen in
  **one database transaction with a row lock on the posting** (`lockForUpdate`), so the "previous
  category" recorded is the one that was actually replaced — two concurrent reclassifications must
  serialize, never both record the same predecessor. The command is idempotent (`LIF-008`), and a
  real two-connection concurrency test on PostgreSQL proves the serialization, following the
  pattern TASK-008 establishes for ACC-006.
- **Reversed events are not reclassifiable.** Decision: a reversal's postings, and the postings of
  a transaction that has a complete posted reversal, reject reclassification. Reclassifying one
  side of a netted pair would un-balance category reports (Rent +10 / Food −10 for an event that
  economically never happened); a wrong, reversed event is repaired by the correction flow, and
  the *replacement* is reclassifiable. Reclassifying first and reversing later stays consistent
  because the reversal mirrors the current category.
- **Category placement becomes one role-aware policy.** TASK-004 recorded that the kernel's
  type-based rule lets a `Fees`-role posting (Expense-typed) carry a category. This task
  introduces the shared, role-aware policy — category allowed only on `IncomeControl` and
  `ExpenseControl` postings — used by BOTH the kernel and `ReclassifyPostingAction`, closing that
  residual rather than copying the gap into a second site.
- **The sanctioned `category_id` mutation is a narrow, designed exception.** The posted-posting
  immutability mechanism (as hardened by TASK-008, event-independent) must expose exactly one
  authorized path: a change whose only dirty attribute is `category_id`, performed by the action.
  Any write that also touches amounts, account, or parent transaction is rejected as before. The
  classification-history model is protected by the SAME event-independent mechanism — quiet
  writes and `withoutEvents` must not be able to edit or delete history rows.
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
- [ ] Draft and cancelled transactions cannot be reversed. Reversal idempotency distinguishes the
      three cases: same key + same payload returns the existing reversal; same key + different
      payload conflicts; different key + already-reversed original is rejected — including under
      the concurrent race via the database constraint, on both engines (tests).
- [ ] A reversal is itself immutable and cannot be reversed (tests).
- [ ] Correction flow is atomic: reversal and replacement post in one database transaction, both
      linked to the original through `reverses_transaction_id` and
      `correction_group_id = original id` with no posted row edited; a failure at any point leaves
      nothing; replaying the correction command returns the existing pair (tests).
- [ ] Reversing an income that was already spent drives the asset's native balance negative and is
      not blocked by the overdraft default (test).
- [ ] Reversal postings carry the mirrored posting's category, and category spending nets to zero
      across original plus reversal (test).
- [ ] Reclassifying a posted posting creates no postings, changes no balance, appends a history
      row retaining the previous category, and the history rejects update and delete through the
      event-independent mechanism (including `saveQuietly`/`deleteQuietly`/`withoutEvents`);
      reclassifying to the same category appends nothing (tests, per ADR-003's validation notes).
- [ ] Two concurrent reclassifications of the same posting serialize: the final category and the
      history chain agree, proven by a two-connection test on PostgreSQL (test).
- [ ] Postings of a reversal, or of a transaction with a complete posted reversal, reject
      reclassification; a `Fees`-role posting rejects a category through the shared role-aware
      policy in both the kernel and the reclassification action (tests).
- [ ] The sanctioned mutation path accepts a change whose only dirty attribute is `category_id`
      and rejects any write that also touches monetary fields, account, or parent (tests).
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
