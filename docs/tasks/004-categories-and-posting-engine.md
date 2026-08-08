---
id: TASK-004
title: Categories and the posting engine
status: ready
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

This task starts once TASK-003 is merged: the posting engine builds on its `MonetaryDecimal` value
object and the boundary guard it attached to the monetary columns.

## Required reading

- [Ledger knowledge base](../README.md)
- [Domain architecture](../09-domain-architecture.md) — especially Actions, events, `ARC-012`,
  `ARC-013`
- [Ledger model](../03-ledger-model.md) — complete
- [Accounts and obligations](../05-accounts-and-obligations.md) — `ACC-001` through `ACC-008`
- [Transaction scenarios](../06-transaction-scenarios.md) — `SCN-OPEN-001`, `SCN-INC-001`, and the
  expense shape of `SCN-EXP-001` (its VES valuation and cost basis are later slices)
- [Integrity and lifecycle](../07-integrity-and-lifecycle.md) — complete
- [ADR-001](../decisions/ADR-001-decimal-precision-and-rounding.md) (as amended)
- [ADR-002](../decisions/ADR-002-functional-instrument-immutability.md)
- [ADR-003](../decisions/ADR-003-expense-classification-boundary.md)
- [ADR-005](../decisions/ADR-005-usd-representations-as-distinct-instruments.md) — seeded
  instruments change
- [TASK-001](001-design-ledger-schema.md) — what the schema already enforces and what it left to
  this task
- [TASK-003](003-decimal-scale-boundary-enforcement.md) — the `MonetaryDecimal` value object and
  `MonetaryScale` cast this task builds on

## Rules that must remain true

- `LED-001` through `LED-010`, `LED-015`, `LED-016`
- `ACC-006` (no accidental negative assets), `ACC-007` (system accounts book-owned, controlled
  creation), `ACC-008` (actions derive counterpart postings)
- `LIF-001`, `LIF-002` (atomic posting), `LIF-003` (posted data immutable — this task finishes the
  guards TASK-001 deferred), `LIF-008`/`LIF-009` (idempotency semantics), `LIF-011` (exact balance,
  no tolerance), `LIF-016` (book isolation, including categories), `LIF-017`, `LIF-018`
- `ARC-001`, `ARC-003`, `ARC-012` (invariants inside the action transaction, never in listeners),
  `ARC-013` (events dispatch after commit)
- ADR-001 as amended (decimal strings/value objects only; over-scale input rejected at the
  boundary; exact balance at storage scale)
- ADR-002 (functional instrument immutable once posted)
- ADR-003 (categories are book-scoped dimensions on the relevant posting only)

## Design and hidden risks

- **One posting kernel.** A single `PostJournalTransactionAction` is the only code path that writes
  journal transactions and postings (`LED-015`, `LIF-018`). Intent-level actions — register opening
  balance, register income, register expense — validate intent, select the system counterpart
  account (`ACC-008`), and call the kernel. Nothing else touches the tables, including the book
  bootstrap.
- **Book bootstrap belongs here** (per the roadmap): a controlled action creates the book with its
  functional instrument and one system account per `SystemAccountRole` (`ACC-007`, `LIF-017`), with
  uniqueness of one account per role per book. No posting can happen before bootstrap.
- **Functional amounts are caller-supplied in this slice.** There are no quotes yet (TASK-006).
  Design the command DTOs so a valuation policy can later populate functional amounts without
  changing the kernel's contract. Do not build any quote lookup now.
- **Balance verification is decimal-string arithmetic.** Sum functional amounts with BCMath (or the
  `MonetaryDecimal` value object) at scale 18 and require an exact zero (`LIF-011`). Never float
  math, never an epsilon. Watch normalization traps: `-0`, `0.10` vs `0.1`, and sign handling when
  comparing the sum to zero.
- **Sign and account-type relationships** follow the `LED-008` table. The kernel rejects a posting
  whose sign contradicts the intent action's declared shape; it does not silently flip signs.
