---
id: TASK-008
title: Harden the posting kernel against external-review findings
status: done
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

- [x] Each of the six findings is either reproduced-then-fixed with a regression test proving the
      exact scenario, or refuted with recorded probe evidence.
- [x] The Draft→Posted transition cannot produce a posted transaction violating `LED-001`, from
      any Eloquent path (tests).
- [x] Posted postings reject reparenting and quiet-write mutation (tests covering `saveQuietly`,
      `createQuietly`, `withoutEvents`).
- [x] The concurrent double-spend scenario is impossible on PostgreSQL, proven by a
      two-connection test.
- [x] A non-UTC effective time round-trips to the correct instant and replays idempotently
      (tests).
- [x] Intent actions reject invalid payment accounts (type, system role, archived, instrument)
      with tests per case.
- [x] Full suite green on SQLite and PostgreSQL 17, pint clean, phpstan clean.

## Out of scope

- TASK-005 (reversal) and TASK-007 (total precision) — separate tasks.
- The documented mass-write/raw-SQL boundary (unchanged).
- Draft/pending workflows beyond what finding 1's fix requires.

## Execution

> Filled by the executor.

- **Summary:** All six findings were reproduced against the merged code before any fix, then
  repaired. Findings 1 and 3 share the mechanism the task doc predicted; finding 2's fix is a
  one-line change to what a guard reads; finding 4 required a locking addition to the kernel;
  finding 5 is a one-line UTC normalization; finding 6 is a new shared trait.

  1. **[P1, reproduced, fixed] Draft→Posted transition bypassed kernel validation.**
     *Reproduction:* `JournalTransaction::factory()->draft()->create()` (zero postings) then
     `$transaction->update(['status' => Posted])` succeeded — proven by temporarily reverting the
     fix and re-running the new regression tests (see Verification); an empty Draft, a Draft with
     one posting, and a Draft whose postings do not sum to zero all transitioned successfully
     before the fix.
     *Fix:* `App\Domain\Ledger\Models\JournalTransaction` overrides Eloquent's low-level
     `performInsert()`/`performUpdate()`/`performDeleteOnModel()` instead of `booted()` event
     hooks (this is also finding 3's mechanism). `performUpdate()` reads the transaction's
     currently *persisted* status straight from the database; when the update is a transition into
     `Posted`, it additionally re-queries this transaction's persisted postings and requires at
     least two, summing to exactly zero at scale 18 (LED-001, LIF-011), throwing the same
     `InsufficientPostings`/`JournalTransactionIsUnbalanced` exceptions the kernel already used.
     `performInsert()` unconditionally rejects creating a row with `status = Posted` directly (no
     posting can exist yet for a transaction with no id, so it can never satisfy LED-001). The
     kernel's own legitimate Draft→Posted flip is unaffected: its postings are already persisted,
     in the same database transaction, before the status update runs.
     *Regression tests:* `tests/Feature/Domain/Ledger/PostedDataImmutabilityTest.php` — "an empty
     draft transaction cannot transition to posted", "a draft transaction with one unbalanced
     posting cannot transition to posted", "a draft transaction whose postings do not sum to zero
     cannot transition to posted", "a journal transaction cannot be created directly as posted,
     bypassing the kernel", plus the adapted "a draft transaction with two balanced postings can
     transition to posted" (the pre-existing test with this exact name proved the vulnerable
     behavior with zero postings; adapted per the task's no-silent-weakening rule, not deleted).

  2. **[P1, reproduced, fixed] Reparenting escaped immutability.**
     *Reproduction:* on a posted posting, `$posting->update(['journal_transaction_id' =>
     $draftTransaction->id])` succeeded — the guard queried the transaction identified by the
     *dirty* (new) `journal_transaction_id`, found it Draft, and allowed the write — proven by
     reverting the fix and re-running the new regression test.
     *Fix:* `App\Domain\Ledger\Models\Posting::performUpdate()`/`performDeleteOnModel()` now check
     `self::transactionIsPosted($this->originalParentId())`, where `originalParentId()` reads
     `getOriginal('journal_transaction_id')` — the posting's *persisted* parent — never the dirty
     in-memory value. Reparenting a posting away from a posted transaction is rejected regardless
     of what the new target's own status is.
     *Regression test:* `tests/Feature/Domain/Ledger/PostedDataImmutabilityTest.php` — "reparenting
     a posted posting onto a draft transaction is rejected", which also asserts the original posted
     transaction still has both its postings afterward.

  3. **[P1, reproduced, fixed] Quiet Eloquent writes disabled the guards.**
     *Reproduction:* `$transaction->saveQuietly()` (after setting a dirty attribute),
     `JournalTransaction::withoutEvents(fn () => $transaction->update([...]))`, and
     `JournalTransaction::createQuietly([...'status' => Posted...])` all succeeded against a posted
     transaction/an unposted insert — proven by reverting the fix and re-running the new regression
     tests. `saveQuietly()`/`createQuietly()`/`withoutEvents()` mute Laravel's event dispatcher, but
     they still call `save()`/`delete()`, which unconditionally call
     `performInsert()`/`performUpdate()`/`performDeleteOnModel()`.
     *Fix:* both `JournalTransaction` and `Posting` moved their guards from `booted()` event
     listeners (`updating`/`deleting`/`creating`) into overrides of those three low-level pipeline
     methods, which run regardless of whether events are muted. This is the same mechanism finding
     1's fix uses, as the task doc anticipated. The kernel's own writes are unaffected, since they
     go through the identical `create()`/`update()` calls the guards already recognized as
     legitimate (Draft while attaching postings, then the one validated Draft→Posted flip).
     *Regression tests:* `tests/Feature/Domain/Ledger/PostedDataImmutabilityTest.php` — "quiet
     writes do not bypass the posted-immutability or LED-001 guards" (covers `saveQuietly`,
     `withoutEvents`, and `createQuietly` on `JournalTransaction`) and "a posting on a posted
     transaction rejects a quiet update or delete" (covers `saveQuietly` and `deleteQuietly` on
     `Posting`).

  4. **[P1, reproduced, fixed] ACC-006 check-then-post race.**
     *Reproduction:* two real, independent OS processes, each opening its own PostgreSQL
     connection and calling `RegisterExpenseAction` with amount `80` against a `100`-balance asset,
     released from a shared barrier file to start as close together in time as the OS scheduler
     allows — reverting the fix and re-running
     `tests/Unit/Domain/Ledger/Concurrency/AccountBalanceRaceTest.php` under
     `composer test:pgsql` produced two "success" results and a final posted native balance of
     `-60.000000000000000000`, the exact failure mode described.
     *Fix:* `PostJournalTransactionAction::createTransaction()` now locks every account referenced
     by the command's postings (`Account::query()->whereIn('id', ...)->lockForUpdate()->get()`, in
     ascending id order to avoid cross-command deadlocks) as the very first step inside the
     kernel's own `DB::transaction()`, before creating anything. `PostJournalTransactionCommand`
     gained an `accountsRequiringNonNegativeBalance` list; for each id in it, the kernel re-derives
     `Account::postedNativeBalance()` and adds this command's own net native movement for that
     account, under the lock just taken, and throws `InsufficientNativeBalance` if the projection
     is negative. `RegisterExpenseAction` no longer performs its own unlocked pre-check; it passes
     `[$command->assetAccountId]` and lets the kernel decide atomically. The balance decision and
     the posting now share one database transaction and one row lock, closing the race by
     construction rather than by narrowing the timing window.
     *Why SQLite cannot exhibit this race:* SQLite is a single-writer database (one writer
     connection may hold the database lock at a time, file-level or whole-`:memory:`-database, not
     per-row), so two connections can never be concurrently mid-transaction against the same row —
     there is no interleaving window for a check-then-post gap to exploit, independent of whether
     the application takes a row lock. `lockForUpdate()` compiles to no additional SQL on SQLite's
     query grammar (there is nothing more specific than the database-wide writer lock to ask for).
     *Regression test:* `tests/Unit/Domain/Ledger/Concurrency/AccountBalanceRaceTest.php` (skips
     itself with an explanatory message unless `config('database.default') === 'pgsql'`). Placed
     under `tests/Unit/...`, not `tests/Feature/...`, and using a dedicated
     `Tests\Support\NonTransactionalTestCase` (see "Important decisions" below) rather than this
     project's default `RefreshDatabase` binding, because the test needs two connections that
     genuinely see each other's committed writes, which `RefreshDatabase`'s per-test rollback
     transaction would prevent (as the task doc's own risk note anticipated). Uses two real OS
     processes (`tests/Support/scripts/register_expense_race.php`), not two Laravel connection
     objects in one process, because a single PHP process cannot run two overlapping blocking
     database calls at once.

  5. **[P1, reproduced, fixed] Non-UTC effective times stored the wrong instant and broke
     idempotent replay.**
     *Reproduction:* posting a command with `effectiveAt` = `2026-08-08 10:00:00 America/Caracas`
     (14:00 UTC) persisted and rehydrated as `2026-08-08T10:00:00+00:00` — four hours off — and an
     identical replay of the same non-UTC command raised `IdempotencyConflict` instead of returning
     the existing transaction — proven by reverting the fix and re-running the new regression
     tests. Root cause: Eloquent's `datetime` cast formats a `Carbon` instance in whatever timezone
     it currently holds; it does not convert to UTC, and the `effective_at` column carries no
     timezone of its own (the application timezone is UTC).
     *Fix:* `PostJournalTransactionAction::createTransaction()` now persists
     `$command->effectiveAt->clone()->utc()` instead of the raw command value. The idempotency
     canonicalization logic already normalized to UTC on both sides (see TASK-004's original
     execution record); the bug was purely at the persistence boundary, so this one line is
     sufficient and needed no change to canonicalization itself, keeping replay of transactions
     already posted in earlier tests unaffected (verified: full suite green on both engines).
     *Regression tests:* `tests/Feature/Domain/Ledger/EffectiveTimeNormalizationTest.php` — a
     round-trip test asserting the persisted and rehydrated instant is exactly `14:00:00Z`, and a
     replay test posting the identical non-UTC command twice (as two distinct, non-object-identical
     `Carbon` instances) and asserting the second call returns the same transaction.

  6. **[P2, reproduced, fixed] Intent actions accepted invalid payment accounts.**
     *Reproduction:* `RegisterIncomeCommand(assetAccountId: <book's OpeningEquity system account
     id>)` posted successfully (income against equity, no asset posting), and
     `RegisterExpenseCommand`/`RegisterIncomeCommand`/`RegisterOpeningBalanceCommand` all accepted
     an asset account denominated in a different instrument than the book's functional instrument
     (e.g. a VES-native account against a USDT-functional book), silently posting a bogus 1:1
     amount — proven by the new regression tests, which construct exactly these two scenarios
     across all three intent actions.
     *Fix:* new `App\Domain\Ledger\Actions\Concerns\AssertsValidPaymentAccount` trait, used by
     `RegisterOpeningBalanceAction`, `RegisterIncomeAction`, and `RegisterExpenseAction`. Before
     building any posting, each action calls `assertValidPaymentAccount($bookId, $accountId)`,
     which rejects (in order) a system-role account (`SystemAccountNotSelectable`, ACC-007), a
     non-Asset account type (`PaymentAccountTypeMismatch`), an archived account
     (`ArchivedAccountNotSelectable`, ACC-005), and an account whose native instrument differs from
     the book's functional instrument (`PaymentAccountInstrumentMismatch` — these actions have no
     valuation/FX policy in this slice). An account id that does not belong to the given book is
     left unrejected here, by design, and falls through to the kernel's own `CrossBookReference`
     (LIF-016), the same book-scoping precedent `RegisterExpenseAction`'s ACC-006 check already
     followed.
     *Regression tests:* `tests/Feature/Domain/Ledger/PaymentAccountValidationTest.php`, one
     dataset-driven test per rejection reason across all three actions (`opening`, `income`,
     `expense`), plus a positive test proving an ordinary active asset account in the book
     instrument is still accepted.

