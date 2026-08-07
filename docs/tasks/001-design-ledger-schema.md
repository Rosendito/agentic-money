---
id: TASK-001
title: Ledger core schema, models, and factories
status: review
created_at: 2026-08-06
---

# TASK-001: Ledger core schema, models, and factories

## Intention

Create the persistent foundation of the ledger: migrations, Eloquent models, relationships, enums,
and factories for books, instruments, containers, accounts, journal transactions, and postings.
After this task, every later slice (posting service, quotes, obligations, reporting) can build on a
schema that already encodes the locked ledger invariants that a database can enforce locally.

## Context

The repository has a Laravel skeleton and a complete domain specification, but no `app/Domain`
code, migrations, or tasks yet. The blocking open decisions for this slice were resolved in
[ADR-001](../decisions/ADR-001-decimal-precision-and-rounding.md) (decimal policy) and
[ADR-002](../decisions/ADR-002-functional-instrument-immutability.md) (functional-instrument
immutability). Quotes, obligations, and the posting service are separate follow-up tasks.

## Required reading

- [Ledger knowledge base](../README.md)
- [Domain architecture](../09-domain-architecture.md)
- [Domain glossary](../02-domain-glossary.md)
- [Ledger model](../03-ledger-model.md)
- [Accounts and obligations](../05-accounts-and-obligations.md) — containers/accounts sections
- [Integrity and lifecycle](../07-integrity-and-lifecycle.md)
- [ADR-001](../decisions/ADR-001-decimal-precision-and-rounding.md)
- [ADR-002](../decisions/ADR-002-functional-instrument-immutability.md)

## Rules that must remain true

- `LED-003`, `LED-004`, `LED-008`, `LED-011` (no mutable running-balance columns)
- `ACC-001`, `ACC-002`, `ACC-003`, `ACC-004`, `ACC-005`, `ACC-007`
- `LIF-001` (lifecycle states), `LIF-013` (effective vs recorded time), `LIF-016` (book isolation)
- `ARC-001`, `ARC-002`, `ARC-003` (Money has no Ledger dependency), `ARC-006`
- ADR-001 as amended (exact decimal representation: `DECIMAL(38, 18)` on MySQL/PostgreSQL,
  CHECK-constrained TEXT-affinity columns on SQLite; decimal-string casts; no float casts; no
  float-produced monetary values anywhere, including factories)
- ADR-002 (book functional instrument immutable once posted transactions exist)

## Scope

Tables and models, with ownership per `09-domain-architecture.md`:

| Concept            | Module namespace                              | Notes                                                                                                             |
| ------------------ | --------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| Instrument         | `App\Domain\Money\Models\Instrument`          | Code not limited to 3 chars; seed at least USDT, USD, VES, USDC, EUR                                              |
| Book               | `App\Domain\Ledger\Models\Book`               | Owner user, functional instrument reference                                                                       |
| Container          | `App\Domain\Ledger\Models\Container`          | Book-scoped, user-facing grouping only                                                                            |
| Account            | `App\Domain\Ledger\Models\Account`            | Book, optional container, account type, immutable native instrument, system role, archived state                  |
| JournalTransaction | `App\Domain\Ledger\Models\JournalTransaction` | Book, status, effective/recorded time, description, idempotency key, reversal link, correction group              |
| Posting            | `App\Domain\Ledger\Models\Posting`            | Transaction, account, signed native quantity, signed functional amount, optional memo/category dimension deferred |

The idempotency scope (`LIF-008`) for manual posting commands is the book:
`UNIQUE (book_id, idempotency_key)` on journal transactions. Provider-event uniqueness (`LIF-010`)
belongs to the future external-import tables, not to this schema.

Also in scope: enums (account type, transaction status, system account role), factories under
`database/factories/Domain/...` with useful states, `LedgerServiceProvider`/`MoneyServiceProvider`
only if a real binding or boot responsibility exists (`ARC-002`, `ARC-006`), and feature tests
under `tests/Feature/Domain/...` proving the constraints below.

