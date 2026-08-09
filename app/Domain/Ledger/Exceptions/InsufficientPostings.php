<?php

namespace App\Domain\Ledger\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a posting command supplies fewer than two postings (LED-001).
 */
class InsufficientPostings extends InvalidArgumentException
{
    public function __construct(int $count)
    {
        parent::__construct("A journal transaction requires at least two postings, {$count} given.");
    }
}