- **Important decisions or deviations:**
  1. **The `posted()` and `reversalOf()` `JournalTransaction` factory states changed shape.**
     Both used to set `status => Posted` directly on `create()`. Finding 1's fix makes that
     impossible from any Eloquent path by design, so both states now create the transaction as
     `Draft`, attach two balanced postings via `afterCreating()`, and flip to `Posted` the same way
     the kernel itself does. Every test that used `->posted()`/`->reversalOf()` purely as a status
     fixture (not asserting anything about posting count) continues to work unchanged; a few tests
     that separately attached their *own* single, unbalanced posting to a transaction before
     flipping it to `Posted` by hand needed a second, balancing posting added
     (`AccountTest.php`'s `createPostedPostingFor()`, `DeletionRestrictionTest.php`'s "deleting a
     book with a posting" test, `PostedDataImmutabilityTest.php`'s
     `postedTransactionWithExistingPosting()`), and one count assertion in
     `PostedDataImmutabilityTest.php` ("a posting cannot be created against an already-posted
     transaction") changed from expecting `0` postings to expecting `2` (the two the factory's
     `posted()` state now legitimately attaches) — none of these tests were asserting anything
     about *why* a transaction started balanced, only that it was posted and balanced by the time
     the behavior under test ran, so this preserves their original intent per the "adapt, don't
     delete" instruction.
  2. **The literal "a draft transaction can transition to posted through an Eloquent update" test
     is finding 1's own vulnerability, reproduced verbatim as a passing test.** It is not deleted;
     it is renamed to "an empty draft transaction cannot transition to posted" and its assertion is
     inverted to expect `InsufficientPostings`, with a comment explaining why. A new test with two
     balanced postings proves the legitimate case still works.
  3. **Finding 4's fix locks every posting account, not only the ones flagged for a balance
     check.** `lockPostingAccounts()` takes a row lock on the full set of accounts a command posts
     to, in ascending id order, independent of `accountsRequiringNonNegativeBalance`. This is
     slightly broader than the minimum needed for ACC-006 alone, but it means any two commands
     sharing accounts (not just two expenses on the same asset) are serialized against each other
     inside the kernel, and the stable lock order rules out a lock-ordering deadlock between two
     commands that reference the same two accounts in opposite orders — a real risk once a second
     multi-account action (e.g. a transfer) exists, even though this task does not add one.
  4. **`tests/Unit/Domain/Ledger/Concurrency/AccountBalanceRaceTest.php` lives under `tests/Unit`,
     not `tests/Feature`, despite being a full, database-backed integration test.** `tests/Pest.php`
     binds `RefreshDatabase` to every file under `tests/Feature` via
     `pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature')`; Pest does not
     let a single file under that glob swap in a different base test case
     (`Pest\Exceptions\TestCaseAlreadyInUse`, confirmed by trying it). `tests/Unit` has no such
     binding in this project, so the test can call `uses(Tests\Support\NonTransactionalTestCase::class)`
     directly without conflict. This is a deliberate, narrow exception to the domain architecture's
     Feature/Unit split for the one test that structurally cannot use this project's default test
     case; it is not a precedent for other database-integration tests, which belong in
     `tests/Feature` as usual.
  5. **`Tests\Support\NonTransactionalTestCase` overrides `beginDatabaseTransaction()`, not
     `connectionsToTransact()`.** The first attempt returned `[]` from `connectionsToTransact()`,
     which looks like Laravel's documented escape hatch for skipping the per-test transaction — but
     `RefreshDatabase::updateLocalCacheOfInMemoryDatabases()` and `beginDatabaseTransaction()` both
     iterate that same list to decide which connections' PDO handle to cache for SQLite's
     `:memory:` driver. Emptying it broke every *other* SQLite test that ran later in the same
     process (`no such table: users`), because nothing cached the freshly-migrated in-memory PDO
     for them to restore. Found via a full-suite run surfacing unrelated failures, root-caused by
     reading `RefreshDatabase`'s source, and fixed by keeping the normal connection list (so the
     caching still happens) while overriding `beginDatabaseTransaction()` itself to skip only the
     `beginTransaction()`/rollback pair.
  6. **The concurrency test's child processes receive the resolved connection config
     (`config('database.connections.<default>')`), not raw `getenv()` values.** `composer
     test:pgsql` runs with `--parallel`; Laravel's `ParallelTesting` rewrites the database name per
     worker via `config()`, not a real environment variable, so `getenv('DB_DATABASE')` inside a
     parallel worker still reports the base `testing` name while the worker's actual connection is
     against `testing_test_N`. The first version of this test passed the wrong database name to the
     child processes, which could not see the fixture rows and failed with neither a "success" nor
     an `InsufficientNativeBalance` result; found by running `composer test:pgsql` (parallel) after
     the same test had already passed under a plain, non-parallel `php artisan test` run.
  7. **No change to the documented mass-write/raw-SQL boundary.** `Model::query()->update()`/
     `delete()`, `insert()`/`upsert()`, and raw SQL still bypass every guard in this task, exactly
     as `JournalTransaction`'s and `Posting's` existing docblocks already stated before this task
     began; both docblocks are updated to describe the new `perform*()`-based mechanism but keep
     the same explicit statement of what remains out of scope, per the task's own scope boundary.

- **Verification:**
  - Reproduction: every finding above was confirmed failing against the pre-fix code by
    `git stash push` on the fixed files, re-running that finding's new regression test(s) (all
    failed with the exact symptom described), then `git stash pop` to restore the fix and
    confirming green again. Findings 4 and 5 were additionally hand-verified this way against a
    real PostgreSQL connection (finding 4 must be; finding 5 was checked on both engines).
  - `php artisan test --compact --no-tia` (SQLite `:memory:`) — **165 tests, 164 passed, 319
    assertions, 1 skipped** (`AccountBalanceRaceTest`, which skips itself with an explanatory
    message on a non-`pgsql` connection; the pre-existing SQLite-only redundant-CHECK test from
    earlier tasks runs and passes on SQLite, it only skips on PostgreSQL — see below).
  - `docker compose up --wait -d` then `composer test:pgsql` (PostgreSQL 17, `--parallel`,
    the committed compose service) — **165 tests, 164 passed, 323 assertions, 1 skipped** (the
    pre-existing SQLite-only redundant-CHECK test; the concurrency test itself ran for real this
    time, contributing its 5 assertions) — then `docker compose down`. Also independently
    re-ran `AccountBalanceRaceTest` alone, both with and without `--parallel`, to confirm it is not
    a parallel-worker artifact.
  - `vendor/bin/pint --dirty --format agent` then `vendor/bin/pint --format agent` — applied
    formatting fixes on the first run (import ordering, quote style, brace position in the new
    command DTO docblock), clean on the rerun.
  - `vendor/bin/phpstan analyse --memory-limit=512M` — **0 errors.**

- **Commit:** `502c25a` — fix(ledger): harden posting kernel against review findings (branch
  `feat/task-008-kernel-hardening`).

### Follow-up: address round-2 `changes_requested` findings (2026-08-09)

- **Summary:**

  1. **[P1, reproduced, fixed] Reparenting a posting *onto* a posted transaction (regression from
     the original finding-2 fix).**
     *Reproduction:* built a posted transaction and a same-book Draft carrying one `+5` posting
     (the composite `(journal_transaction_id, book_id)` foreign key requires the same book on both
     sides, or the write fails at the database layer for an unrelated reason that proves nothing
     about the application guard — an early version of this test used two different books and had
     to be corrected). `$posting->update(['journal_transaction_id' => $postedTransaction->id])`,
     the same call via `saveQuietly()`, and the same call inside `Posting::withoutEvents()` all
     succeeded with no exception against the code from commit `502c25a` — confirmed by temporarily
     `git stash`-ing only the model fix and re-running the three new regression tests, all of which
     failed with "Exception not thrown" (not a `QueryException`, once both sides shared a book).
     *Fix:* `Posting::performUpdate()` now throws when **either**
     `transactionIsPosted($this->originalParentId())` (the persisted parent — finding 2's original
     direction) **or** `transactionIsPosted($this->journal_transaction_id)` (the current/dirty
     value — this direction) is true. `performInsert()` already covered the injection direction for
     brand-new postings; this makes `performUpdate()` symmetric with it instead of trusting only one
     side. `performDeleteOnModel()` is unchanged: a delete has no "new parent" to check, only the
     persisted one.
     *Regression tests:* `tests/Feature/Domain/Ledger/PostedDataImmutabilityTest.php` — "reparenting
     a draft-parented posting onto a posted transaction is rejected" (plain `update()`, plus asserts
     the posted transaction's postings still sum to zero), "...through a quiet update"
     (`saveQuietly()`), and "...through withoutEvents" — alongside the pre-existing away-direction
     test, so both directions now have coverage through all three write paths this task cares about.

  2. **[P2, fixed] The ascending lock order was never emitted as real SQL.**
     *Confirmed via `toSql()`:* `Account::query()->whereIn('id', [3, 1, 2])->lockForUpdate()` compiled
     to `select * from "accounts" where "id" in (?, ?, ?)` — the PHP-side `->sort()` on the id
     collection only reordered bind parameter values, not row lock acquisition order, which
     PostgreSQL is free to choose from the `IN (...)` predicate however its plan prefers.
     *Fix:* added `->orderBy('id')` to `PostJournalTransactionAction::lockPostingAccounts()`'s
     query. Re-checked with `toSql()`: `... where "id" in (?, ?, ?) order by "id" asc`. The kernel
     and command docblocks' existing "ascending order" claims are now true rather than aspirational;
     no wording changed since the claim itself was already accurate as a *description of intent*,
     only the implementation was incomplete.

  3. **[P3, fixed] Concurrency test cleanup did not run on assertion failure.**
     *Fix:* `tests/Unit/Domain/Ledger/Concurrency/AccountBalanceRaceTest.php` now wraps every
     `expect()` call after the two child processes finish in a `try`/`finally`, with the bulk
     query-builder cleanup (`Posting`/`JournalTransaction`/`Account`/`Book`/`Instrument`/`User`
     deletes) moved into the `finally` block so it always runs, including when a future kernel
     regression makes one of the assertions fail.

- **Important decisions or deviations:**
  1. The first draft of finding 1's regression tests placed the Draft-parented posting and the
     posted transaction in two different books (via two independent factory calls). That version
     failed with a `QueryException` against both the pre-fix and post-fix code, because the
     composite `(journal_transaction_id, book_id)` foreign key rejects a cross-book reparent
     regardless of what the application guard decides — the test would have "passed" for the wrong
     reason once the fix landed. Corrected by adding a
     `postedTransactionAndSameBookDraftPosting()` helper that builds both transactions in the same
     book, so the only thing standing between the write and success is the application guard under
     test.
  2. Not a decision but worth recording for the next reviewer: `performDeleteOnModel()` was left
     checking only `originalParentId()`, deliberately. A delete's `journal_transaction_id` cannot be
     "dirty" toward a different target the way an update's can — there is no analogous injection
     direction for a delete to guard against.

- **Verification:**
  - Reproduction: `git stash push -- app/Domain/Ledger/Models/Posting.php`, re-ran the three new
    injection-direction regression tests against commit `502c25a`'s guard — all three failed with
    "Exception ... not thrown", confirming the exact regression the validator described — then
    `git stash pop` to restore the fix.
  - `php artisan test --compact --no-tia` (SQLite `:memory:`) — **168 tests, 167 passed, 329
    assertions, 1 skipped** (`AccountBalanceRaceTest`, self-skipped on a non-`pgsql` connection).
  - `docker compose up --wait -d` then `composer test:pgsql` (PostgreSQL 17, `--parallel`) —
    **168 tests, 167 passed, 333 assertions, 1 skipped** (the pre-existing SQLite-only
    redundant-CHECK test) — then `docker compose down`. Additionally re-ran, non-parallel, just
    `AccountBalanceRaceTest` and the three new reparenting tests together against PostgreSQL —
    **4 tests, 4 passed, 15 assertions** — confirming the concurrency test and the finding-1 fix
    both hold together, not only in isolation.
  - `vendor/bin/pint --dirty --format agent` — clean, no changes needed.
  - `vendor/bin/phpstan analyse --memory-limit=512M` — **0 errors.**
  - Confirmed via `php artisan tinker` that `Account::query()->whereIn('id', [3, 1,
    2])->orderBy('id')->lockForUpdate()->toSql()` now includes `order by "id" asc`.
  - Unrelated concurrent edits to `docs/04-money-valuations-and-rates.md`,
    `docs/05-accounts-and-obligations.md`, `docs/06-transaction-scenarios.md`, and
    `docs/decisions/ADR-004-cost-basis-and-backdating.md` were present in the working tree during
    this follow-up (evidently another session's in-progress work on a different task) and were left
    untouched and unstaged; only the files this follow-up changed were committed.

- **Follow-up commit:** `f2fbc34` — fix(ledger): reject reparenting a posting onto posted history
  (branch `feat/task-008-kernel-hardening`).

## Validation

> Filled by the validator.

- **Verdict:** Changes requested. Five of the six findings are genuinely reproduced-then-fixed and
  hold up under independent probing. Finding 2's fix, however, replaced a two-sided guard with a
  one-sided one and opened a new escape in the opposite direction: a posting can now be reparented
  *into* a posted transaction, unbalancing it. That is a regression against `main`, not a
  pre-existing defect, and it violates `LED-001` and `LIF-003` from an ordinary Eloquent path.

- **Findings:**

  1. **[P1] Reparenting a posting *onto* a posted transaction is no longer rejected (regression).**
     `app/Domain/Ledger/Models/Posting.php:99-115,122-125` — `performUpdate()` and
     `performDeleteOnModel()` consult only `originalParentId()`
     (`getOriginal('journal_transaction_id')`), so a posting whose persisted parent is a Draft
     passes the guard no matter what its *new* `journal_transaction_id` points at.
     *Expected:* `LED-001` ("every posted journal transaction must contain at least two postings and
     the exact sum of their functional amounts must be zero") and `LIF-003` hold for a posted
     transaction continuously, not only at the moment of transition; `performInsert()` already
     refuses to attach a new posting to a posted transaction, and before this task the
     `updating` guard read the dirty target and refused this too.
     *Observed:* validator probe (temporary Pest test, removed after the run) — a posted transaction
     from `JournalTransaction::factory()->posted()`, plus a Draft carrying one `+5` posting, then
     `$posting->update(['journal_transaction_id' => $posted->id])`: no exception thrown, the posted
     transaction ends with **3 postings and a functional sum of `5.000000000000000000`**. The same
     probe against `main`'s event-based guard is rejected, because `transactionIsPosted()` there
     read the dirty (new) parent.
     *Impact:* any Eloquent update path — including `saveQuietly()`/`withoutEvents()`, which this
     task exists to close — can silently unbalance posted history and corrupt every balance derived
     from it (`LED-011`), with no reversal record. Finding 2's own acceptance criterion ("posted
     postings reject reparenting") is met only in the away direction.
     *Resolve by:* rejecting the write when **either** the persisted parent or the target parent is
     posted, with a regression test for the draft→posted direction alongside the existing
     posted→draft one.

  2. **[P2] The claimed deterministic lock order is not actually emitted in SQL.**
     `app/Domain/Ledger/Actions/PostJournalTransactionAction.php:208-217`, and the same claim in the
     execution record's decision 3 and in `PostJournalTransactionCommand`'s docblock.
     *Expected:* "in ascending id order to avoid cross-command deadlocks" implies the database
     acquires the row locks in a command-independent order.
     *Observed:* `Account::query()->whereIn('id', $accountIds)->lockForUpdate()->get(['id'])` emits
     `select "id" from "accounts" where "id" in (?, ?, ?) for update` — verified with `toSql()`;
     there is no `ORDER BY`. Sorting the collection in PHP only reorders the bind parameters, which
     PostgreSQL ignores when choosing scan order.
     *Impact:* no live defect today (this slice has a single multi-account command shape, so
     concurrent commands share a plan), but the documented deadlock protection does not exist. The
     first action that locks a partially-overlapping account set can deadlock, and the docblock
     would mislead the reader into thinking it cannot.
     *Resolve by:* either ordering the locking query explicitly, or correcting both docblocks and
     the execution record to state that lock ordering is left to the engine.

  3. **[P3] The concurrency test's cleanup does not run when an assertion fails.**
     `tests/Unit/Domain/Ledger/Concurrency/AccountBalanceRaceTest.php:109-131` — the test commits
     real rows (by design, `NonTransactionalTestCase` skips the per-test transaction) and deletes
     them at the end, but the deletes sit after four `expect()` calls rather than in a `finally`.
     A regression in the kernel would therefore leave committed books, accounts, postings, and a
     user behind in that worker's database, turning one real failure into a cascade of unrelated
     ones. Not blocking; the green path cleans up correctly.

- **Evidence:**
  - `php artisan test --compact --no-tia` (SQLite `:memory:`) — **165 tests, 164 passed, 319
    assertions, 1 skipped**. Matches the execution record.
  - `docker compose up --wait -d` then `composer test:pgsql` (PostgreSQL 17, `--parallel`) — **165
    tests, 164 passed, 323 assertions, 1 skipped**, then `docker compose down`. Matches the
    execution record.
  - The 319/323 assertion gap is fully explained and contains no hidden skips: exactly one test
    skips per engine. On SQLite `AccountBalanceRaceTest` skips (0 assertions) while
    `PostingTest`'s `skipUnlessSqlite()` CHECK-constraint test runs (1 assertion); on PostgreSQL
    the reverse, with the concurrency test contributing 5. `319 - 1 + 5 = 323`.
  - `vendor/bin/pint --test --format agent` — passed (read-only mode; no worktree mutation).
  - `vendor/bin/phpstan analyse --memory-limit=512M` — 0 errors.
  - Finding 1 (Draft→Posted): independently probed. `create(['status' => Posted])`,
    `createQuietly`, `saveQuietly`, `withoutEvents`, and a plain `update()` on empty, single-posting,
    and non-zero-sum Drafts are all rejected; the kernel's own two-step flip still works.
    `forceDelete()` on both models reaches `performDeleteOnModel()` and is rejected (verified);
    `touch()` on a posted transaction either is a no-op or reaches `performUpdate()` and throws;
    `increment()` on a monetary column fails in the `MonetaryScale` cast.
  - Finding 5 (UTC): independently probed beyond the shipped tests. A `America/Caracas` submission
    stores `2026-08-08T14:00:00+00:00`; an identical non-UTC replay returns the same transaction;
    and the *same instant expressed as `14:00 UTC`* also returns the same transaction, so
    canonicalization is instant-based rather than wall-clock-based, which is the correct semantics.
  - Finding 6: rejection is proven per reason (system role, non-Asset type, archived, instrument
    mismatch) across all three intent actions, each asserting zero transactions were written. The
    expense fee path needs no separate account check — the fee posts to the resolved `Fees` system
    account and the outflow leaves the same, already-validated asset account.
  - Factory change reviewed: `posted()`/`reversalOf()` now build two balanced postings via the
    kernel's Draft-then-flip sequence. The three adapted fixtures
    (`AccountTest::createPostedPostingFor()`, `DeletionRestrictionTest`,
    `PostedDataImmutabilityTest::postedTransactionWithExistingPosting()`) each gained a balancing
    counterpart posting only; no assertion was removed or loosened, and the one changed count
    assertion (`0` → `2`) still proves that nothing new attached.
  - Commit hygiene: `git show --format=fuller --no-patch 502c25a f079598` — no AI attribution
    trailers, Conventional Commit subjects, author and committer identical. No dependency changes
    (`composer.json`/`package.json` untouched). Worktree clean apart from this validation edit.

- **Follow-ups:**
  - After finding 1 above is repaired, this review is no longer independent for that change; a
    fresh validation round is required.
  - Consider whether `LED-001` deserves an enforcement point that does not depend on every Eloquent
    write path being individually guarded — three separate escapes have now been found in this one
    seam across two review rounds. Out of scope for TASK-008.

### Round 3 (2026-08-08, fresh validator — reviews `f2fbc34`/`8a461ad`)

- **Verdict:** Done. All three round-2 findings are genuinely fixed and hold up under independent
  probing, the fix introduces no new escape and no false positive, and every required gate
  reproduces the executor's reported numbers exactly on both engines. No blocking findings.

- **Findings:** none at P0/P1/P2. One non-blocking P3 is recorded under Follow-ups.

- **Evidence:**
  - **Round-2 finding 1 (reparenting), probed as a full quadrant matrix, not a single scenario.**
    Temporary Pest probe (`tests/Feature/Domain/Ledger/ZzValidatorProbeTest.php`, removed before
    this commit) exercised every persisted-parent → target-parent combination, each through
    `update()`, `saveQuietly()`, and `Posting::withoutEvents()`, all fixtures in a **single book**
    so the composite `(journal_transaction_id, book_id)` foreign key could not mask the guard:
    - posted → draft (escape): rejected, 3/3 paths; source transaction keeps both postings.
    - draft → posted (injection): rejected, 3/3 paths; target keeps exactly 2 postings and a zero
      functional sum. This is the exact regression round 2 found.
    - posted → posted: rejected, 3/3 paths; both transactions keep 2 postings.
    - draft → draft: **allowed**, 3/3 paths, and the posting genuinely lands on the new Draft
      parent — the legitimate workflow is not collaterally blocked.
    18 probe tests, 18 passed on PostgreSQL (39 assertions); 17 passed + 1 engine-gated skip on
    SQLite.
  - **The committed regression tests genuinely fail against the pre-fix guard.** Independently
    reproduced rather than taken on trust: `performUpdate()`'s condition was temporarily reduced to
    the round-1 one-sided form (`transactionIsPosted($this->originalParentId())` only) from a
    scratchpad copy, the suite re-run, and the file restored byte-for-byte (md5 verified). Result:
    exactly 6 failures, all of them the injection direction — the executor's three committed
    `reparenting a draft-parented posting onto a posted transaction ...` tests plus this
    validator's three `draft->posted` quadrant cases. Every other test in
    `PostedDataImmutabilityTest` still passed, confirming the new tests isolate this defect and do
    not merely ride along with existing coverage.
  - **Regression sweep of the fix itself (the round-2 lesson).** The both-sides check produces no
    false positive: a Draft-parented posting still accepts a `memo` update, a `functional_amount`
    update, and a delete; the kernel's own Draft-create-then-flip sequence still posts and stays
    balanced; and the full suite is green on both engines, which exercises the kernel through every
    intent action. The asymmetry in `performDeleteOnModel()` (persisted parent only) is correct —
    a delete has no prospective new parent to inject into.
  - **Round-2 finding 2 (lock order) is now real SQL.** `php artisan tinker` against the compose
    PostgreSQL service: `Account::query()->whereIn('id', [3, 1, 2])->orderBy('id')
    ->lockForUpdate()->toSql()` →
    `select * from "accounts" where "id" in (?, ?, ?) order by "id" asc for update`. The `ORDER BY`
    is above the lock in the plan, so rows are locked in sorted order rather than in whatever order
    the scan returns them. `PostJournalTransactionAction::lockPostingAccounts()` (line 221) is the
    query that was checked, and its docblock's added paragraph describes exactly what the code now
    does — no residual overclaim in either that docblock or `PostJournalTransactionCommand`'s.
  - **Round-2 finding 3 (test cleanup) is fixed.** `AccountBalanceRaceTest.php:113-137` — all four
    `expect()` calls are inside `try`, and the six bulk deletes are in `finally`, so a future kernel
    regression that fails an assertion still removes the committed rows. The test still passes for
    real on PostgreSQL (it is the one test that runs there and skips on SQLite).
  - **Round-1 findings spot-checked as still closed** (not assumed): `saveQuietly()` mutation of a
    posted posting's `functional_amount` is rejected and the transaction stays balanced (finding 3);
    a Draft whose two postings sum to `+10` is rejected on the flip to Posted with
    `JournalTransactionIsUnbalanced` and stays Draft (finding 1); the non-UTC round-trip and replay
    tests (finding 5) and the per-reason payment-account rejection tests (finding 6) all pass in the
    full-suite runs below.
  - `php artisan test --compact --no-tia` (SQLite `:memory:`) — **168 tests, 167 passed, 329
    assertions, 1 skipped**. Matches the follow-up execution record exactly.
  - `docker compose up --wait -d` then `composer test:pgsql` (PostgreSQL 17, `--parallel`) —
    **168 tests, 167 passed, 333 assertions, 1 skipped**, then `docker compose down`. Matches the
    follow-up execution record exactly. The 329/333 gap is the concurrency test's 5 assertions
    replacing the SQLite-only CHECK test's 1, unchanged from round 2.
  - `vendor/bin/pint --test --format agent` — passed (read-only mode; no worktree mutation).
  - `vendor/bin/phpstan analyse --memory-limit=512M` — 0 errors.
  - Commit hygiene: `f2fbc34` and `8a461ad` carry no AI attribution trailers, no `Co-Authored-By`,
    and no generation footer; author and committer are identical; subjects are Conventional Commits
    at 61 and 50 characters. No dependency changes. `8a461ad` records the correct hash (`f2fbc34`)
    for the follow-up commit. Unrelated in-progress planner edits to `docs/04`, `docs/05`,
    `docs/06`, `docs/README.md`, `docs/decisions/ADR-004`, `docs/tasks/005`, and
    `docs/tasks/README.md` were present throughout and were left untouched and unstaged; this
    validation commit stages only this file.

- **Follow-ups:**
  - **[P3, non-blocking] `AccountBalanceRaceTest`'s cleanup still does not cover a failure before
    the `try`.** The fixture rows (user, instrument, book, accounts, opening balance) are committed
    at lines 49-60, but the `try` opens at line 113. A `ProcessTimedOutException` from either
    `$process->wait()` would escape before the `finally` and leave those rows behind — the same
    class of problem round 2 raised, in a narrower window. The assertion path this round's finding
    named is genuinely fixed; widening the `try` to start right after the fixture setup would close
    the remainder.
  - The round-2 architectural follow-up still stands and is still out of scope: `LED-001`/`LIF-003`
    are enforced by guarding each Eloquent write path individually, and this seam has now produced
    four escapes across three review rounds. A future slice should consider an enforcement point
    that does not depend on the guard reading the right side of every write.
  - This validator wrote no production or test code in the reviewed change and repaired nothing;
    the temporary probe file was removed and `Posting.php` restored to its committed content before
    this record was written, so this review is independent.