## Design and hidden risks

- **Stored status vocabulary:** `Reversed` is a derived condition, not a stored status. Stored
  transaction statuses are Draft, Pending, Posted, Cancelled; reversal is expressed by a posted
  transaction referencing the original.
- **Database-enforceable facts** (from `03-ledger-model.md`, validation responsibilities): valid
  instrument references, `UNIQUE (book_id, idempotency_key)` on journal transactions, lifecycle
  state validity, reversal self-links, decimal columns per ADR-001. Aggregate rules (balance,
  minimum two postings, sign correctness) belong to the posting service — do not attempt them as
  database constraints here.
- **Cross-book integrity (`LIF-016`) is enforced by the database.** Child rows carry `book_id` and
  reference their parents through composite foreign keys, which SQLite enforces and Laravel's
  `Blueprint::foreign()` supports with column arrays:
  - `postings (journal_transaction_id, book_id)` → `journal_transactions (id, book_id)`;
  - `postings (account_id, book_id)` → `accounts (id, book_id)`;
  - `accounts (container_id, book_id)` → `containers (id, book_id)` (when a container is set);
  - `journal_transactions (reverses_transaction_id, book_id)` → `journal_transactions (id, book_id)`.
  Parent tables need a composite unique index on `(id, book_id)` to be valid FK targets. Model
  guards may exist as a second line of defense, never as the only barrier. Verify
  `foreign_key_constraints` stays enabled on the SQLite connection.
- **SQLite precision:** SQLite's NUMERIC affinity coerces decimal literals to 8-byte floats, so
  monetary columns follow the amended ADR-001: per-engine definition with TEXT-affinity columns
  plus CHECK constraints (canonical decimal syntax, max scale 18) on the `sqlite` driver and real
  `decimal(38, 18)` elsewhere. Use `decimal:18` (string) casts, never float casts, and include a
  round-trip test with a value carrying 18 fractional digits. Factories and seeders supply decimal
  strings, never `randomFloat()`.
- **Immutability guards:** ADR-002 defines functional-instrument immutability as a
  domain/application-layer guarantee — implement it as a model-level guard proven by tests, not a
  database trigger. `ACC-003`/`ACC-004` (account instrument/type frozen once the account has
  postings) get the same model-level treatment. Full posted-transaction immutability (`LIF-003`)
  is finished by the posting-service task, but the schema must not invite mutation (for example,
  no balance columns to keep in sync).
- **Migration workflow:** the root `README.md` declares `MVP development`, so migrations for this
  slice may be edited in place and rebuilt with `migrate:fresh` while the task is under review.
  Follow the `laravel-migration-workflow` skill, including its table-grouping criteria.
- **No accounting behavior:** this task creates no posting, reversal, or book-initialization
  services and no way to produce balanced transactions. Factories may fabricate postings for
  constraint tests only; that is acceptable because `LED-015` governs application interfaces, not
  test setup.
- **System accounts:** add the role column and enum, but the controlled book-initialization service
  (`ACC-007`) is a follow-up task.

## Acceptance criteria

- [x] Migrations create the six tables with the documented columns, foreign keys, unique
      constraints, and monetary columns per the amended ADR-001 (per-engine exact decimal
      representation with SQLite CHECK constraints).
- [x] Models live in their owning module namespaces with relationships, enum casts, and
      decimal-string casts; no monetary attribute uses a float cast.
- [x] A book's functional instrument cannot be changed once a posted transaction exists (test).
- [x] An account's native instrument and type cannot be changed once it has postings (test).
- [x] A duplicate `(book_id, idempotency_key)` pair is rejected, while the same key in another
      book is accepted (test).
- [x] A value with 18 fractional digits survives a write/read round-trip unchanged (test).
- [x] A posting whose account or transaction belongs to a different book is rejected by the
      database through the composite foreign keys, with the same proof for the account–container
      and reversal–original links (test).
- [x] An architecture test proves `App\Domain\Money` has no dependency on `App\Domain\Ledger` or
      `App\Domain\Obligations` (`ARC-003`).