- **Idempotency (`LIF-009`) needs a canonical payload.** Same key plus identical canonical payload
  returns the existing transaction; same key with a materially different payload is a conflict
  error. Define the canonical form deliberately (stable field order, normalized decimal strings) and
  store what is needed to compare. Concurrent duplicates are settled by the existing
  `UNIQUE (book_id, idempotency_key)` constraint — handle the constraint violation as the
  idempotent path, not as an unexpected crash.
- **Posted immutability guards (`LIF-003`) are completed here.** TASK-001 shipped instrument/type
  guards; this task adds model-level guards so a posted journal transaction's financial fields and
  its postings reject updates and deletes through Eloquent. The reversal path (TASK-005) is the only
  sanctioned correction and does not exist yet — that is acceptable; being unable to change posted
  data is the point.
- **Categories per ADR-003:** a book-scoped `categories` table owned by Ledger, an optional
  `category_id` on postings with a composite `(category_id, book_id)` foreign key so cross-book
  references die at the database (`LIF-016`), and category applied only to the income or expense
  posting of a transaction — never to fees or future FX postings. The TASK-001 migrations have
  never run in a real environment, so they are rewritten in place per ADR-003 and the
  migration-workflow rules, not extended with follow-up migrations. New monetary columns are none;
  `category_id` is not monetary.
- **Classification history is TASK-005.** Initial categorization at posting time does not write a
  history row; the history table and the reclassification use case arrive with TASK-005. Keep the
  category reference shape compatible with appending that history later.
- **Events after commit (`ARC-013`).** `JournalTransactionPosted` is dispatched only after a
  successful commit (Laravel's after-commit dispatch contract). No listener is part of this task; no
  invariant may depend on one (`ARC-012`).
- **`ACC-006` default policy:** the expense action rejects spending that would push an asset's
  native balance negative, computed from posted postings (`LED-011`, no balance columns). Design the
  check so a future explicit override flag can exist; do not add the flag yet.
- **ADR-005 seed update:** replace the seeded `USD` instrument with `USD.CASH` and `USD.BCV` in
  `InstrumentSeeder` and any factory defaults that reference it. The seeder has never run against
  real data, so in-place replacement is correct.

## Acceptance criteria

- [ ] A book bootstrap action creates a book with its functional instrument and exactly one system
      account per role; a second bootstrap of the same book does not duplicate them (test).
- [ ] `SCN-OPEN-001`: an opening balance posts asset against opening equity, increases the native
      balance, and reports no income (test).
- [ ] `SCN-INC-001`: an income posts asset against income control and carries its category on the
      income posting (test).
- [ ] A functional-instrument expense posts expense control against the asset, and when the
      transaction includes a fee posting, only the expense posting carries the category (ADR-003
      validation note, test).
- [ ] The kernel rejects: fewer than two postings, a functional sum that is not exactly zero at
      scale 18, a posting violating the `LED-005` zero policy, a sign contradicting the declared
      shape, and an account or category from another book (tests).
- [ ] Reusing an idempotency key with the identical canonical payload returns the existing
      transaction without a duplicate; reusing it with a different payload raises a conflict
      (tests).
- [ ] Posting is atomic: a validation or persistence failure after partial work leaves no header,
      postings, or category rows behind (test).
- [ ] A posted transaction's financial fields and postings reject Eloquent updates and deletes
      (`LIF-003` guards, tests).
- [ ] An expense that would push the asset's native balance negative is rejected by default
      (`ACC-006`, test).
- [ ] `JournalTransactionPosted` dispatches only after commit; a rolled-back posting dispatches
      nothing (test).
- [ ] The seeded instruments are USDT, USDC, VES, EUR, `USD.CASH`, and `USD.BCV` (ADR-005).
- [ ] `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, and
      `composer run types:check` pass.

## Out of scope

- Cross-instrument exchanges, realized FX results, and cost-basis tracking.
- Quotes and valuation policies (functional amounts are caller-supplied in this slice).
- Reversals, corrections, reclassification, and the classification-history table (TASK-005).
- Same-instrument transfers between own accounts (a later slice once the kernel exists).
- Draft and pending transaction workflows; this slice posts directly.
- Obligations and counterparties.
- Satellite classification axes (beneficiary and similar, per ADR-003).
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
