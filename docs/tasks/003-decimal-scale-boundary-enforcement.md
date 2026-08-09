---
id: TASK-003
title: Enforce decimal scale at the application boundary
status: done
created_at: 2026-08-08
---

# TASK-003: Enforce decimal scale at the application boundary

## Intention

Make over-scale monetary input behave identically on every database engine, and make that behavior
a deliberate, named decision rather than an accident of the storage engine.

## Context

TASK-002 put PostgreSQL in CI precisely so engine-level precision behavior would stop being
theoretical. It immediately surfaced a divergence, confirmed empirically against a live PostgreSQL
instance during validation:

| Input (19 fractional digits) | SQLite | PostgreSQL |
| --- | --- | --- |
| `'1.1234567890123456789'` | rejected by CHECK constraint | accepted, silently stored as `1.123456789012345679` |

Malformed and non-numeric input (`'12.34.56'`, `'not-a-number'`) is rejected by both engines, so
that half of the behavior already agrees.

Two problems follow from the over-scale row:

1. **Rounding happens at an unnamed boundary.** `LIF-012` requires that quote calculation,
   functional conversion, provider precision, settlement, and display each declare their rounding
   mode and scale. A write to disk is none of those. PostgreSQL is applying round-half-even at a
   boundary nobody declared.
2. **The authoritative engine is the permissive one.** ADR-001 designates PostgreSQL/MySQL as the
   authoritative precision enforcement and SQLite's CHECK constraints as "the local safety net".
   For this case the relationship is inverted: the safety net is stricter than the authority. The
   skip message in `tests/Feature/Domain/Ledger/PostingTest.php` asserts that MySQL/PostgreSQL
   "enforce this natively through DECIMAL(38, 18)", which is true for syntax and false for scale.

The ledger currently has no application-level guard, so whichever engine is underneath decides.

## Decision

**Resolved (2026-08-08, product decision by the book owner): reject over-scale input at the
application boundary.** A monetary value carrying more than 18 fractional digits raises an error;
it is never rounded on the way to storage. Storage is not a rounding boundary.

Rationale: a 19-decimal amount reaching the ledger is a bug or a mis-scaled feed, and absorbing it
quietly is the same class of harm `LIF-011` forbids for balance tolerance. A provider that
legitimately emits more precision is handled at the *provider precision* boundary already named in
`LIF-012`, inside that adapter, with its rounding mode declared and tested.

Rounding at a new ingestion boundary was considered and rejected: it adds one more place where a
value silently changes. Leaving the divergence in place was rejected outright, since it makes
correctness depend on which engine happens to be running.

This decision is already recorded in [ADR-001](../decisions/ADR-001-decimal-precision-and-rounding.md)
(amendment of 2026-08-08). The executor implements it; it does not re-open it.

## Required reading

- [Ledger knowledge base](../README.md)
- [Domain architecture](../09-domain-architecture.md)
- [ADR-001](../decisions/ADR-001-decimal-precision-and-rounding.md)
- [Integrity and lifecycle](../07-integrity-and-lifecycle.md)
- [TASK-002](002-ci-real-database-validation.md), whose validation section records the finding

## Rules that must remain true

- ADR-001 (per-engine exact decimal representation; values cross boundaries as decimal strings or
  decimal value objects, never binary floats)
- `LIF-011` (no silent tolerance), `LIF-012` (rounding only at named boundaries with a declared mode)

## Design and hidden risks

- The guard belongs where every write passes through, so no caller can bypass it by constructing a
  model directly. Placing it only in a form request or a service method would leave factories,
  seeders, and future importers unguarded — and factories are exactly what the regression tests use.
- Do not reach for float arithmetic to count fractional digits. Scale must be determined from the
  decimal string or decimal value object, per ADR-001's prohibition on `float`/`double` for
  monetary values.
- Every monetary column that exists today is in scope — `native_quantity` and `functional_amount` —
  and a guard covering only one of them leaves the divergence alive on the other. Rate columns do not
  exist yet; the guard must be the path any future monetary or rate column also passes through, so
  quotes cannot reintroduce the divergence later.
- **The application guard is necessarily the only boundary on PostgreSQL, and that is not a
  violation of `SCP-015`.** A `DECIMAL(38, 18)` column rounds the value before any CHECK constraint
  can observe it, so no database-level rejection of over-scale input is possible there. SQLite keeps
  its CHECK constraint as a second layer. Record this explicitly so the guard is not read as relying
  on a model observer where a database constraint was available.
- **The existing driver skip is too broad.** `skipUnlessSqlite()` skips all three constraint tests
  on PostgreSQL, but two of them (malformed syntax, non-numeric) pass there unchanged. Narrow the
  skip to the over-scale case only, so PostgreSQL stops silently forfeiting coverage it already
  satisfies.
- Regression coverage must run on **both** engines and assert identical behavior. A test that only
  proves the guard works on SQLite reproduces the original defect.

## Acceptance criteria

- [x] Over-scale monetary input is rejected with a clear error on both SQLite and PostgreSQL, and
      the two engines behave identically.
- [x] The guard cannot be bypassed by writing through a factory, seeder, or model directly.
- [x] Both existing monetary columns, `native_quantity` and `functional_amount`, are covered.
- [x] The skip in `PostingTest.php` is narrowed to the over-scale case, and its message no longer
      claims PostgreSQL enforces scale natively.
- [x] Regression tests assert the behavior on both engines and pass in both CI matrix legs. *(Both
      engines verified locally by the validator, including a real PostgreSQL 17; the CI legs
      themselves remain unrun because no PR was opened — see the final validation record.)*
- [x] No new rounding boundary is introduced. `LIF-012`'s five named boundaries stay as they are;
      this task adds a rejection, not a rounding step.

## Out of scope

- The maximum permitted explicit rounding adjustment, which ADR-001 defers to the posting-kernel
  decision.
