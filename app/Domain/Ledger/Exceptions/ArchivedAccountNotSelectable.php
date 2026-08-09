<?php

namespace App\Domain\Ledger\Exceptions;

use RuntimeException;

/**
 * Thrown when a caller selects an archived account as the payment account for an intent action.
 * An archived account (ACC-005) keeps its posted history available for reporting but is no longer
 * an active position a new transaction may post against.
 */
class ArchivedAccountNotSelectable extends RuntimeException
{
    public function __construct(int $accountId)
    {
        parent::__construct("Account {$accountId} is archived and cannot be used as a payment account.");
    }
}
