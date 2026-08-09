---
id: TASK-008
title: Harden the posting kernel against external-review findings
status: ready
rigor: strict
created_at: 2026-08-08
---

# TASK-008: Harden the posting kernel against external-review findings

## Intention

Close the defects a third, external review found in the merged posting engine before any further
slice builds on it. Each finding below is a claim with a concrete scenario: the executor first
reproduces it (on the engines cited), then fixes the confirmed ones; a finding that does not
reproduce is refuted with evidence in the execution record, not silently skipped.

## Context

TASK-004 shipped after two strict validation rounds. A subsequent independent external review
(third reviewer, different model) reported five P1 findings and one P2 against the merged code,
each with file/line references and reproduction claims. TASK-005 (reversal) is planned and ready
but must not start until the kernel these findings target is sound.

## Findings to reproduce and fix

1. **[P1] Draft→Posted transition bypasses kernel validation.** An ordinary
   `$transaction->update(['status' => Posted])` on a Draft with one unbalanced posting succeeds:
   the guard permits the transition without validating posting count or balance
   (`JournalTransaction.php` guard; `PostedDataImmutabilityTest` even proves an empty Draft can
   transition). Fix direction: the Posted transition must atomically validate persisted postings
   (count ≥ 2, exact functional balance) or be impossible outside the kernel.
2. **[P1] Reparenting escapes immutability.** Updating a posted posting's
   `journal_transaction_id` to point at a Draft passes the guard, which checks the dirty target
   instead of the persisted parent; the original transaction is left Posted and unbalanced. Fix:
   guards must evaluate the persisted parent (original attribute values) and reject reparenting
   away from posted history.
3. **[P1] Quiet Eloquent writes disable the guards.** `saveQuietly()`, `createQuietly()`, and
   `Model::withoutEvents()` suppress model events entirely, so a posted posting's amount can be
   rewritten. This is distinct from the documented mass-write/raw-SQL boundary: quiet APIs are
   ordinary Eloquent single-model persistence. Fix direction: enforce the posted-immutability
   invariants through an event-independent mechanism (for example overriding the model's low-level
   `perform*`/save pipeline), not only through model events; keep the documented raw-SQL boundary
   as is.
4. **[P1] ACC-006 is a check-then-post race.** Two concurrent expenses of 80 against a balance of
   100 both read 100 before the kernel transaction opens, both project 20, both post; final
   balance −60 (reproduced on PostgreSQL). Fix direction: the balance decision and the posting
   must share one database transaction with row-level concurrency control on the account (for
   example `lockForUpdate` inside the kernel transaction). Prove it with a real two-connection
   concurrency test on PostgreSQL; document why SQLite (single-writer) cannot exhibit the race.
5. **[P1] Non-UTC effective times store the wrong instant and break idempotent replay.** A
   command carrying `America/Caracas` wall time persists the timezone-less wall time, hydrates as
   UTC (four hours off), and an identical retry raises `IdempotencyConflict` because canonical
   input and persisted data no longer agree. Fix: normalize effective (and any caller-supplied)
   times to UTC before persistence and before canonicalization; test a non-UTC submission
   round-trip and its identical replay.
6. **[P2] Intent actions accept invalid payment accounts.** A system account (OpeningEquity) or a
   wrong-instrument asset passes as `assetAccountId`: income posted against equity with no asset
   posting; a VES asset posted `+7 VES / +7 USDT` by a functional-instrument action. Fix: each
   intent action rejects wrong account type, system-role accounts, archived accounts, and
   instrument mismatches before posting, with dedicated exceptions.

## Required reading

- [TASK-004](004-categories-and-posting-engine.md) — the implementation under repair, both prior
  validation rounds, and the external-review addendum
- [Ledger model](../03-ledger-model.md) — `LED-001`, `LED-015`, validation responsibilities
- [Integrity and lifecycle](../07-integrity-and-lifecycle.md) — `LIF-001`, `LIF-002`, `LIF-003`,
  `LIF-013` (effective vs recorded time)
- [Accounts and obligations](../05-accounts-and-obligations.md) — `ACC-006`
- `.ai/rules/index.md` and matching rule files, including `.ai/rules/tests.md`

## Rules that must remain true

- `LED-001`, `LED-015`, `LIF-001`, `LIF-002`, `LIF-003`, `LIF-013`, `ACC-006`
- ADR-001 (no floats; decimal strings/value objects)
- Every fix keeps the kernel as the single write path; no fix may weaken an existing guard or test

## Design and hidden risks

- Reproduce first, on the engine each finding cites. A finding that cannot be reproduced after
  genuine effort is recorded as refuted with the probe evidence.
- Finding 3's fix must not break the kernel's own legitimate persistence path — the kernel itself
  writes postings and flips status; whatever low-level enforcement is chosen must recognize the
  sanctioned transition (finding 1's fix and finding 3's fix likely share a mechanism).
- Finding 4's concurrency test needs two real database connections; `RefreshDatabase` wraps tests
  in a transaction that can mask locking behavior — verify the test observes real lock waits on
  PostgreSQL.
- Finding 5 touches idempotency canonicalization: changing time normalization must not break
  replay of transactions already posted in tests, and the canonical form must remain stable.

## Acceptance criteria

- [ ] Each of the six findings is either reproduced-then-fixed with a regression test proving the
      exact scenario, or refuted with recorded probe evidence.
- [ ] The Draft→Posted transition cannot produce a posted transaction violating `LED-001`, from
      any Eloquent path (tests).
- [ ] Posted postings reject reparenting and quiet-write mutation (tests covering `saveQuietly`,
      `createQuietly`, `withoutEvents`).
- [ ] The concurrent double-spend scenario is impossible on PostgreSQL, proven by a
      two-connection test.
- [ ] A non-UTC effective time round-trips to the correct instant and replays idempotently
      (tests).
- [ ] Intent actions reject invalid payment accounts (type, system role, archived, instrument)
      with tests per case.
- [ ] Full suite green on SQLite and PostgreSQL 17, pint clean, phpstan clean.

## Out of scope

- TASK-005 (reversal) and TASK-007 (total precision) — separate tasks.
- The documented mass-write/raw-SQL boundary (unchanged).
- Draft/pending workflows beyond what finding 1's fix requires.

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
