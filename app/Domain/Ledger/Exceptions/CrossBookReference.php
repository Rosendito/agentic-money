<?php

namespace App\Domain\Ledger\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a posting command references an account or category that does not belong to the
 * journal transaction's book (LIF-016). The composite foreign keys reject this at the database
 * layer too; this exception lets the kernel report the violation before attempting any write.
 */
class CrossBookReference extends InvalidArgumentException
{
    public static function forAccount(int $accountId, int $bookId): self
    {
        return new self("Account {$accountId} does not belong to book {$bookId}.");
    }

    public static function forCategory(int $categoryId, int $bookId): self
    {
        return new self("Category {$categoryId} does not belong to book {$bookId}.");
    }
}
