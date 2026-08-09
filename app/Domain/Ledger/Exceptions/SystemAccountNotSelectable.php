<?php

namespace App\Domain\Ledger\Exceptions;

use App\Domain\Ledger\Enums\SystemAccountRole;
use RuntimeException;

/**
 * Thrown when a caller selects a system-managed account (a book's opening equity, income control,
 * expense control, or other {@see SystemAccountRole}) as an ordinary payment account. System
 * accounts are book-owned and cannot be repurposed or selected by a caller (ACC-007).
 */
class SystemAccountNotSelectable extends RuntimeException
{
    public function __construct(int $accountId, SystemAccountRole $role)
    {
        parent::__construct(
            "Account {$accountId} is the book's {$role->value} system account and cannot be ".
            'selected as a payment account.'
        );
    }
}
