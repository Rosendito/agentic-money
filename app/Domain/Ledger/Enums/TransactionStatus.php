<?php

namespace App\Domain\Ledger\Enums;

/**
 * Stored journal transaction lifecycle states (docs/07-integrity-and-lifecycle.md, "Transaction
 * states"). "Reversed" is a derived condition, never a stored status: a reversal is expressed by a
 * posted transaction referencing the original through `reverses_transaction_id`.
 */
enum TransactionStatus: string
{
    case Draft = 'Draft';
    case Pending = 'Pending';
    case Posted = 'Posted';
    case Cancelled = 'Cancelled';
}
