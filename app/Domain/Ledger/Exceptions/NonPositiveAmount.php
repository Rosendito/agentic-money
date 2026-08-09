<?php

namespace App\Domain\Ledger\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when an intent action receives a zero or negative magnitude for a field documented as a
 * positive amount (e.g. `RegisterExpenseCommand::$amount`). Intent actions declare a shape — "an
 * expense spends", "an income receives" — by deriving each posting's sign themselves; a negative
 * input would silently invert that shape instead of contradicting it, and the kernel's own
 * same-posting sign-consistency check cannot see the inversion because both legs of the resulting
 * posting still agree with each other. Enforcing strict positivity is therefore the intent action's
 * responsibility, not the kernel's.
 */
class NonPositiveAmount extends InvalidArgumentException
{
    public function __construct(string $field, string $value)
    {
        parent::__construct("\"{$field}\" must be a strictly positive amount, \"{$value}\" given.");
    }
}
