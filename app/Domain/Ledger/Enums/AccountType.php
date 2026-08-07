<?php

namespace App\Domain\Ledger\Enums;

/**
 * The accounting nature of an account (docs/02-domain-glossary.md, "Account type").
 */
enum AccountType: string
{
    case Asset = 'Asset';
    case Liability = 'Liability';
    case Equity = 'Equity';
    case Income = 'Income';
    case Expense = 'Expense';
}
