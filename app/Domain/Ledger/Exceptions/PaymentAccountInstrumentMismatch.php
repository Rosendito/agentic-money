<?php

namespace App\Domain\Ledger\Exceptions;

use RuntimeException;

/**
 * Thrown when a caller selects a payment account whose native instrument is not the book's
 * functional instrument, for an intent action that has no valuation or FX policy (this slice's
 * opening balance, income, and expense actions post the same magnitude as both the native quantity
 * and the functional amount, per docs/tasks/004-categories-and-posting-engine.md's "functional
 * amounts are caller-supplied" design). Posting a VES account through such an action would silently
 * record a bogus 1:1 VES-to-functional-instrument amount instead of a real valuation.
 */
class PaymentAccountInstrumentMismatch extends RuntimeException
{
    public function __construct(int $accountId, int $accountInstrumentId, int $bookFunctionalInstrumentId)
    {
        parent::__construct(
            "Account {$accountId} is denominated in instrument {$accountInstrumentId}, not the ".
            "book's functional instrument {$bookFunctionalInstrumentId}; this action has no ".
            'valuation policy to convert between them.'
        );
    }
}
