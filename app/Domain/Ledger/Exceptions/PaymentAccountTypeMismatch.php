<?php

namespace App\Domain\Ledger\Exceptions;

use App\Domain\Ledger\Enums\AccountType;
use RuntimeException;

/**
 * Thrown when a caller selects an account that is not an {@see AccountType::Asset} account as the
 * payment account for an intent action. Income, expense, equity, and liability accounts are never
 * valid payment accounts for opening balance, income, or expense registration.
 */
class PaymentAccountTypeMismatch extends RuntimeException
{
    public function __construct(int $accountId, AccountType $type)
    {
        parent::__construct(
            "Account {$accountId} is a {$type->value} account and cannot be used as a payment account; ".
            'only an Asset account may be selected.'
        );
    }
}
