---
paths:
  - 'database/migrations/**'
---

# Migrations

## Never use decimal() for high-precision monetary columns on SQLite
Laravel's `$table->decimal()` compiles to a `numeric` column on SQLite, which SQLite gives NUMERIC type affinity. NUMERIC affinity silently coerces well-formed decimal-literal text into an 8-byte IEEE float and truncates precision beyond ~15 significant digits — this happens even when the value is bound as a string, and even via raw `INSERT`. It broke the ADR-001/LIF-011 lossless round-trip for DECIMAL(38,18) values with 18 fractional digits (e.g. `12345.123456789012345678` became `12345.123456789000000000`).

Fix: on the `sqlite` driver, declare the column as `varchar` (TEXT affinity) instead, sized to fit sign + digits + separators (see `monetaryColumn()` helper in `database/migrations/2026_08_07_041348_create_postings_table.php`). Keep `decimal(38, 18)` for MySQL/PostgreSQL, which do not have this coercion. Always add a round-trip test with an 18-fractional-digit value for any new monetary column.

## Add SQLite CHECK constraints by splicing sqlite_master, not ALTER TABLE
SQLite has no `ALTER TABLE ADD CONSTRAINT`, and Laravel's Blueprint has no `check()` method on any grammar, so a CHECK constraint (closed-vocabulary columns, canonical decimal syntax, self-reference guards) must be part of the original `CREATE TABLE` statement on SQLite.

Pattern used in `create_books_containers_and_accounts_tables.php` and `create_journal_transactions_and_postings_tables.php` (see private `addSqliteCheckConstraints()` in both): after `Schema::create()` builds the table normally, read the table's own compiled SQL back from `sqlite_master`, `DROP TABLE` it (safe only because it is freshly created and empty), reissue that same SQL with `, CHECK (...)` clauses spliced in before the final `)`, then reissue any named indexes captured from `sqlite_master` (type='index'), since `DROP TABLE` cascades and drops them. This avoids hand-duplicating columns/FKs/indexes in a second raw-SQL representation.

Order matters: run this splice for a table BEFORE creating any other table that holds a foreign key into it — you cannot `DROP TABLE` a table that is already referenced by another table's FK.

On MySQL/PostgreSQL, `ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)` works fine as a normal statement after `Schema::create()`; only SQLite needs the splice technique. `IN (...)` vocabulary checks should be portable across all three engines; decimal-syntax CHECKs are only needed on SQLite since MySQL/PostgreSQL enforce it natively via `DECIMAL(38, 18)` (ADR-001).

Migrations must not import application Enum/Model classes for the vocabulary list (frozen-schema-snapshot rule) — hardcode the literal values as of that migration instead.
