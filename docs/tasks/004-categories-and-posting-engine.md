---
id: TASK-004
title: Categories and the posting engine
status: done
rigor: strict
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

- [x] A book bootstrap action creates a book with its functional instrument and exactly one system
      account per role; a second bootstrap of the same book does not duplicate them (test).
- [x] `SCN-OPEN-001`: an opening balance posts asset against opening equity, increases the native
      balance, and reports no income (test).
- [x] `SCN-INC-001`: an income posts asset against income control and carries its category on the
      income posting (test).
- [x] A functional-instrument expense posts expense control against the asset, and when the
      transaction includes a fee posting, only the expense posting carries the category (ADR-003
      validation note, test).
- [x] The kernel rejects: fewer than two postings, a functional sum that is not exactly zero at
      scale 18, a posting violating the `LED-005` zero policy, a sign contradicting the declared
      shape, and an account or category from another book (tests).
- [x] Reusing an idempotency key with the identical canonical payload returns the existing
      transaction without a duplicate; reusing it with a different payload raises a conflict
      (tests).
- [x] Posting is atomic: a validation or persistence failure after partial work leaves no header,
      postings, or category rows behind (test).
- [x] A posted transaction's financial fields and postings reject Eloquent updates and deletes
      (`LIF-003` guards, tests).
- [x] An expense that would push the asset's native balance negative is rejected by default
      (`ACC-006`, test).
- [x] `JournalTransactionPosted` dispatches only after commit; a rolled-back posting dispatches
      nothing (test).
