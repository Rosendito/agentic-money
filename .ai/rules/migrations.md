---
paths:
  - 'database/migrations/**'
---

# Migrations

## Never use decimal() for high-precision monetary columns on SQLite
Laravel's `$table->decimal()` compiles to a `numeric` column on SQLite, which SQLite gives NUMERIC type affinity. NUMERIC affinity silently coerces well-formed decimal-literal text into an 8-byte IEEE float and truncates precision beyond ~15 significant digits — this happens even when the value is bound as a string, and even via raw `INSERT`. It broke the ADR-001/LIF-011 lossless round-trip for DECIMAL(38,18) values with 18 fractional digits (e.g. `12345.123456789012345678` became `12345.123456789000000000`).

Fix: on the `sqlite` driver, declare the column as `varchar` (TEXT affinity) instead, sized to fit sign + digits + separators (see `monetaryColumn()` helper in `database/migrations/2026_08_07_041348_create_postings_table.php`). Keep `decimal(38, 18)` for MySQL/PostgreSQL, which do not have this coercion. Always add a round-trip test with an 18-fractional-digit value for any new monetary column.
