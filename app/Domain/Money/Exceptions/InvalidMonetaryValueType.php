<?php

namespace App\Domain\Money\Exceptions;

use App\Domain\Money\ValueObjects\MonetaryDecimal;
use InvalidArgumentException;

/**
 * Thrown when a monetary attribute is set with anything other than a decimal string or an
 * already-validated {@see MonetaryDecimal}.
 *
 * ADR-001 forbids `float`/`double` for monetary values: coercing a float to a string before
 * counting its fractional digits would silently truncate at PHP's `precision` setting (for
 * example `0.1 + 0.2` stringifies to `"0.3"`), which is exactly the silent-rounding boundary this
 * guard exists to prevent. Rejecting the type loudly here is cheaper and safer than trying to
 * recover the original exact value from a float that may have already lost it.
 */
class InvalidMonetaryValueType extends InvalidArgumentException
{
    public function __construct(string $attribute, mixed $value)
    {
        parent::__construct(
            "The monetary attribute \"{$attribute}\" requires a decimal string or a MonetaryDecimal ".
            'instance, got '.get_debug_type($value).'. Binary floats and any other type are '.
            'forbidden for monetary values (ADR-001).'
        );
    }
}