- [x] Every model has a factory with states for the meaningful variants (e.g. system account,
      archived account, posted/draft transaction).
- [x] `php artisan test --compact` and `vendor/bin/pint --dirty --format agent` pass.

## Out of scope

- Posting, reversal, and book-initialization actions or any domain service.
- Quotes, valuation policies, and rate providers (Money slice, next task).
- Counterparties, obligations, and settlements.
- Categories as posting dimensions (add when the expense slice needs them).
- Balances, read models, reporting queries, dashboards, HTTP/Inertia layers.
- External imports, provider event tables, reconciliation.
- Seeding real personal data; only instrument reference data is seeded.

## Execution

> Filled by the executor.

- **Summary:** Implemented the six tables (`instruments`, `books`, `containers`, `accounts`,
  `journal_transactions`, `postings`) with cross-book composite foreign keys, the owning Eloquent
  models (`App\Domain\Money\Models\Instrument`; `App\Domain\Ledger\Models\{Book,Container,Account,
  JournalTransaction,Posting}`), enums (`AccountType`, `TransactionStatus`, `SystemAccountRole`),
  model-level immutability guards for ADR-002 and ACC-003/ACC-004, factories with meaningful states
  under `database/factories/Domain/...`, an `InstrumentSeeder` for the five required reference
  instruments, and feature/architecture tests under `tests/Feature/Domain/Ledger` and
  `tests/Unit/Domain`.
- **Important decisions or deviations:**
  - Recorded time is not a separate column; `journal_transactions.created_at` serves as the
    recorded-time fact (LIF-013), avoiding a redundant timestamp.
  - Discovered and worked around a SQLite-specific defect, not previously documented: Laravel's
    `decimal()` migration column gets NUMERIC type affinity on SQLite, which silently coerces
    well-formed decimal-literal text into an 8-byte float and truncates precision beyond ~15
    significant digits — even when bound as a string, and even via raw `INSERT`. This broke the
    required 18-fractional-digit round-trip. Fixed by declaring `postings.native_quantity` and
    `postings.functional_amount` as `varchar` (TEXT affinity) on the `sqlite` driver only, while
    keeping real `decimal(38, 18)` for MySQL/PostgreSQL (see the `monetaryColumn()` helper in
    `database/migrations/2026_08_07_041348_create_postings_table.php`). Recorded as a durable rule
    in `.ai/rules/migrations.md` for future monetary columns (e.g. `Money\Quote.rate`).
  - `Instrument` intentionally has no `decimal_places`/precision column: `MNY-002` is not among this
    task's "Rules that must remain true," and per-instrument precision policy belongs to the Money
    valuation slice.
  - No `MoneyServiceProvider`/`LedgerServiceProvider` was added: neither module has a real binding
    or boot responsibility yet (`ARC-002`, `ARC-006`).
- **Verification:**
  - `php artisan migrate:fresh --no-interaction` against the local disposable
    `database/database.sqlite` (freshly created, contained no data) — all nine migrations ran
    cleanly, including the composite and self-referencing foreign keys.
  - `php artisan test --compact` — 21 passed, 41 assertions, 0 failed.
  - `vendor/bin/pint --dirty --format agent` — passed, no changes needed.
- **Commit:** `10ed11d` — feat(ledger): add core schema, models, and factories

### Revision 2 (fresh executor, addressing `changes_requested`)

