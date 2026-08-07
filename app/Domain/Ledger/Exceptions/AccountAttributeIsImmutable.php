<?php

namespace App\Domain\Ledger\Exceptions;

use RuntimeException;

/**
 * Thrown when application code attempts to change an account's native instrument or account type
 * after the account already has a posting (ACC-003, ACC-004).
 */
class AccountAttributeIsImmutable extends RuntimeException
{
    public function __construct(string $attribute)
    {
        parent::__construct(
            "An account's {$attribute} cannot change once it has a posting."
        );
    }
}
