<?php

namespace App\Domain\Ledger\Exceptions;

use RuntimeException;

/**
 * Thrown when application code attempts to update or delete a posted journal transaction or one of
 * its postings through Eloquent (LIF-003). The reversal path (TASK-005) is the only sanctioned
 * correction and posts new, separate rows instead of mutating posted ones.
 */
class PostedDataIsImmutable extends RuntimeException
{
    public static function forTransaction(): self
    {
        return new self('A posted journal transaction cannot be updated or deleted.');
    }

    public static function forPosting(): self
    {
        return new self('A posting belonging to a posted journal transaction cannot be updated or deleted.');
    }
}
