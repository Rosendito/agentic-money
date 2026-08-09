<?php

namespace App\Domain\Ledger\Exceptions;

use App\Domain\Ledger\Enums\AccountType;
use InvalidArgumentException;

/**
 * Thrown when a posting command attaches a category to a posting whose account is not an income or
 * expense account (LED-010, ADR-003: "category applied only to the income or expense posting of a
 * transaction — never to fees or future FX postings"). Enforced at the single posting boundary
 * (LED-015) so the rule holds for every future caller, not only the intent actions that happen to
 * respect it today.
 */
class CategoryNotAllowedOnAccountType extends InvalidArgumentException
{
    public function __construct(int $accountId, AccountType $type)
    {
        parent::__construct(
            "Account {$accountId} is a {$type->value} account and cannot carry a category; ".
            'only an Income or Expense posting may be categorized.'
        );
    }
}