- Changing the stored precision or scale away from 38/18.
- Provider-specific precision handling in import adapters; this task fixes the ledger's own
  boundary, not any particular feed.
- MySQL. ADR-001 names it as an alternative authoritative engine, but CI runs PostgreSQL and adding
  a third matrix leg is not justified by this finding.

## Execution

> Filled by the executor.

- **Summary:** Added `App\Domain\Money\ValueObjects\MonetaryDecimal`, an immutable value object
  whose `fromString()` counts fractional digits directly from the decimal string (regex, never
  float arithmetic) and throws `App\Domain\Money\Exceptions\ExcessiveDecimalScale` above 18. Added
  `App\Domain\Money\Casts\MonetaryScale`, an Eloquent `CastsAttributes` implementation that runs the
  value object on `set()` and pads the fractional part to 18 digits (string padding only) on `get()`
  to preserve the existing `decimal:18` read behavior. Replaced the `decimal:18` casts on
  `Posting::$native_quantity` and `Posting::$functional_amount` with `MonetaryScale::class`. Because
  the guard lives in the cast invoked by Eloquent's `setAttribute()`, it runs identically for mass
  assignment, factories, seeders, and direct model construction — there is no write path around it,
  and it is the one place any future monetary or rate column would also pass through by attaching
  the same cast. Corrected a migration comment that claimed PostgreSQL enforces the 18-digit scale
  natively (it enforces canonical decimal *syntax* natively but silently rounds excess *scale*).
  Narrowed `PostingTest`'s `skipUnlessSqlite()` to the one assertion that is genuinely SQLite-only —
  a raw `DB::table('postings')->insert()` that bypasses the new application guard to prove SQLite's
  CHECK constraint still works as a redundant second layer — and rewrote its message, which
  previously and incorrectly claimed PostgreSQL "enforces this natively through DECIMAL(38, 18)".
  The malformed-syntax and non-numeric tests now run unskipped on both engines. Replaced the old
  SQLite-only "database rejects over-scale" test with an unskipped, dataset-driven test asserting
  `ExcessiveDecimalScale` for both `native_quantity` and `functional_amount`, plus a test proving the
  guard fires on direct model construction (`new Posting([...])`), not just through the factory.

- **Important decisions or deviations:**
  1. The guard is implemented as a reusable Eloquent cast rather than a per-model `saving` hook
     (the pattern already used in `Account` for ACC-003/004). A cast is attachable to any current or
     future monetary/rate column across any model with one line in `casts()`, whereas a `saving`
     hook would need re-implementing (or abstracting into a trait) per model. This directly serves
     the task's requirement that "the guard must be the path any future monetary or rate column also
     passes through."
  2. `MonetaryScale::get()` pads the fractional part to 18 digits via string operations (no
     arithmetic, no float conversion) to preserve the exact read behavior of the previous
     `decimal:18` cast (which rounded to scale 18 via `BigDecimal` on every read). Since the write
     guard already rejects anything over scale 18, this padding never rounds or discards data — it
     only adds trailing zeros to shorter values, e.g. `"100"` reads back as `"100.000000000000000000"`,
     matching prior behavior and keeping `AccountTest`'s and the round-trip test's assertions
     unchanged.
  3. `MonetaryDecimal::fractionalDigits()` reports scale `0` for a string that is not canonical
     decimal syntax (multiple dots, non-numeric characters) rather than attempting to guess. Syntax
     enforcement remains the database's job (SQLite CHECK constraints, PostgreSQL's native
     `invalid input syntax` rejection); this value object polices scale only, per the task's
     out-of-scope note that provider-specific and syntax handling stay elsewhere.
  4. Kept one SQLite-only test (`sqlite rejects an over-scale monetary value written directly to the
     table as a redundant safety net`) that bypasses Eloquent via `DB::table()->insert()` to prove
     the CHECK constraint independently still rejects over-scale input, per the task's design
     guidance that "SQLite keeps its CHECK constraint as a second layer." This is a deliberate
     addition beyond the acceptance criteria's minimum, not a re-opening of the decision.
  5. Corrected the migration's inline comment on the same false "PostgreSQL enforces this natively"
     claim for scale (distinct from syntax, which it does enforce natively). Not called out
     explicitly by name in the acceptance criteria, but the acceptance criteria target exactly this
     factual error in `PostingTest.php`, and leaving an identical error in the migration file that
     produced the constraint would recreate the same misleading claim one file over.

- **Verification:**
  - SQLite (local, PHP 8.5.8, `:memory:`): `php artisan test --compact` — 50/50 passed (93
    assertions), including the new `MonetaryDecimalTest` (7 tests) and the rewritten `PostingTest`
    (12 tests, 0 skipped).
  - PostgreSQL 17 (local, ephemeral `docker run postgres:17` on port 55432): `php artisan test
    --compact` — 50 tests, 49 passed (92 assertions), 1 skipped. The single skip is the
    SQLite-only redundant-safety-net test (confirmed via `grep -rn markTestSkipped tests` — it is
    the only skip site in the suite). All over-scale, malformed-syntax, and non-numeric assertions
    passed identically to the SQLite run, including the new `ExcessiveDecimalScale`-based tests for
    both `native_quantity` and `functional_amount` and the direct-construction bypass test.
  - `vendor/bin/pint --dirty --format agent` — ran and applied formatting fixes to the new
    `MonetaryDecimal.php` (unary operator spacing, empty-body style); clean on a second run.
  - `vendor/bin/phpstan analyse --memory-limit=512M` — 0 errors (this machine's default 128M
    `php.ini` memory_limit is too low for PHPStan's parallel workers, a pre-existing local
    constraint noted in TASK-002, not fixed here as it is per-machine).
  - `composer ci:check` — pnpm `lint:check`/`format:check`/`types:check` all passed; Pint passed;
    PHPStan failed only due to the local 128M memory limit above, resolved by rerunning with
    `--memory-limit=512M` directly.
  - The existing architecture test (`no monetary attribute uses a float or double cast`) and
    `AccountTest`'s hardcoded `'10.000000000000000000'` fixtures were re-run and pass unchanged,
    confirming the cast swap did not alter existing read/write semantics for in-scale values.
  - Not run: real CI on both matrix legs (no PR opened per instructions; this is delegated to CI on
    the eventual PR, matching how the guard was independently verified against a real, separately
    provisioned PostgreSQL 17 instance above rather than the CI pipeline itself).

