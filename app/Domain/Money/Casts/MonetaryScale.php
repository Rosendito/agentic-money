<?php

namespace App\Domain\Money\Casts;

use App\Domain\Money\Exceptions\InvalidMonetaryValueType;
use App\Domain\Money\ValueObjects\MonetaryDecimal;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a monetary column to and from an exact decimal string, rejecting malformed syntax, more
 * than 18 fractional digits, and binary floats on the way in, and normalizing the accepted literal
 * to the ledger's canonical decimal form (ADR-001, 2026-08-08 amendment; LIF-011, LIF-012).
 *
 * `set()` returns {@see MonetaryDecimal}'s fully normalized, 18-digit-padded string, so both SQLite
 * and PostgreSQL store identical bytes for the same accepted literal. `get()`'s own `pad()` is a
 * lighter, best-effort fallback for rows written through a path this cast does not cover (the
 * documented query-builder/raw-SQL gap below) — it only pads the fractional part and does not
 * repeat the full normalization, since a value that reached storage that way was never validated
 * here in the first place.
 *
 * This covers every write that goes through an Eloquent attribute: mass assignment (`fill()`,
 * `create()`), direct property assignment, factories, and seeders all funnel through
 * `setAttribute()`, which invokes `set()` below. It does **not** cover Eloquent's query builder —
 * `Model::query()->update(...)`, `insert()`, and `upsert()` write columns directly without
 * instantiating a model or invoking any cast — nor raw SQL. Those paths sit below this boundary,
 * the same class of gap as `DB::table()`, and are a documented, accepted limitation rather than a
 * closed one (see `tests/Feature/Domain/Ledger/PostingTest.php`): the ledger's own actions create
 * and correct postings through the model (LIF-003, LIF-004), never through a bulk query-builder
 * write.
 *
 * The application guard is necessarily the only rejection boundary for scale on PostgreSQL — a
 * `DECIMAL(38, 18)` column rounds an over-scale value before any CHECK constraint could observe it,
 * so no database-level rejection is possible there. SQLite keeps its CHECK constraint as a second,
 * redundant layer for both syntax and scale.
 *
 * `set()` deliberately accepts `mixed`, not just the values documented above: rejecting an
 * unexpected type is the guard's job, so its input type cannot be narrowed to only the types it
 * already trusts.
 *
 * @implements CastsAttributes<string|null, mixed>
 */
class MonetaryScale implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->pad((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidMonetaryValueType when the value is not a string or a MonetaryDecimal.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof MonetaryDecimal) {
            return (string) $value;
        }

        // A float must never reach MonetaryDecimal::fromString(): stringifying it here would
        // silently coerce it at PHP's `precision` setting (e.g. `0.1 + 0.2` becomes `"0.3"`),
        // which is exactly the silent-rounding boundary this guard exists to prevent (ADR-001).
        if (! is_string($value)) {
            throw new InvalidMonetaryValueType($key, $value);
        }

        return (string) MonetaryDecimal::fromString($value);
    }

    /**
     * Pad the fractional part to the stored scale (18 digits) so reads are consistent regardless of
     * how many fractional digits the caller originally supplied. String padding only, never
     * arithmetic or float conversion (ADR-001).
     */
    private function pad(string $value): string
    {
        [$integer, $fraction] = str_contains($value, '.')
            ? explode('.', $value, 2)
            : [$value, ''];

        return $integer.'.'.str_pad($fraction, MonetaryDecimal::MAX_FRACTIONAL_DIGITS, '0');
    }
}