- **Summary:** Fixed every P1/P2 finding from the first validation pass without changing any
  accepted acceptance criterion.
  - **Destructive cascades (P1):** `books.user_id`, `books.functional_instrument_id`,
    `containers.book_id`, `accounts.book_id`, `accounts.native_instrument_id`, and
    `journal_transactions.book_id` now use `restrictOnDelete()` instead of `cascadeOnDelete()`.
    Composite foreign keys (`postings`→`journal_transactions`/`accounts`, `accounts`→`containers`,
    `journal_transactions` self-reference) already defaulted to restrictive behavior and were left
    unchanged. Added `tests/Feature/Domain/Ledger/DeletionRestrictionTest.php` proving a user with a
    book, a book with an account, a book with a posted transaction, and a book with a posting all
    reject deletion with a `QueryException` instead of cascading.
  - **SQLite monetary representation (P1):** Implemented the amended ADR-001 exactly: real
    `DECIMAL(38, 18)` on MySQL/PostgreSQL (unchanged), `varchar` TEXT-affinity columns on SQLite
    (unchanged), now additionally protected on SQLite by a CHECK constraint enforcing canonical
    decimal syntax (optional sign, digit run, optional `.` plus 1–18 fractional digits) built from
    SQLite string functions (`GLOB`, `substr`, `length`, `instr` — no `REGEXP`, which SQLite lacks
    without an extension). `PostingFactory` no longer calls `randomFloat()`; it assembles decimal
    strings from `numberBetween()` integers only. Added three failure-path tests (malformed syntax,
    non-numeric, more than 18 fractional digits) and one factory-output test proving the default
    factory never produces a float, all in `PostingTest.php`.
  - **Unconstrained lifecycle vocabularies (P1):** Added portable `CHECK (... IN (...))` constraints
    for `accounts.type`, `accounts.system_role`, and `journal_transactions.status`, plus
    `CHECK (reverses_transaction_id IS NULL OR reverses_transaction_id <> id)` for LIF-004. Since
    SQLite has no `ALTER TABLE ADD CONSTRAINT` and Laravel's `Blueprint` has no `check()` method on
    any grammar, SQLite CHECK constraints are added by reading the table's own compiled
    `CREATE TABLE` SQL back from `sqlite_master` right after `Schema::create()`, dropping the
    freshly created (still empty) table, and reissuing that same SQL with the CHECK clauses spliced
    in before the closing parenthesis (see private `addSqliteCheckConstraints()` in both new
    migrations). This avoids hand-duplicating columns/indexes/foreign keys in a second raw-SQL
    representation. MySQL/PostgreSQL use a plain `ALTER TABLE ... ADD CONSTRAINT ... CHECK` after
    creation instead, since they support it directly. The vocabulary values are literal strings
    matching the current enums, not imports of the enum classes, per the "migrations do not
    reference application code" rule. Recorded the SQLite-splice technique as a durable rule in
    `.ai/rules/migrations.md`. Added failure-path tests for all four constraints across
    `AccountTest.php` and `JournalTransactionTest.php`.
  - **Static analysis gate (P1):** Added `@return BelongsTo<Related, $this>` / `HasMany<Related,
    $this>` PHPDoc to every relation method across all five ledger/money models. Fixed
    `BookFactory`'s Faker concatenation by narrowing `words()`'s `array|string` return with an
    `is_string()` guard instead of a cast (PHPStan rejects casting `array|string` to `string`
    directly, since the union genuinely can be an array). Fixed `PostingFactory`'s two
    `argument.type` errors by replacing `Book::findOrFail($id)` (typed `Book|Collection`) with
    `Book::query()->whereKey($id)->firstOrFail()` (typed `Book`), which also let `->for()` receive a
    concrete `Book` instead of a union. `composer run types:check` now passes with zero errors.
  - **Migration fragmentation (P2):** Replaced the six single-table migration files with three
    batches: `2026_08_07_041343_create_instruments_table.php` (unchanged, a shared reference table
    kept on its own per the migration-workflow skill), `2026_08_07_045203_create_books_containers_
    and_accounts_tables.php`, and `2026_08_07_045204_create_journal_transactions_and_postings_
    tables.php`. Each groups tables that share one creation order and one clear `down()` reversal in
    dependency order. Used `php artisan make:migration --no-interaction` to generate the new files
    at fresh timestamps, then removed the five superseded files with `git rm`.