- [x] The seeded instruments are USDT, USDC, VES, EUR, `USD.CASH`, and `USD.BCV` (ADR-005).
- [x] `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, and
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

- **Summary:** Implemented the single posting kernel and the first three intent actions on top of
  it, plus categories (ADR-003) and the remaining `LIF-003` immutability guards.
  - **Schema (rewritten in place, per the migration-workflow skill and the TASK-001/ADR-003
    precedent):** added a book-scoped `categories` table (unique `(book_id, name)`, composite
    `(id, book_id)` unique as an FK target) to `2026_08_07_045204_create_journal_transactions_and_
    postings_tables.php`, before `journal_transactions`; added a nullable `postings.category_id`
    with a composite `(category_id, book_id)` foreign key into `categories`. Added a partial unique
    index `accounts_book_id_system_role_unique ON accounts (book_id, system_role) WHERE system_role
    IS NOT NULL` to `2026_08_07_045203_create_books_containers_and_accounts_tables.php` (ACC-007/
    LIF-017: exactly one system account per role per book, without forbidding multiple ordinary
    accounts). Both SQLite and PostgreSQL accept the same partial-index syntax.
  - **ADR-005 seed update:** `InstrumentSeeder` now seeds `USDT`, `USDC`, `VES`, `EUR`, `USD.CASH`,
    and `USD.BCV`, replacing the single `USD` row. No factory hardcoded `'USD'`.
  - **Models:** `App\Domain\Ledger\Models\Category` (book-scoped, flat); `Posting` gained
    `category_id` (fillable, `category()` relation) and an `LIF-003` guard (`updating`/`deleting`
    reject once the parent transaction is `Posted`); `JournalTransaction` gained the same guard
    against its own persisted status; `Account` gained `postedNativeBalance()` (LED-011/012: summed
    from posted postings only, via `MonetaryDecimal::sum()`, no balance column); `Book` gained a
    `categories()` relation. `SystemAccountRole` gained `accountType(): AccountType`, a declared
    mapping (OpeningEquity/CorrectionSuspense → Equity, IncomeControl/RealizedFxGain → Income,
    ExpenseControl/RealizedFxLoss/Fees/Rounding → Expense) used by the book bootstrap.
  - **`MonetaryDecimal` (Money module, TASK-003's value object) gained `sum()`, `isZero()`,
    `isNegative()`, and `negated()`** — small, tested additions reused by the kernel's exact-zero
    balance check (LIF-011) and by intent actions deriving a counterpart posting's amount from a
    caller-supplied positive magnitude.
  - **`App\Domain\Ledger\Actions\PostJournalTransactionAction`** is the one posting kernel
    (LED-015, LIF-018). It validates aggregate rules before any write (≥2 postings; LED-005 zero
    policy; LED-008 sign consistency between a posting's native quantity and functional amount;
    exact zero functional sum via `MonetaryDecimal::sum()`/`isZero()`; every account/category
    belongs to the command's book), then creates the transaction and its postings inside one
    `DB::transaction()` (LIF-002), then dispatches `JournalTransactionPosted` (implements
    `ShouldDispatchAfterCommit`, ARC-013) only after that call returns successfully. Idempotency
    (LIF-008/009): looks up an existing transaction by `(book_id, idempotency_key)` first; an
    identical canonical payload (sorted by account/category, decimal-normalized, ISO-8601 effective
    time) returns the existing transaction, a different one throws `IdempotencyConflict`. A
    concurrent duplicate that slips past the initial lookup is caught as a `QueryException` on the
    `UNIQUE(book_id, idempotency_key)` violation and resolved the same way, per LIF-009's
    concurrency note, rather than surfacing as an unexpected crash.
  - **Intent actions**, each validating its own intent, selecting system accounts via a shared
    `ResolvesSystemAccount` trait (ACC-008), and calling the kernel: `BootstrapBookAction`
    (ACC-007/LIF-017 — creates the book and exactly one account per `SystemAccountRole`,
    idempotent via `firstOrCreate` plus the new partial unique index as a DB-level safety net);
    `RegisterOpeningBalanceAction` (SCN-OPEN-001); `RegisterIncomeAction` (SCN-INC-001, category on
    the income posting only); `RegisterExpenseAction` (SCN-EXP-001's functional-instrument shape,
    optional fee posting that never carries the category, and the ACC-006 native-balance guard via
    `Account::postedNativeBalance()`).
  - Command/result DTOs live under `app/Domain/Ledger/Data/` (`PostJournalTransactionCommand`,
    `PostingInput`, and one command per intent action), typed against `Carbon\CarbonInterface` to
    match this project's global `Date::use(CarbonImmutable::class)` convention rather than the
    concrete `Illuminate\Support\Carbon` class.
  - New exceptions under `app/Domain/Ledger/Exceptions/`: `InsufficientPostings`,
    `JournalTransactionIsUnbalanced`, `ZeroPostingIsNotAllowed`, `PostingSignMismatch`,
    `CrossBookReference`, `IdempotencyConflict`, `InsufficientNativeBalance`,
    `PostedDataIsImmutable`.

- **Important decisions or deviations:**
  1. **"Sign contradicts the declared shape" (LED-008) is interpreted as same-posting sign
     consistency**, not an account-type-based rule: a posting's native quantity and functional
     amount must carry the same sign (both describe one real movement in two denominations), unless
     the native quantity is exactly zero (LED-005's documented exception). Account-type-based "must
     stay positive" rules do not hold in general (an asset legitimately goes negative-signed when
     spent), so the kernel cannot judge correctness from account type alone; it can, and does,
     reject an internally contradictory posting without guessing which side is wrong. Intent
     actions declare the shape by computing each posting's sign themselves (never accepting a
     pre-signed "shape" flag from a further-out caller).
  2. **Idempotency canonical payload is derived, not stored.** Rather than adding a payload-hash
     column (not named as required schema in the task), the kernel reconstructs a canonical array
     from the persisted transaction and postings (sorted by account/category, using
     `MonetaryDecimal`-normalized decimal strings) and compares it to the same construction from
     the incoming command. This satisfies LIF-009 without a schema addition beyond what the task
     scoped ("New monetary columns are none; `category_id` is not monetary").
  3. **System-account-type mapping for `RealizedFxGain`/`RealizedFxLoss`/`Fees`/`Rounding`/
     `CorrectionSuspense`** (not directly exercised by this task's postings) follows the canonical
     sign convention: a gain behaves like income (credit-normal), a loss like an expense
     (debit-normal), and the correction suspense account is treated like opening equity — a nominal
     balancing account, not a real asset or liability. These roles are bootstrapped now (the
     acceptance criterion is "one system account per role") but are not posted to until later
     tasks (FX, rounding, corrections).
  4. **`RegisterExpenseAction`/`RegisterIncomeAction`/`RegisterOpeningBalanceAction` accept a
     positive-magnitude `amount` (and `feeAmount`)** and compute each posting's sign themselves,
     rather than accepting caller-supplied signed values. They document this as a caller contract;
     a negative `amount` is not defensively rejected, since nothing in the acceptance criteria
     requires it and the sign-consistency check inside the kernel does not by itself detect it
     (both sides of that one posting would still agree in sign).
  5. **Concurrent-duplicate idempotency resolution (the `QueryException`-catch branch) is not
     covered by a dedicated race-simulation test.** Forcing genuine concurrent contention
     deterministically inside a synchronous Pest test would require invasive mocking that risks
     being misleading rather than proving anything real; the branch reuses the same
     `resolveIdempotentDuplicate()` comparison already covered by the direct (non-concurrent)
     same-key tests, and the `UNIQUE(book_id, idempotency_key)` constraint it relies on is proven
     by TASK-001's own tests.
  6. **Fixed a pre-existing test/implementation interaction, not a new defect:** `JournalTransac
     tionTest.php`'s "the database rejects a transaction that references itself as its own
     reversal" test used a `posted()` transaction fixture; the new LIF-003 guard now rejects that
     `update()` before the query reaches the database, masking the `QueryException` the test was
     built to prove. Changed the fixture to `draft()`, which still reaches the CHECK constraint the
     test targets and leaves the assertion unchanged.
  7. **Found and fixed a latent bug in the immutability guards while testing them**: `Illuminate\
     Database\Eloquent\Builder::value()` fetches a model and reads the attribute, so it applies the
     `status` enum cast — comparing its result against `TransactionStatus::Posted->value` (a
     string) is always false. Both guards now compare against the enum case itself
     (`TransactionStatus::Posted`), confirmed via a failing-then-passing Pest probe before writing
     the permanent tests.

- **Verification:**
  - `php artisan migrate:fresh --no-interaction` (local disposable SQLite) — all seven migrations
    (three pre-existing plus the two edited in place) ran cleanly, including the new `categories`
    table, `postings.category_id` composite FK, and the `accounts` partial unique index.
  - `php artisan test --compact` (SQLite, `:memory:`) — **127 passed, 235 assertions, 0 failed**
    (up from TASK-003's 80/123; every acceptance-criterion scenario above has at least one
    dedicated test, plus the pre-existing suite unaffected apart from the one fixture fix in
    finding/deviation 6).
  - `docker compose up --wait -d` + `composer test:pgsql` (PostgreSQL 17, the committed compose
    service) — **127 tests, 126 passed, 234 assertions, 1 skipped** (the one pre-existing
    SQLite-only redundant-CHECK-constraint test from TASK-003, unrelated to this task) — then
    `docker compose down`. Identical pass/fail shape to the SQLite run.
  - `vendor/bin/pint --dirty --format agent` — applied minor style fixes to two new files on the
    first run (import ordering, brace style), clean on the rerun.
  - `composer run types:check -- --memory-limit=512M` (PHPStan/Larastan, the default 128M limit is
    insufficient locally, a pre-existing local constraint per TASK-002/003) — **0 errors**. Fixing
    this required adding `@property` docblocks to `JournalTransaction` and `Posting` (their first
    app-code, non-test attribute access outside factories/casts) and loosening two `list<...>`
    return-type annotations to `array<int, ...>` to match what the actual `Collection` chain
    produces.
  - Manually verified via `php artisan tinker` before writing the permanent tests: book bootstrap
    idempotency, `SCN-OPEN-001`/`SCN-INC-001`/expense-with-fee posting shapes, native balance
    accumulation, idempotent replay, and the immutability guards (this is where finding 7 above was
    caught).

- **Commit:** `4f68ba6` — feat(ledger): add posting kernel, categories, and intent actions

### Follow-up: address `changes_requested` findings (2026-08-08)

- **Summary:**
  1. **[P1] Non-positive intent-action amounts now rejected before any posting is built.** Added
     `App\Domain\Ledger\Exceptions\NonPositiveAmount` and a `RequiresPositiveAmount` trait
     (`app/Domain/Ledger/Actions/Concerns/`) applied to `RegisterOpeningBalanceAction`,
     `RegisterIncomeAction`, and `RegisterExpenseAction` (amount and, when given, fee amount). This
     closes both reported reproductions: `RegisterExpenseCommand(amount: '-1000')` on an empty
     asset and `RegisterIncomeCommand(amount: '-100')` now both throw `NonPositiveAmount` instead of
     creating or destroying value. The kernel's same-posting sign-consistency rule is unchanged, per
     the validator's confirmation that it is sound on its own terms — it was never the right layer
     to catch an inverted-but-internally-consistent posting.
  2. **[P2] `Posting` now guards `creating`, not only `updating`/`deleting`.** A posting can no
     longer be attached to an already-`Posted` transaction. This required reordering the kernel's
     own write sequence (`PostJournalTransactionAction::createTransaction()`): the transaction is
     now created as `Draft`, every posting is created against it while it is still `Draft` (so the
     new guard does not block the kernel's own legitimate writes), and the transaction is flipped to
     `Posted` only as the final step — the one `Draft` -> `Posted` transition the existing
     `JournalTransaction` guard already allowed, since it checks the *currently persisted* status.
     Every existing test fixture that built "a posting on a posted transaction" via
     `JournalTransaction::factory()->posted()->create()` followed by `Posting::factory()->create()`
     had to change to the same draft-then-flip sequence
     (`AccountTest.php`, `DeletionRestrictionTest.php`, `PostedDataImmutabilityTest.php`) — none of
     those tests were asserting anything about *when* the transaction became posted, only that it
     was posted by the time the behavior under test ran, so the sequencing change preserves their
     original intent.
  3. **[P2] The idempotency canonical sort key is now a total order.** `postingSortKey()` in
     `PostJournalTransactionAction` previously keyed only on `(account_id, category_id)`, so two
     postings sharing both (e.g. two legs on the same account) kept their input order and made the
     canonical payload order-*dependent* for that case. It now folds in `native_quantity`,
     `functional_amount`, and `memo` (via `json_encode` of the ordered tuple, since these are
     already normalized, fixed-format decimal strings — no numeric sort semantics are needed, only a
     deterministic one). Postings identical in every one of these fields are truly interchangeable,
     so any relative order between them is safe.
  4. **[P3, fixed rather than deferred] The kernel now enforces LED-010/ADR-003 at the single
     posting boundary.** Added `CategoryNotAllowedOnAccountType` and extended
     `assertSameBook()` to reject a category attached to a posting whose account is not `Income` or
     `Expense`, using the same account rows already fetched for the cross-book check (now fetching
     `type` alongside `id`). This was flagged as non-blocking because the binding, action-scoped
     acceptance criterion was already met, but the fix was cheap given the existing query and closes
     the gap at the boundary the validator identified as the right home for it.
  5. **[P3, fixed] `RegisterExpenseAction`'s `ACC-006` balance read is now book-scoped.**
     `assertSufficientNativeBalance()` queries `Account::where('book_id', $bookId)->find(...)`
     instead of an unscoped `findOrFail()`; a missing/foreign account now falls through to the
     kernel's own `CrossBookReference` rejection instead of being read and reported as
     `InsufficientNativeBalance`.
  6. **[P3, documented rather than changed] Mass update/delete bypassing the `LIF-003` guards is
     now stated explicitly in both guarded models' docblocks** (`JournalTransaction`, `Posting`),
     naming the uncovered write paths (`Model::query()->update()`/`delete()`, `insert()`/`upsert()`,
     raw SQL) and why they are out of scope today (LED-015/LIF-018 make the posting kernel the only
     write path in `app/`), mirroring the precedent `App\Domain\Money\Casts\MonetaryScale` already
     set for the same class of gap.

- **Important decisions or deviations:**
  1. Kept the kernel's same-posting sign-consistency rule exactly as the validator confirmed it
     ("otherwise sound and should stay") and did not attempt to make the kernel itself detect a
     non-positive intent amount — that information (which side is "the expense" versus "the funding
     source") only exists at the intent-action layer, which is where the fix now lives.
  2. Chose `json_encode` of an ordered tuple for the sort key over hand-rolled string
     concatenation with a delimiter: `memo` is arbitrary caller text and could contain any
     delimiter character, so a fixed-format concatenation risks re-introducing an ordering
     ambiguity for a different reason than the one just fixed.
  3. Two tests needed edits, not because they were wrong, but because tightening the account-type
     rule (fix 4) or the creating guard (fix 2) changed what a *different* concern's test had to set
     up to isolate its own concern: `PostJournalTransactionActionTest`'s "rejects a category from
     another book" test now attaches the cross-book category to a dedicated `Expense` account
     instead of the helper's `Asset` account, so it exercises `CrossBookReference` rather than
     tripping the new `CategoryNotAllowedOnAccountType` first.

- **Verification:**
  - `php artisan test --compact` (SQLite, `:memory:`) — **141 passed, 269 assertions, 0 failed** (up
    from 127; 14 new tests cover the three blocking findings, the two cheap P3 fixes, and the
    reordered creating-guard setup).
  - `docker compose up --wait -d` + `composer test:pgsql` (PostgreSQL 17) — **141 tests, 140 passed,
    268 assertions, 1 skipped** (the same pre-existing SQLite-only redundant-CHECK test) — then
    `docker compose down`.
  - `vendor/bin/pint --dirty --format agent` — applied import-ordering fixes to two files on the
    first run, clean on the rerun.
  - `composer run types:check -- --memory-limit=512M` — **0 errors**. Required adding an
    `@property` docblock to `Account` (its `type` attribute's first access outside factories/casts
    in application code, same root cause as the `JournalTransaction`/`Posting` docblocks added in
    the original execution record).

- **Commit:** `916ded7` — fix(ledger): close negative-amount, LIF-003, and idempotency gaps

## Validation

> Filled by the validator.

- **Verdict:** `done`, after two independent review rounds. Round 1 recorded
  `changes_requested`; round 2 (below) verified every finding closed, with no regression and no new
  blocking finding.

### Round 1 — `changes_requested` (against `4f68ba6`)

The posting kernel, categories, bootstrap, atomicity, after-commit event, seeder, migrations, and
immutability guards were correct and well tested, and every declared quality gate reproduced. Three
findings blocked acceptance: one that let a mis-signed intent create money in the ledger, and two
gaps in invariants this slice is responsible for.

- **Findings:**

  1. **[P1] A negative magnitude passed to an intent action posts an inverted, immutable transaction
     and bypasses `ACC-006`.** `app/Domain/Ledger/Actions/RegisterExpenseAction.php:28-67` (sign
     derivation at 36-37 and 54-58, guard at 69-78) and
     `app/Domain/Ledger/Actions/RegisterIncomeAction.php:29-43`.
     *Expected:* the plan states the kernel "rejects a posting whose sign contradicts the intent
     action's declared shape"; `ACC-006` requires the default action policy to reject spending beyond
     an asset's available native balance; both command DTOs document a positive magnitude.
     *Observed:* `RegisterExpenseCommand(amount: '-1000')` on an empty asset account posted
     successfully — expense control `-1000`, asset `+1000` — and `Account::postedNativeBalance()`
     then returned `1000.000000000000000000` for an account that never received funds. The `ACC-006`
     guard cannot catch it: `projected = available + 1000` is positive, so the check passes.
     Symmetrically, `RegisterIncomeCommand(amount: '-100')` drove the asset to
     `-100.000000000000000000`, exactly the accidental negative asset `ACC-006` exists to prevent,
     on a path with no balance check at all. The kernel's sign rule cannot see this, because both
     legs of each posting agree in sign — which is precisely why the declared shape must be enforced
     where it is declared.
     *Impact:* one mis-signed input creates or destroys value in the ledger. `LIF-003` makes the
     result immutable and TASK-005's reversal path does not exist, so the row can never be corrected.
     *Resolve by:* enforcing the declared shape — either the intent actions reject a non-positive
     magnitude, or the kernel receives the shape and validates each posting against it. Execution
     deviation 4 documents this as intentional; under this task's rules it is a gap, not a
     defensible reading. Deviation 1's same-posting consistency check is otherwise sound and should
     stay.

  2. **[P2] `LIF-003` guards do not cover `creating`: a posting can be appended to a posted
     transaction.** `app/Domain/Ledger/Models/Posting.php:58-71`.
     *Expected:* posted financial data is immutable (`LIF-003`) and a posted transaction's functional
     amounts sum to zero (`LED-001`).
     *Observed:* `Posting::create([... 'journal_transaction_id' => <posted transaction> ...])`
     succeeds. The probe left a posted transaction holding three postings summing to `+5`.
     *Impact:* posted financial content changes and the balance invariant breaks, with no error and
     nothing in the model layer objecting.
     *Resolve by:* extending the guard so a posting cannot be created against an already-posted
     transaction.

  3. **[P2] The canonical idempotency payload is order-sensitive when two postings share the same
     `(account_id, category_id)`, producing a false conflict on a genuine replay.**
     `app/Domain/Ledger/Actions/PostJournalTransactionAction.php:184-235`, sort key at 232-235.
     *Expected:* `LIF-009` — reusing a key with an identical canonical payload returns the existing
     result. The implementation sorts postings precisely to make the canonical form order-independent,
     and that works for distinct accounts (verified).
     *Observed:* posting `[A:+3, A:+7, C:-10]` and then replaying the same command with the first two
     postings swapped raised `IdempotencyConflict`. The sort key is `account_id` + `category_id` only,
     so equal-key postings keep their input order.
     *Impact:* a retry of an unchanged command is rejected as a materially different payload — the
     exact failure mode idempotency exists to prevent. No current intent action emits two postings on
     one account, but the kernel is the boundary every future slice publishes through, and a
     multi-leg FX or fee transaction will.
     *Resolve by:* making the canonical ordering total over the fields that distinguish a posting.

  4. **[P3] The kernel does not restrict a category to the income or expense posting.**
     `PostJournalTransactionAction.php:107-132` validates only that a category belongs to the book.
     A category on an asset or fee posting is accepted at the kernel boundary; only the intent
     actions enforce `LED-010`/ADR-003. The binding acceptance criterion is action-scoped and is met
     (`RegisterExpenseAction` leaves the fee posting uncategorized, proven by test), so this does not
     block — but the single posting boundary is where the rule will need to live once other callers
     build transactions.

  5. **[P3] The `ACC-006` check reads an account without book scoping.**
     `RegisterExpenseAction.php:69-78` calls `Account::findOrFail()` before the kernel's book check,
     so an asset account from another book is reported as `InsufficientNativeBalance` rather than
     `CrossBookReference`, after reading its balance. The posting is still rejected; only the error
     and a balance read are wrong.

  6. **[P3] Mass updates and deletes bypass the `LIF-003` guards.**
     `JournalTransaction::query()->whereKey($id)->update(...)` and
     `Posting::query()->where(...)->delete()` mutate posted rows silently, since query-builder writes
     fire no model events. The plan asked for model-level guards, so this is inherent rather than a
     deviation, and the kernel is verified as the only write path in `app/`. Worth an explicit note
     in the guard docblocks so a later contributor does not mistake the guard for complete coverage.

- **Evidence:**
  - `php artisan test --compact --no-tia` (SQLite `:memory:`) — **127 passed, 235 assertions, 0
    failed**. Matches the execution record.
  - `docker compose up --wait -d` + `composer test:pgsql` (PostgreSQL 17) — **127 tests, 126 passed,
    234 assertions, 1 skipped** (the pre-existing SQLite-only redundant-CHECK test). Matches the
    execution record. Service torn down with `docker compose down`.
  - `vendor/bin/phpstan analyse --memory-limit=512M` — 0 errors.
  - `vendor/bin/pint --test --format agent` — clean on every file under review.
  - 32 temporary validator probes were run on both engines and removed; the worktree carries no test
    changes. Findings 1, 2 and 3 above are each reproduced by a passing probe.
  - Live schema inspected on both engines. SQLite `sqlite_master`: the spliced CHECK constraints on
    `accounts`, `journal_transactions`, and `postings` survived the in-place rewrite, monetary columns
    remain `varchar` (ADR-001 amendment), and
    `CREATE UNIQUE INDEX accounts_book_id_system_role_unique ... WHERE system_role IS NOT NULL` is
    present. PostgreSQL `\d accounts` / `\d postings`: the same partial unique index exists, monetary
    columns are `numeric(38,18)`, and `postings_category_id_book_id_foreign (category_id, book_id) ->
    categories(id, book_id)` is in place. A cross-book category insert is rejected at the database on
    both engines.
  - `migrate:fresh`, then `migrate:rollback` and `migrate` against a disposable SQLite file — clean in
    both directions.
  - Verified by probe: exact-zero balance at scale 18 (`10.000000000000000001` rejects), native
    quantities correctly exempt from balancing (`LED-002`), mixed-sign multi-posting sums, second
    bootstrap idempotent with the partial index rejecting a duplicate role, `ACC-006` boundary exact
    (equal-to-balance passes, one unit at scale 18 over rejects, fee included in the outflow),
    idempotency conflicts correctly raised for a different amount, account, category, memo,
    description, and effective time, replay correct across decimal spellings and posting order for
    distinct accounts, key scoping per book, and the `LIF-003` guards firing on fresh instances,
    through relations, and via `save()` — confirming execution finding 7's `Builder::value()` cast fix
    is real and effective.
  - `git show --format=fuller --no-patch 4f68ba6 f581deb` — authored and committed by the repository
    owner, English, Conventional Commit subjects, no AI attribution trailers.
  - TASK-001's adjusted test (`tests/Feature/Domain/Ledger/JournalTransactionTest.php:57-63`) still
    reaches the `reverses_transaction_id <> id` CHECK constraint it was written to prove; the
    `posted()` → `draft()` change removes only the new guard's interference and weakens no coverage.
  - ARC-003 holds: `MonetaryDecimal`'s new methods introduce no Ledger dependency, and the
    architecture test still enforces it.

- **Follow-ups:** none independent of the findings above. Finding 4 may be deferred to the slice that
  adds a second categorizable posting shape, provided it is recorded then rather than forgotten.

### Round 2 — `done` (against `916ded7`)

Re-validated independently against reality, not against the follow-up record. Every round-1 finding
is closed, each confirmed by re-running the original reproduction rather than by reading the fix.

- **Per-finding results:**

  1. **[P1] Closed.** `RequiresPositiveAmount` is applied to all three intent actions and to the fee
     amount, and it rejects on `isZero() || isNegative()` — evaluated on the already-normalized
     `MonetaryDecimal`, so the `-0` family is caught as zero rather than slipping through a naive
     string check. Re-ran the round-1 reproductions and the full boundary set on both engines:
     `-1000`, `0`, `-0`, `-0.0`, `-0.000000000000000000`, `0.000000000000000000`, and
     `-0.000000000000000001` all throw `NonPositiveAmount` for expense, income, opening balance, and
     fee, leaving the asset at `0.000000000000000000` and no transaction row. The smallest positive
     amount, `0.000000000000000001`, is still accepted and posts correctly — the guard is strict
     positivity, not a magnitude floor. The kernel's same-posting sign rule is untouched, which is
     right: the fix lives at the only layer that knows which side is the funding source.

  2. **[P2] Closed.** The `+5` append reproduction now throws `PostedDataIsImmutable` and leaves the
     transaction at two postings, on both engines. The `Draft` -> `Posted` reordering it required was
     probed for the failure modes it introduces, and holds:
     - a failure on the second posting insert leaves no header and no postings;
     - a failure on the `Draft` -> `Posted` update itself also leaves nothing (verified separately on
       PostgreSQL, whose aborted-transaction semantics differ);
     - an outer-transaction rollback around a successful `handle()` still leaves nothing and
       dispatches nothing;
     - no `Draft` row survives a successful posting, and the returned and persisted status are both
       `Posted`;
     - `LIF-001` holds through the new window: a `Draft` transaction carrying a posting of `999`
       contributes nothing to `Account::postedNativeBalance()`.
     The three adjusted fixtures (`AccountTest.php`, `DeletionRestrictionTest.php`,
     `PostedDataImmutabilityTest.php`) still prove what they originally proved — each asserts a
     behavior of a posting on a *posted* transaction (`ACC-003`/`ACC-004` immutability, restricted
     book deletion, `LIF-003` update/delete rejection), and only the order in which that state is
     reached changed. `PostedDataImmutabilityTest` additionally gained direct coverage of the new
     guard in both directions.

  3. **[P2] Closed.** `postingSortKey()` now folds `native_quantity`, `functional_amount`, and `memo`
     into the key. The round-1 reproduction (`[A:+3, A:+7, C:-10]` replayed with the first two
     swapped) now returns the existing transaction. Probed the surrounding semantics rather than the
     single case: two fully identical posting lines replay correctly; the same swap with a shared
     category replays; a memo-only change on one of two same-account postings still raises
     `IdempotencyConflict`; collapsing two `+5` lines into one `+10` still conflicts; and replay
     across differently spelled decimals still works. Encoding the tuple with `json_encode` rather
     than a delimiter-joined string is the right call given `memo` is arbitrary caller text.

  4. **[P3] Fixed at the kernel, with one residual.** `CategoryNotAllowedOnAccountType` rejects a
     category on any posting whose account is not `Income` or `Expense`; verified rejected on asset
     and equity postings and accepted on income and expense, on both engines. Residual, non-blocking:
     the rule keys on account *type*, and the `Fees` system account is `Expense`-typed, so the kernel
     still accepts a category on a fee posting (verified by probe). ADR-003's "never to fees" remains
     enforced only by `RegisterExpenseAction`, which does so correctly and is tested. This is a
     strict improvement over round 1 and is recorded as a follow-up, not a defect.

  5. **[P3] Fixed.** The `ACC-006` balance read is book-scoped; an asset account from another book,
     and a nonexistent account id, both now surface `CrossBookReference` from the kernel instead of a
     foreign balance read or an unhandled `ModelNotFoundException`.

  6. **[P3] Documented.** Both guarded models now name the uncovered write paths and why they are out
     of scope, mirroring the `MonetaryScale` precedent. The bypass still exists by design; verified
     present and now honestly described.

- **Regression sweep:** the `ACC-006` boundary (equal-to-balance passes, one unit at scale 18 over
  rejects), exact-zero balance at scale 18, category-on-fee suppression in `RegisterExpenseAction`,
  and the after-commit event behavior are all unchanged.

- **Round 2 evidence:**
  - `php artisan test --compact --no-tia` (SQLite) — **141 passed, 269 assertions, 0 failed.**
  - `docker compose up --wait -d` + `composer test:pgsql` (PostgreSQL 17) — **141 tests, 140 passed,
    268 assertions, 1 skipped.** Service torn down afterwards.
  - `vendor/bin/pint --test --format agent` — passed. `vendor/bin/phpstan analyse
    --memory-limit=512M` — 0 errors.
  - All four executor-claimed counts reproduce exactly.
  - 44 temporary validator probes on SQLite plus 12 engine-sensitive probes on PostgreSQL — all
    passing, all removed before this commit. The worktree carries no validator test changes.
  - `git show --format=fuller --no-patch 916ded7 401da40` — repository owner, English, Conventional
    Commit subjects, no AI attribution trailers.

- **Round 2 follow-ups:** one, non-blocking — the kernel's category rule is account-type-based, so a
  category on a `Fees`-role posting passes the kernel. Worth making role-aware in the slice that adds
  a second system-account posting shape (FX, rounding), recorded here so it is not rediscovered.
