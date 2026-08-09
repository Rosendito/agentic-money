<?php

namespace App\Domain\Money\ValueObjects;

use App\Domain\Money\Exceptions\ExcessiveDecimalScale;
use App\Domain\Money\Exceptions\MalformedMonetaryDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Stringable;

/**
 * An exact decimal string used for native quantities, functional amounts, and (in future) rates
 * (ADR-001). This value object is the sole authority on the accepted decimal form: an optional
 * leading sign, one or more digits, and an optional `.` followed by one or more digits — no
 * exponent notation, no surrounding whitespace (including a trailing newline), no missing integer
 * part. Rejecting anything else here (rather than leaving syntax to the database) matters because
 * SQLite and PostgreSQL disagree on which non-canonical spellings they accept; PostgreSQL's
 * `numeric` additionally allows exponent notation, leading whitespace, and a missing integer part,
 * so without this guard `'1e-19'` reaches PostgreSQL, is silently rounded to `0`, and reaches SQLite
 * as a rejected `QueryException` — correctness would depend on which engine is running underneath.
 *
 * A canonical value also carries at most 18 fractional digits, the ledger's stored scale, enforced
 * here for the same reason: PostgreSQL's `DECIMAL(38, 18)` silently rounds an over-scale value
 * instead of rejecting it.
 *
 * The stored value is further **normalized**, never rounded: a leading `+` is stripped, redundant
 * leading zeros are stripped (keeping one integer digit), negative zero collapses to plain zero, and
 * the fractional part is padded with trailing zeros to the full 18-digit scale. This is normalization
 * because the numeric value never changes — no `LIF-012` rounding boundary is introduced — but it
 * matters because PostgreSQL's `numeric` column performs this same normalization on write while a
 * plain text column on SQLite does not: without normalizing here, `'+1.5'`, `'007.5'`, and `'-0.0'`
 * would read back as the caller's literal text on SQLite but as `'1.500000000000000000'`,
 * `'7.500000000000000000'`, and `'0.000000000000000000'` on PostgreSQL. Normalizing at construction
 * makes both engines hold identical bytes.
 *
 * Fractional digits are counted directly from the string's own syntax, never through float
 * arithmetic (ADR-001 forbids `float`/`double` for monetary values): converting an
 * 18-fractional-digit decimal to a binary float would silently lose the precision before the count
 * could even happen. Normalization itself uses `brick/math`'s arbitrary-precision `BigDecimal`, the
 * same exact-decimal library Laravel's own `decimal:` cast uses internally, never a binary float.
 */
final readonly class MonetaryDecimal implements Stringable
{
    public const int MAX_FRACTIONAL_DIGITS = 18;

    /**
     * The `D` modifier anchors `$` to the true end of the subject instead of allowing it to match
     * before a trailing `\n`, so `"1.5\n"` is rejected instead of silently accepted (PCRE's default
     * `$` behavior, without `D`, matches before a final newline).
     */
    private const string CANONICAL_PATTERN = '/^[+-]?\d+(\.(\d+))?$/D';

    private function __construct(private string $value) {}

    /**
     * @throws MalformedMonetaryDecimal when the value is not canonical decimal syntax.
     * @throws ExcessiveDecimalScale when the value carries more than the maximum fractional digits.
     */
    public static function fromString(string $value): self
    {
        if (! preg_match(self::CANONICAL_PATTERN, $value, $matches)) {
            throw new MalformedMonetaryDecimal($value);
        }

        $fractionalDigits = isset($matches[2]) ? strlen($matches[2]) : 0;

        if ($fractionalDigits > self::MAX_FRACTIONAL_DIGITS) {
            throw new ExcessiveDecimalScale($value, $fractionalDigits, self::MAX_FRACTIONAL_DIGITS);
        }

        // Widening to scale 18 never rounds here: the value was just proven to carry no more than
        // 18 fractional digits, so RoundingMode::Unnecessary only pads with zeros and can never
        // throw. This is normalization (stripping a leading '+', redundant leading zeros, and
        // negative zero), not rounding — the numeric value is unchanged.
        return new self((string) BigDecimal::of($value)->toScale(self::MAX_FRACTIONAL_DIGITS, RoundingMode::Unnecessary));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
