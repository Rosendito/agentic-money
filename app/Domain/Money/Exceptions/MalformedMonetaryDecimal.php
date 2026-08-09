<?php

namespace App\Domain\Money\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a monetary value is not canonical decimal syntax: an optional leading `+`/`-` sign,
 * one or more digits, and an optional `.` followed by one or more digits — no exponent notation,
 * no surrounding whitespace, and no missing integer part.
 *
 * SQLite and PostgreSQL do not agree on which non-canonical spellings they accept (PostgreSQL's
 * `numeric` additionally allows exponent notation, leading whitespace, and a missing integer part),
 * so leaving syntax enforcement to the database would make correctness depend on which engine is
 * running. The value object that throws this exception is the sole authority on the accepted
 * decimal form (ADR-001, 2026-08-08 amendment).
 */
class MalformedMonetaryDecimal extends InvalidArgumentException
{
    public function __construct(string $value)
    {
        parent::__construct(
            "The monetary value \"{$value}\" is not canonical decimal syntax (an optional sign, ".
            'digits, and an optional decimal point followed by digits). Malformed input is rejected '.
            'at the application boundary, not left to the database engine.'
        );
    }
}