- **Commit:** `83e3eb0` (guard implementation and tests), plus this document update.

### Follow-up: address validation findings 1-4 (2026-08-08)

- **Summary:**
  1. **[P1] `MonetaryDecimal` is now the sole authority on the accepted decimal form**, not just on
     scale. `fromString()` first matches the full literal against a canonical-decimal pattern
     (optional sign, digits, optional `.` followed by digits — no exponent, no whitespace, no
     missing integer part) and throws a new `App\Domain\Money\Exceptions\MalformedMonetaryDecimal`
     for anything that does not match, before ever computing a fractional-digit count. This closes
     the gap the validator found: `'1.1234567890123456789e0'`, `' 1.1234567890123456789'`,
     `'.1234567890123456789'`, and `'1e-19'` are all forms PostgreSQL's `numeric` accepts (and
     silently rounds or, for the last one, collapses to zero) while SQLite's CHECK rejects them; all
     four are now rejected by the value object itself, identically on every engine, before either
     engine is reached. This is a deliberate decision to *reject*, not normalize, non-canonical
     forms — consistent with the task's own decision that storage is never a rounding boundary, and
     avoiding a second silent-normalization step that ADR-001 already rejected as an option for
     over-scale input.
  2. **[P2] Corrected the overstated "no caller can bypass" claim.** `MonetaryScale`'s docblock now
     states precisely which write paths the cast covers (Eloquent attribute assignment: mass
     assignment, factories, seeders, direct property assignment) and explicitly names the paths it
     does not cover — `Model::query()->update()`, `insert()`, `upsert()`, and raw SQL — as the same
     class of below-the-boundary gap the task itself already treats `DB::table()` as. Added a
     feature test (`an update issued through the query builder bypasses the model cast...`) that
     documents the gap on both engines rather than silently leaving it undocumented: on SQLite the
     CHECK constraint still catches it; on PostgreSQL it does not, and the test records that
     directly by asserting the persisted value no longer matches the over-scale input. Left the gap
     open rather than closing it with a model-level guard: the ledger has no service/action layer
     yet that performs bulk query-builder updates on postings (LIF-003/LIF-004 route corrections
     through reversal-and-replacement on the model, not bulk updates), so a guard for a write path
     nothing in the codebase uses yet would be speculative; closing it is better deferred to whatever
     posting-kernel or correction action introduces the first real caller of that path.
  3. **[P2] `MonetaryScale::set()` now rejects any value that is not a string or an
     already-validated `MonetaryDecimal`** before it ever reaches `(string)` coercion, via a new
     `App\Domain\Money\Exceptions\InvalidMonetaryValueType`. This closes the float-truncation gap:
     previously `(string) $value` ran before the scale check, so `0.1 + 0.2` (a float) silently
     stringified to `"0.3"` and passed. Floats, ints, bools, and arrays are now all rejected loudly.
  4. **[P3] Corrected the same syntax/scale conflation in `.ai/rules/migrations.md`** (the sentence
     ending "...MySQL/PostgreSQL enforce it natively via `DECIMAL(38, 18)` (ADR-001)"), matching the
     fix already applied to the migration file's inline comment and the `PostingTest.php` skip
     message.

