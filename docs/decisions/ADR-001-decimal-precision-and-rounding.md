# ADR-001: Decimal precision and rounding policy

## Status

Accepted (2026-08-06, product decision by the book owner).

## Context

`LIF-011` and `LIF-012` require exact functional balance at the storage scale and named rounding
boundaries, but the exact database precision and scale for native quantities, functional amounts,
and rates was an open decision blocking schema implementation (`07-integrity-and-lifecycle.md`,
"Open — Exact decimal policy"). The ledger must support crypto instruments with up to 18 decimal
places and large VES nominal amounts without silent truncation.

## Decision

- Native quantities, functional amounts, and rates use a single high-precision decimal type:
  `DECIMAL(38, 18)` (38 total digits, 18 fractional digits).
- Monetary values cross application boundaries as decimal strings or decimal value objects, never
  binary floats (`float`/`double` casts are forbidden for monetary columns).
- Intermediate calculations keep at least 18 fractional digits and round only at named boundaries
  (`LIF-012`): quote calculation, functional conversion, provider precision, settlement, and
  display.
- The default rounding mode at each named boundary is round-half-up. A boundary that needs a
  different mode must declare it explicitly.
- Display precision is a presentation concern and never feeds back into stored values.
- The maximum permitted explicit rounding adjustment is a posting-kernel policy and will be fixed
  in the posting-service decision, not here. Until then, no automatic rounding posting exists.

## Alternatives considered

- `DECIMAL(24, 8)` (exchange-style): sufficient for USDT/USD/VES today but caps future instruments
  with more than 8 decimals.
- Per-instrument scaled integers (minor units): maximally exact but adds scaling complexity to
  value objects, SQL aggregation, and cross-instrument queries without a current need.

## Consequences

- One uniform column definition for all monetary columns simplifies migrations, casts, and value
  objects.
- SQLite (the current default connection) does not enforce `DECIMAL` precision or scale; exactness
  depends on storing values with string/decimal casts and on tests asserting lossless round-trips
  of 18-fractional-digit values. A future move to PostgreSQL/MySQL gains real database-level
  enforcement without a schema redesign.
- Model casts use `decimal:18` (string-based); comparisons in domain code use decimal math (for
  example BCMath), never float comparison.

## Affected rules and scenarios

- Resolves the "Open — Exact decimal policy" block in `07-integrity-and-lifecycle.md`.
- Resolves known open decision 3 in `docs/README.md`.
- Constrains `LED-004`, `LED-011`, `LIF-011`, `LIF-012` implementations.

## Validation notes

- Feature tests must prove that an 18-fractional-digit value survives a write/read round-trip
  unchanged.
- An architecture or model test should assert no monetary attribute uses a float cast.
