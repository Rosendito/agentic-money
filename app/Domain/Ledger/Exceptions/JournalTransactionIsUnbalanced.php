<?php

namespace App\Domain\Ledger\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when the exact decimal-string sum of a journal transaction's functional amounts is not
 * zero at the storage scale (LED-001, LIF-011). Never a float comparison and never an epsilon
 * tolerance.
 */
class JournalTransactionIsUnbalanced extends InvalidArgumentException
{
    public function __construct(string $sum)
    {
        parent::__construct("The journal transaction's functional amounts sum to {$sum}, not exactly zero.");
    }
}