- **Important decisions or deviations:**
  1. Making the canonical-syntax check absolute (finding 1) means the value object now also rejects
     inputs that used to reach the database and fail there — `'12.34.56'` and `'not-a-number'` now
     throw `MalformedMonetaryDecimal` at the application boundary instead of `QueryException` from
     the database. This is a direct, intended consequence of finding 1's own resolution ("the value
     object [must be] the authority on the accepted decimal form"): syntax and scale cannot be split
     between "the app checks scale, the database checks syntax" once it's established that the two
     engines don't even agree on syntax. Updated the two existing tests (`the application rejects a
     monetary value with malformed decimal syntax on every engine`, `... that is not numeric on
     every engine`) to assert the new exception type; they still run unskipped on both engines.
  2. Did not attempt to special-case `int` as an accepted `set()` input alongside `string` and
     `MonetaryDecimal`. The validator's instruction was to accept only "non-string/non-MonetaryDecimal"
     — read literally, an `int` is also rejected. This matches how every existing call site (factories,
     tests, the round-trip test) already only ever supplies decimal strings; no code in the repository
     relies on assigning a bare `int` to a monetary attribute, so narrowing to exactly `string` or
     `MonetaryDecimal` does not break anything and keeps the accepted-input surface as small as
     possible.
  3. Fixed an unrelated bug surfaced while writing the query-builder test: `DB::table('postings')
     ->whereKey($id)` silently compiled to `where "key" = $id` (the base query builder has no
     `whereKey()` method and falls back to Eloquent-style dynamic-where magic, treating "Key" as a
     literal column name) rather than raising an error, which produced a wrong-column runtime SQL
     error on PostgreSQL. Replaced with `->where('id', $id)`. `Posting::query()->whereKey(...)` in
     the same test is unaffected — that one is a true Eloquent builder, which does implement
     `whereKey()`.

- **Verification:**
  - SQLite (local, PHP 8.5.8, `:memory:`): `php artisan test --compact` — 64/64 passed (107
    assertions). Test count grew from 50 to 64: +7 `MonetaryDecimalTest` cases from the malformed
    dataset (replacing 1), +4 non-canonical-literal-form cases, +2 float-rejection cases, +1
    query-builder-bypass documentation test.
  - PostgreSQL 17 (local, ephemeral `docker run --rm -d -p 55432:5432 -e POSTGRES_USER=postgres -e
    POSTGRES_PASSWORD=postgres -e POSTGRES_DB=testing postgres:17`, matching
    `.github/workflows/tests.yml`'s service container and env overrides): `DB_CONNECTION=pgsql
    DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=testing DB_USERNAME=postgres DB_PASSWORD=postgres
    php artisan test --compact` — 64 tests, 63 passed (106 assertions), 1 skipped (the SQLite-only
    redundant-safety-net test — the only `markTestSkipped` site in the suite, confirmed via `grep`).
  - Reproduced the validator's exact bypass probes directly (a standalone script bootstrapping the
    app, not committed): all four non-canonical literal forms
    (`'1.1234567890123456789e0'`, `' 1.1234567890123456789'`, `'.1234567890123456789'`, `'1e-19'`)
    now throw `MalformedMonetaryDecimal` from `MonetaryDecimal::fromString()`, and `0.1 + 0.2` now
    throws `InvalidMonetaryValueType` from `MonetaryScale::set()`.
  - `vendor/bin/pint --dirty --format agent` — applied minor formatting to the two new exception/
    value-object files; `vendor/bin/pint --test --format agent` clean on the final state.
  - `vendor/bin/phpstan analyse --memory-limit=512M` — 0 errors. Two errors surfaced mid-fix from the
    cast's `@implements CastsAttributes<string|null, string|null>` generic annotation making
    PHPStan treat `$value` in `set()` as always `string`, so both the `instanceof MonetaryDecimal`
    and `is_string()` guards looked dead. Corrected the annotation to `CastsAttributes<string|null,
    mixed>` — accurate, since `set()`'s entire job in this class is to accept and validate
    otherwise-untyped input, not a workaround to silence the checker.
  - Re-ran the full SQLite suite after every fix (not just once at the end) to catch regressions
    incrementally; final run is the 64/64 figure above.

- **Commit:** `e6e7d04` (fixes for validation findings 1-4), plus this document update.

### Follow-up: address re-validation findings 5-6 (2026-08-08)

- **Summary:**
  1. **[P2] Anchored `CANONICAL_PATTERN` with PCRE's `D` modifier.** Without it, `$` matches before a
     trailing `\n`, so `"1.5\n"` passed the value object's own syntax check, then diverged exactly
     like every other case this task exists to close: rejected by SQLite's CHECK, accepted (and
     normalized) by PostgreSQL. `App\Domain\Money\ValueObjects\MonetaryDecimal::CANONICAL_PATTERN` is
     now `/^[+-]?\d+(\.(\d+))?$/D`, anchoring `$` to the true end of the subject. Extended the
     malformed-input dataset (`MonetaryDecimalTest` and `PostingTest`) with `"1.5\n"`, `"1.5 "`,
     `"1.5\t"`, and `"1.5\r"` — the last one was already rejected before this fix (it isolated the
     defect to the anchor, not the character class), and now has explicit regression coverage
     alongside the other three.
  2. **[P3] `MonetaryDecimal::fromString()` now canonicalizes losslessly at construction**, per the
     planner's decision: strip a redundant leading `+`, strip redundant leading zeros (keeping one
     integer digit), collapse negative zero to plain zero, and pad the fractional part to scale 18.
     This is normalization, not rounding — the numeric value is unchanged, so `LIF-012`'s five named
     rounding boundaries are untouched; only the *representation* changes, and only for
     representations that were already redundant. This closes the regression the validator found:
     `'+1.5'`, `'007.5'`, and `'-0.0'` are canonical under the accepted grammar, but PostgreSQL's
     `numeric` type normalizes them on write while SQLite's plain text column previously did not, so
     the same accepted literal used to read back differently per engine. Implemented with
     `brick/math`'s `BigDecimal::of($value)->toScale(18, RoundingMode::Unnecessary)` rather than
     hand-rolled string surgery, per the planner's explicit suggestion: `Unnecessary` cannot throw
     here because the literal was already proven to carry at most 18 fractional digits, so widening
     to scale 18 only pads zeros and never rounds. `brick/math` was already an indirect dependency
     (via `laravel/framework`, and the library the previous `decimal:18` cast used internally through
     Laravel's own `HasAttributes::asDecimal()`); since application code now depends on it directly
     rather than only transitively, added `"brick/math": "^0.18"` to `composer.json`'s `require` so
     the dependency is declared honestly instead of relying on it staying available as a side effect
     of Laravel's own requirements.

- **Important decisions or deviations:**
  1. Padding now happens inside `MonetaryDecimal::fromString()` itself (at construction), not only in
     `MonetaryScale::get()` on read. `MonetaryScale::set()` therefore now returns the fully
     normalized, 18-digit-padded canonical string, so SQLite and PostgreSQL store identical bytes for
     the same accepted literal — closing the finding directly rather than papering over it on read.
     `MonetaryScale::get()`'s own `pad()` (fraction-only, no normalization) is kept as a lighter,
     best-effort fallback for rows written through the documented, accepted query-builder/raw-SQL gap
     (finding 2 from the prior round) — a value that reached storage that way was never validated by
     this cast, so it was never normalized either, and `get()` does not try to repeat the full
     validation for it.
  2. Updated three existing `MonetaryDecimalTest` cases (`'-10.5'`, `'100'`, `'1.100'`) whose
     assertions predated normalization and expected the caller's literal to survive unpadded; they
     now assert the padded canonical form (`'-10.500000000000000000'`, `'100.000000000000000000'`,
     `'1.100000000000000000'`). This is a direct, intended consequence of finding 6's resolution, not
     a scope expansion — every value now leaves `MonetaryDecimal` at scale 18.
  3. Did not extend the round-trip or "default factory" tests, since neither uses a non-canonical
     literal; both already assert exact scale-18 strings and are unaffected by normalization.

- **Verification:**
  - Confirmed the exact normalization brick/math produces before wiring it in, via a standalone
    script (not committed): `BigDecimal::of('+1.5')->toScale(18, RoundingMode::Unnecessary)` →
    `'1.500000000000000000'`; `'007.5'` → `'7.500000000000000000'`; `'-0.0'` →
    `'0.000000000000000000'`; `'00.10'` → `'0.100000000000000000'`; confirmed 18-fractional-digit and
    plain-integer inputs pass through unchanged in value.
  - SQLite (local, PHP 8.5.8, `:memory:`): `php artisan test --compact` — 80/80 passed (123
    assertions). Test count grew from 64 to 80: +4 trailing-whitespace malformed cases in
    `MonetaryDecimalTest`, +1 canonicalization dataset test (4 cases) in `MonetaryDecimalTest`, +4
    trailing-whitespace malformed cases in `PostingTest`, +1 canonicalization dataset test (4 cases)
    in `PostingTest`.
  - PostgreSQL 17 (`docker compose up --wait -d` from the main checkout, the project's own committed
    `compose.yaml` service, port 55432, matching `.github/workflows/tests.yml`'s env overrides exactly):
    `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=testing DB_USERNAME=postgres
    DB_PASSWORD=postgres php artisan test --compact` — 80 tests, 79 passed (122 assertions), 1
    skipped (the SQLite-only redundant-safety-net test — confirmed the suite's only
    `markTestSkipped` site). Torn down afterward with `docker compose down`.
  - Reproduced the validator's exact probes directly (a standalone script bootstrapping the app, not
    committed): all four trailing-whitespace forms (`"1.5\n"`, `"1.5 "`, `"1.5\t"`, `"1.5\r"`) now
    throw `MalformedMonetaryDecimal`; `'+1.5'`, `'007.5'`, `'-0.0'`, and `'00.10'` all normalize to
    `'1.500000000000000000'`, `'7.500000000000000000'`, `'0.000000000000000000'`, and
    `'0.100000000000000000'` respectively. This logic lives entirely in the pure-PHP value object, so
    it is engine-independent by construction — the PostgreSQL run above confirms the persisted-value
    behavior end to end rather than re-testing the same PHP logic redundantly.
  - `vendor/bin/pint --dirty --format agent` then `vendor/bin/pint --test --format agent` — clean.
  - `vendor/bin/phpstan analyse --memory-limit=512M` — 0 errors.
  - `composer ci:check`'s pnpm `lint:check`/`format:check`/`types:check` — all passed (untouched by
    this change, re-run for completeness since `composer.json` changed).

- **Commit:** `05934ee` (fixes for re-validation findings 5-6), plus this document update.

## Validation

> Filled by the validator.

- **Verdict:** changes_requested (independent review of `1795986` vs `main`, 2026-08-08).

- **Findings:**
  1. **[P1] The guard recognizes only one decimal literal form, so over-scale input still diverges
     between engines.** `MonetaryDecimal::fractionalDigits()`
     (`app/Domain/Money/ValueObjects/MonetaryDecimal.php:50-57`) matches `/^[+-]?\d+\.(\d+)$/` and
     returns scale `0` for anything else, per the executor's deviation 3 ("syntax enforcement remains
     the database's job"). That premise does not hold, because the two engines do not agree on
     syntax: SQLite's CHECK accepts only the characters `[0-9.+-]` with a leading digit or sign,
     while PostgreSQL's `numeric` additionally accepts exponent notation, surrounding whitespace, and
     a missing integer part. Every literal in that gap carries real scale, is scored as `0` by the
     guard, and reaches storage. Reproduced on both engines through the project's own harness
     (probe test, since removed):

     | Input | SQLite | PostgreSQL 17 |
     | --- | --- | --- |
     | `'1.1234567890123456789'` | `ExcessiveDecimalScale` | `ExcessiveDecimalScale` |
     | `'1.1234567890123456789e0'` | `QueryException` | accepted, stored `1.123456789012345679` |
     | `' 1.1234567890123456789'` | `QueryException` | accepted, stored `1.123456789012345679` |
     | `'.1234567890123456789'` | `QueryException` | accepted, stored `0.123456789012345679` |
     | `'1e-19'` | `QueryException` | accepted, stored `0.000000000000000000` |

     This is the original defect, unchanged, reachable through three ordinary spellings of the same
     number plus one case that loses the value entirely. Acceptance criterion 1 ("rejected on both
     SQLite and PostgreSQL, and the two engines behave identically") is not met, and PostgreSQL is
     still rounding at an unnamed boundary in violation of `LIF-012`. Resolve by making the value
     object the authority on the accepted decimal form — reject any input it cannot parse as a
     canonical decimal instead of scoring it zero-scale — so the boundary's verdict no longer depends
     on which engine's syntax rules apply downstream.

  2. **[P2] Writes through the Eloquent query builder bypass the cast and reintroduce the
     divergence.** `Posting::query()->whereKey($id)->update(['native_quantity' => '1.1234567890123456789'])`
     never invokes `MonetaryScale::set()`. Observed: SQLite rejects it via the CHECK constraint,
     PostgreSQL accepts it and stores `1.123456789012345679`. Criterion 2 as written names factories,
     seeders, and direct construction, and those genuinely pass — but a query-builder update is
     application code above the boundary, not raw SQL below it, so the "no caller can bypass the
     guard" claim in `app/Domain/Money/Casts/MonetaryScale.php:11-15` and in the commit message
     overstates what a cast can provide. `Posting::insert()` and `upsert()` share the gap. Resolve by
     either closing the path (a model-level guard covering builder writes) or narrowing the documented
     claim to the write paths the cast actually intercepts, so the next reader is not misled about the
     coverage.

  3. **[P2] The guard silently accepts binary floats and truncates them.** `MonetaryScale::set()`
     (`app/Domain/Money/Casts/MonetaryScale.php:31-40`) applies `(string) $value` before the scale
     check, so a float is coerced at PHP's `precision` setting and the resulting shorter string passes
     the guard. Observed: `0.1 + 0.2` stores as `0.3`; `1.1234567890123457` stores as
     `1.1234567890123` on SQLite and `1.123456789012300000` on PostgreSQL; `1.0E-19` stores as `0` on
     PostgreSQL. ADR-001 forbids `float`/`double` for monetary values and `SCP-014` keeps binary
     floating-point out of domain contracts; the cast is the one place that could enforce it and
     instead performs an undeclared truncation, which also sits awkwardly against criterion 6 ("no new
     rounding boundary"). The architecture test `no monetary attribute uses a float or double cast`
     (`tests/Unit/Domain/ArchitectureTest.php:14-25`) inspects cast *names* only, so it no longer
     proves anything about the value actually stored. Resolve by rejecting non-string monetary input
     at the boundary rather than stringifying it.

  4. **[P3] The corrected claim survives in the rules file.** `.ai/rules/migrations.md:20` still reads
     "decimal-syntax CHECKs are only needed on SQLite since MySQL/PostgreSQL enforce it natively via
     `DECIMAL(38, 18)`". The executor fixed this same conflation in the migration comment and the skip
     message (deviation 5, correctly reasoned), but the shared rule the next agent reads first was not
     updated with the syntax-versus-scale distinction.

- **Evidence:**
  - Diff surface `main...1795986` inspected in full (8 files, 336 insertions); worktree clean apart
    from the temporary probe test, which was removed before recording this verdict.
  - `php artisan test --compact --no-tia` (SQLite, `:memory:`) — 50 passed, 93 assertions.
  - `DB_CONNECTION=pgsql … php artisan test --compact --no-tia` (PostgreSQL 17, ephemeral
    `docker run postgres:17` on port 55432) — 50 tests, 49 passed, 92 assertions, 1 skipped. Both
    figures match the executor's Verification section exactly.
  - `vendor/bin/pint --test --format agent` — passed. `vendor/bin/phpstan analyse
    --memory-limit=512M` — 0 errors. Both executor claims reproduced.
  - Findings 1-3 reproduced by a temporary Pest feature test run against both engines, asserting on
    the value read back from `DB::table('postings')` rather than on the model, so the observed values
    are the persisted ones. Deleted after the run; no implementation file was touched.
  - The narrowed skip is correct: `skipUnlessSqlite()` now guards exactly one test, the raw
    `DB::table()->insert()` safety-net case, and its message no longer claims PostgreSQL enforces
    scale natively. Confirmed the malformed-syntax and non-numeric tests run unskipped and pass on
    PostgreSQL (`invalid input syntax`), and that the single pgsql skip is the suite's only skip site.
  - Both monetary columns are covered: `grep` confirms `native_quantity` and `functional_amount` are
    the only monetary columns in any migration, no `decimal:` cast remains anywhere in `app/` or
    `database/`, and the dataset-driven rejection test exercises both.
  - Scale counting uses no float arithmetic — `preg_match` plus `strlen` on the captured fraction —
    and `MonetaryScale::pad()` is `explode`/`str_pad` only. Verified `'1.100'` survives unchanged.
  - Rejection of `'1.1000000000000000000'` (19 fractional digits, trailing zeros) is consistent with
    ADR-001's literal wording, "carrying more than 18 fractional digits"; literal scale, not
    significant scale, is the right reading and the implementation matches it.
  - No new rounding boundary is added for string input: `get()` pads with zeros and never rounds, so
    `LIF-012`'s five named boundaries are untouched. (Float input is the exception — finding 3.)
  - `git show --format=fuller --no-patch 83e3eb0 1795986` — author and committer are the human
    author; no Co-Authored-By or AI attribution trailers. Conventional Commit subjects, English
    throughout, strict return types and PHPDoc on every new method, Pest style consistent with
    sibling tests.

- **Unverified gates:**
  - Real CI on both matrix legs. No PR was opened, so criterion 5's "pass in both CI matrix legs" half
    rests on local runs against a separately provisioned PostgreSQL 17. Acceptable as the executor
    framed it, and the local pgsql run is genuine engine evidence, but the pipeline itself remains
    unproven for this branch.
  - MySQL, out of scope by the task's own boundary.

- **Follow-ups:**
  1. Once finding 1 is resolved, add regression coverage for the non-canonical literal forms
     (exponent, leading whitespace, missing integer part) to both engines, so the gap cannot silently
     reopen.

### Re-validation of the follow-up (2026-08-08)

- **Verdict:** changes_requested (independent re-review of `5528ca9` vs `63930cc`, same validator).

- **Previous findings — all four resolved:**
  1. **[P1] Non-canonical literals bypassing the guard — resolved.** `MonetaryDecimal::fromString()`
     now validates the whole literal against `CANONICAL_PATTERN` and throws `MalformedMonetaryDecimal`
     before any scale check. All four inputs from the original finding were re-run on both engines and
     are now rejected identically, in-process, before the engine is reached:

     | Input | SQLite | PostgreSQL 17 |
     | --- | --- | --- |
     | `'1.1234567890123456789e0'` | `MalformedMonetaryDecimal` | `MalformedMonetaryDecimal` |
     | `'1e-19'` | `MalformedMonetaryDecimal` | `MalformedMonetaryDecimal` |
     | `' 1.1234567890123456789'` | `MalformedMonetaryDecimal` | `MalformedMonetaryDecimal` |
     | `'.1234567890123456789'` | `MalformedMonetaryDecimal` | `MalformedMonetaryDecimal` |

     Additional spellings I did not originally report are covered by the same change and also reject
     on both engines: `'1_000.5'`, `'0x10'`, `'1.'`, `''`, fullwidth digits, and `"1.5\r"`.
  2. **[P2] Query-builder bypass — resolved as a named limitation, and the framing is correct.**
     `LED-015` states that direct inserts into posting tables "are not an application feature" and
     `LIF-018` forbids any component writing postings directly, so a `Model::query()->update()` on
     `postings` is below the domain boundary by rule, not merely by implementation convenience — the
     same class as `DB::table()`. Documenting it rather than closing it is the right call, and the
     cast docblock now states the covered and uncovered paths precisely instead of claiming "no caller
     can bypass". The new feature test pins actual behavior per engine (SQLite `QueryException`,
     PostgreSQL accepts) rather than asserting an aspiration. Acceptance criterion 2 is unaffected: it
     names factories, seeders, and direct construction, all of which remain guarded.
  3. **[P2] Float coercion — resolved.** `MonetaryScale::set()` rejects any non-string,
     non-`MonetaryDecimal` value with `InvalidMonetaryValueType` before stringification. Verified:
     `0.1 + 0.2` and `1.1234567890123457` are rejected on both engines instead of storing `0.3` /
     `1.1234567890123`. `MonetaryDecimal` instances pass through and store correctly (`'7.25'`).
     Integers are rejected too — stricter than ADR-001 strictly requires, but consistent with its
     "written as decimal strings everywhere" consequence, and deliberate rather than accidental.
  4. **[P3] `.ai/rules/migrations.md` — resolved.** The syntax-versus-scale distinction is now spelled
     out and points at the application guard.

- **Along-the-way fixes — both sound.** The test now uses `whereKey()` only on the Eloquent builder
  and `where('id', …)` on `DB::table()`, which is correct since `whereKey()` does not exist on the
  base query builder. Widening `@implements CastsAttributes<string|null, mixed>` is the right generic:
  `set()` must accept `mixed` precisely so it can reject unexpected types, and the docblock says so.
  PHPStan is clean at level config with the widened generic.

- **New findings:**
  5. **[P2] The canonical pattern's `$` anchor still admits a trailing newline, and the engines
     disagree about it.** `MonetaryDecimal::CANONICAL_PATTERN`
     (`app/Domain/Money/ValueObjects/MonetaryDecimal.php:33`) is `/^[+-]?\d+(\.(\d+))?$/` with no `D`
     modifier, so PCRE's `$` matches before a final `\n`. Observed: `"1.5\n"` is **accepted** by the
     value object, then rejected by SQLite's CHECK (`QueryException`) and accepted by PostgreSQL
     (stored `1.500000000000000000`). `"1.5\r"` is correctly rejected, which isolates the cause to the
     anchor rather than the character class. This contradicts the contract the change itself
     documents — both the class docblock and `MalformedMonetaryDecimal`'s message state "no
     surrounding whitespace" — and leaves exactly one engine-dependent accept/reject case in the
     boundary that was just made the sole authority. The new dataset covers leading whitespace but not
     trailing, so nothing catches it. A trailing newline is not exotic: it is what an unrimmed CSV
     cell or a line-delimited provider feed produces. Resolve by anchoring the pattern to the true end
     of the subject so the value object's verdict matches its documented grammar.

  6. **[P3] `pad()` no longer normalizes, so accepted-but-unnormalized literals read back differently
     per engine — a regression against `decimal:18`.** `'+1.5'`, `'007.5'`, and `'-0.0'` are all
     canonical under the documented grammar and are accepted. On SQLite the literal text is stored and
     `MonetaryScale::pad()` only appends zeros, so they read back as `'+1.500000000000000000'`,
     `'007.500000000000000000'`, `'-0.000000000000000000'`; on PostgreSQL `numeric` normalizes them to
     `'1.500000000000000000'`, `'7.500000000000000000'`, `'0.000000000000000000'`. The previous
     `decimal:18` cast routed reads through `BigDecimal::of($value)->toScale(18, HalfUp)`
     (`HasAttributes.php:1536-1542`), which produced the PostgreSQL form on both engines — confirmed
     directly against the installed `brick/math`. Not blocking: ADR-001 directs domain comparisons to
     decimal math, which treats these as equal, and no consumer exists yet. But it is a behavior
     change this branch introduced, and string-level equality on a stored amount would now be
     engine-dependent. Worth a deliberate decision — normalize on write, or state that the ledger
     stores the caller's exact literal — rather than leaving it as a side effect of hand-rolled
     padding.

- **Evidence:**
  - `php artisan test --compact --no-tia` (SQLite, `:memory:`) — **64 passed, 107 assertions**.
  - `DB_CONNECTION=pgsql … php artisan test --compact --no-tia` (PostgreSQL 17, ephemeral
    `docker run postgres:17`, port 55432) — **64 tests, 63 passed, 106 assertions, 1 skipped**. Both
    reproduce the executor's reported counts exactly.
  - `vendor/bin/pint --test --format agent` — passed. `vendor/bin/phpstan analyse
    --memory-limit=512M` — 0 errors.
  - Findings 1, 3, 5, and 6 re-probed with a temporary Pest feature test run against both engines,
    asserting on the value read back from `DB::table('postings')` and from `$posting->fresh()`.
    Deleted after the run; no implementation file was touched.
  - Round-trip re-checked: `'12345.123456789012345678'` survives write and read unchanged on both
    engines, and `'100'` reads back as `'100.000000000000000000'`, so ADR-001's lossless-round-trip
    validation note still holds.
  - `git show --format=fuller --no-patch e6e7d04 5528ca9` — human author and committer, no
    Co-Authored-By or AI attribution trailers.

- **Unverified gates (unchanged):** real CI on both matrix legs, since no PR was opened; MySQL, out of
  scope.

### Final validation (2026-08-08)

- **Verdict:** approved — status `done` (independent review of `634ac9a` vs `49c732c`, same
  validator). No blocking findings remain.

- **Findings 5 and 6 — both resolved:**
  5. **[P2] Trailing-newline anchor — resolved.** `CANONICAL_PATTERN` is now
     `/^[+-]?\d+(\.(\d+))?$/D`. Re-ran the probe that produced the finding, on both engines:
     `"1.5\n"`, `"1.5 "`, `"1.5\t"`, `"1.5\r"`, `"1.5\n\n"`, and `"\n1.5"` all raise
     `MalformedMonetaryDecimal` in-process, identically on SQLite and PostgreSQL, before either engine
     is reached. The accept/reject divergence is gone. The regression datasets in both
     `MonetaryDecimalTest` and `PostingTest` cover the four whitespace forms, so it cannot silently
     reopen.
  6. **[P3] Normalization — resolved, and it is genuinely normalization rather than rounding.**
     `fromString()` canonicalizes through `BigDecimal::of($value)->toScale(18, RoundingMode::Unnecessary)`.
     Verified read-back is now byte-identical on both engines:

     | Input | SQLite (stored / read) | PostgreSQL 17 (stored / read) |
     | --- | --- | --- |
     | `'+1.5'` | `1.500000000000000000` | `1.500000000000000000` |
     | `'007.5'` | `7.500000000000000000` | `7.500000000000000000` |
     | `'-0.0'` | `0.000000000000000000` | `0.000000000000000000` |
     | `'00.10'` | `0.100000000000000000` | `0.100000000000000000` |

     `'12345.123456789012345678'` still round-trips unchanged on both engines, so ADR-001's
     lossless-round-trip validation note holds.

- **`RoundingMode::Unnecessary` cannot round here — verified, not assumed.** The scale check runs
  before `BigDecimal` is ever constructed, so the literal is proven to carry at most 18 fractional
  digits and `toScale(18)` can only widen. Swept every scale from 0 to 18 and confirmed each
  constructs without a `RoundingException` and pads to exactly 18 digits. Over-scale input
  (`'1.1234567890123456789'` and a 30-digit fraction) surfaces `ExcessiveDecimalScale`, never a
  `brick/math` exception. No new `LIF-012` rounding boundary exists: the numeric value is unchanged
  in every accepted case, only its representation.

- **Validation order — verified correct.** The regex runs first, so every malformed form surfaces
  `MalformedMonetaryDecimal` rather than leaking a `brick/math` exception: checked `'not-a-number'`,
  `'12.34.56'`, `'1e-19'`, `'.5'`, `' 1.5'`, `'1.'`, `''`, `'0x10'`, `'1_0.5'`. `BigDecimal::of()`
  would accept exponent notation, but the regex rejects it first, so the value object's grammar
  remains the sole authority and the widest-accepting parser never gets the chance to widen it.

- **Dependency declaration — for the book owner's ratification.** `composer.json` now requires
  `"brick/math": "^0.18"` directly. The `composer.lock` delta is a single line, the `content-hash`;
  no resolved version changed and no package was added or removed. `brick/math` was already installed
  at `0.18.0` transitively via `laravel/framework`, and it is the same library Laravel's own
  `decimal:` cast uses through `HasAttributes::asDecimal()`. `composer validate` passes. Declaring a
  library that application code now imports directly is correct practice, and the change is
  materially inert — but the project rule is that dependencies do not change without approval, so
  this is flagged for an explicit yes rather than treated as settled by the validator.

- **Evidence:**
  - `php artisan test --compact --no-tia` (SQLite, `:memory:`) — **80 passed, 123 assertions**.
  - `DB_CONNECTION=pgsql … php artisan test --compact --no-tia` (PostgreSQL 17, ephemeral
    `docker run postgres:17`, port 55432) — **80 tests, 79 passed, 122 assertions, 1 skipped**. Both
    reproduce the executor's reported counts exactly.
  - `vendor/bin/pint --test --format agent` — passed. `vendor/bin/phpstan analyse
    --memory-limit=512M` — 0 errors. `pnpm run lint:check`, `types:check`, `format:check` — all
    passed.
  - All findings re-probed with a temporary Pest feature test run against both engines, asserting on
    the value read back from `DB::table('postings')` and `$posting->fresh()`. Deleted after the run;
    no implementation file was touched in any of the three review rounds.
  - `git show --format=fuller --no-patch 05934ee 634ac9a` — human author and committer, no
    Co-Authored-By or AI attribution trailers.
  - Spot-checked the executor's follow-up record against reality; every claim in it reproduced.

- **Acceptance criteria — all met.** Over-scale input rejected identically on both engines (criterion
  1); the guard holds through factories, seeders, and direct construction (criterion 2); both
  `native_quantity` and `functional_amount` are covered (criterion 3); the skip is narrowed to the one
  genuinely SQLite-only assertion with an accurate message (criterion 4); regression tests assert on
  both engines and pass on both locally (criterion 5, with the CI-leg half noted below); no new
  rounding boundary was introduced (criterion 6).

- **Unverified gates:** real CI on both matrix legs — no PR was opened, so criterion 5's pipeline half
  rests on local runs against a separately provisioned PostgreSQL 17. Genuine engine evidence, but the
  workflow itself is unproven for this branch. MySQL remains out of scope by the task's own boundary.

- **Follow-ups (non-blocking, none required for this task):**
  2. **Total precision (the `38` in `DECIMAL(38, 18)`) is still unguarded, and the engines diverge on
     it.** With scale now fixed at 18, the column permits 20 integer digits. Verified: 19 and 20
     integer digits are accepted by both engines; 21 is accepted and stored by SQLite
     (`999999999999999999999.500000000000000000`) and rejected by PostgreSQL with a `QueryException`.
     This is the same shape as the defect this task closed, one axis over. It is pre-existing — SQLite's
     CHECK never limited integer digits — and outside these acceptance criteria, which are scale-only,
     so it does not block. Worth a small follow-up task now that `MonetaryDecimal` is the natural place
     to enforce it.
  3. Ratify the `brick/math` direct requirement noted above.
