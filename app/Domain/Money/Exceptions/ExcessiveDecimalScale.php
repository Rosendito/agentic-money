<?php

namespace App\Domain\Money\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a monetary value carries more fractional digits than the ledger's stored scale
 * permits (ADR-001, 2026-08-08 amendment; LIF-011, LIF-012).
 *
 * Storage is not a rounding boundary: PostgreSQL's `DECIMAL(38, 18)` would otherwise round such a
 * value silently instead of rejecting it, while SQLite's CHECK constraints reject it outright. This
 * exception keeps both engines behaving identically by rejecting the value before either one is
 * reached.
 */
class ExcessiveDecimalScale extends InvalidArgumentException
{
    public function __construct(string $value, int $fractionalDigits, int $maxFractionalDigits)
    {
        parent::__construct(
            "The monetary value \"{$value}\" has {$fractionalDigits} fractional digits, exceeding ".
            "the maximum of {$maxFractionalDigits}. Over-scale input is rejected, never rounded."
        );
    }
}