- **Important decisions or deviations:**
  - CHECK-constraint vocabulary values are hardcoded literals in the migrations (not enum-class
    imports), to keep migrations independent of application code that may change later, per
    `.ai/rules/migrations.md`. A future enum change requires its own migration to update the
    constraint — this is the same trade-off the project already accepts for other frozen schema
    facts.
  - The canonical-decimal-syntax CHECK is SQLite-only, matching the amended ADR-001 explicitly:
    "SQLite CHECK constraints are the local safety net"; MySQL/PostgreSQL enforce the same fact
    natively through `DECIMAL(38, 18)` and gain no CHECK constraint.
  - Left `native_instrument_id`/`functional_instrument_id` foreign keys pointed at `instruments`
    with `restrictOnDelete()` (an incidental strengthening alongside the required fixes), since an
    instrument backing a live book or account must not silently cascade away either.
- **Verification:**
  - `php artisan migrate:fresh --no-interaction` against the local disposable
    `database/database.sqlite` — all six migrations ran cleanly, including the composite,
    self-referencing, and CHECK-constrained tables. Confirmed via `.env` (`DB_CONNECTION=sqlite`,
    file-based) that this is not a production database.
  - `php artisan test --compact` — 33 passed, 60 assertions, 0 failed (up from 21 passed/41
    assertions; 12 new tests cover the five findings).
  - `vendor/bin/pint --dirty --format agent` — passed, no changes needed.
  - `composer run types:check` (`vendor/bin/phpstan analyse --memory-limit=1G`, the default 128M
    parallel-worker limit was insufficient locally) — 0 errors (down from 21).
  - `php artisan db:seed --no-interaction` — `InstrumentSeeder` still runs cleanly against the
    rebuilt schema.
- **Commit:** `89f86a2` — fix(ledger): address schema validation findings

## Validation

> Filled by the validator.

- **Verdict:** Changes requested.
- **Findings:**
  - **P1 — Destructive cascades can erase posted history.** `books.user_id`, plus the direct
    book-owned foreign keys, use cascading deletes. Deleting a user or book can therefore remove
    accounts, posted transactions, and postings, contrary to `ACC-005` and `LIF-007`. Core ledger
    history must use restrictive/no-action delete behavior, with tests proving posted history
    cannot disappear through a parent deletion.
  - **P1 — The SQLite monetary representation is an unapproved ADR-001 deviation.** The active
    SQLite schema stores `native_quantity` and `functional_amount` as `varchar`, while the task and
    accepted ADR require `DECIMAL(38, 18)`. The workaround preserves one round trip but does not
    enforce numeric syntax, range, or scale, and `PostingFactory` supplies binary floats through
    `randomFloat()`. Resolve the storage contradiction explicitly at the ADR/task level and keep
    every monetary factory input as a decimal string.
  - **P1 — Database lifecycle validity is not enforced.** `journal_transactions.status`, account
    type, and system role are unconstrained strings, so invalid closed-vocabulary values can be
    inserted directly despite the task naming lifecycle validity as a database-enforceable fact.
    Add portable constraints and failure-path tests. Also reject a transaction that references
    itself as its own reversal because `LIF-004` requires a new event.
  - **P1 — The project static-analysis gate fails.** `composer run types:check` reports 21 errors:
    missing Eloquent relation generics across the five ledger models, one invalid Faker
    concatenation in `BookFactory`, and two factory relationship type errors in `PostingFactory`.
  - **P2 — The migration files are more fragmented than the project convention.** In MVP mode,
    rewrite the six domain migrations as three coherent batches: instruments; books/containers/
    accounts; and journal transactions/postings. These groups have clear names, dependency order,
    and reversible boundaries.
- **Evidence:** `php artisan migrate:fresh --no-interaction` passed all nine migrations;
  `php artisan test --compact` passed 21 tests and 41 assertions; `vendor/bin/pint --dirty --format
  agent` passed; `composer run types:check` failed with 21 errors. Source review confirmed the
  cascade paths, unconstrained vocabularies, SQLite `varchar` monetary columns, and float factory
  inputs.
- **Follow-ups:** A fresh executor should address every finding and return the task to `review`; a
  fresh validator should re-run the full project checks and independently inspect the revised
  schema.
