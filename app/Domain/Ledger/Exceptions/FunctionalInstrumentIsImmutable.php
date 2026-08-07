<?php

namespace App\Domain\Ledger\Exceptions;

use RuntimeException;

/**
 * Thrown when application code attempts to change a book's functional instrument after the book
 * already has a posted journal transaction (ADR-002).
 */
class FunctionalInstrumentIsImmutable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            "A book's functional instrument cannot change once it has a posted journal transaction."
        );
    }
}
