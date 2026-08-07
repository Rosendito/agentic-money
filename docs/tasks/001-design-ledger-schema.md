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
- ADR-001 (all monetary columns `DECIMAL(38, 18)`, decimal-string casts, no float casts)
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
- **SQLite precision:** the default connection is SQLite, which does not enforce `DECIMAL(38, 18)`.
  Use `decimal:18` (string) casts, never float casts, and include a round-trip test with a value
  carrying 18 fractional digits.
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
      constraints, and `DECIMAL(38, 18)` monetary/rate columns.
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
- **Commit:** `b01383d` — feat(ledger): add core schema, models, and factories

## Validation

> Filled by the validator.

- **Verdict:** Pending.
- **Findings:** Pending.
- **Evidence:** Pending.
- **Follow-ups:** None.
