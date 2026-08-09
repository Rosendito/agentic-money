<?php

namespace App\Domain\Ledger\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a posting's native quantity and functional amount carry opposite signs (LED-008).
 * Both values describe the same movement of the same account in two denominations, so a positive
 * native quantity can never pair with a negative functional amount (or vice versa) unless the
 * native quantity is exactly zero (LED-005's documented exception). The kernel rejects the
 * contradiction; it never silently flips a sign to make the posting balance.
 */
class PostingSignMismatch extends InvalidArgumentException
{
    public function __construct(string $nativeQuantity, string $functionalAmount)
    {
        parent::__construct(
            "A posting's native quantity ({$nativeQuantity}) and functional amount ".
            "({$functionalAmount}) carry contradictory signs."
        );
    }
}
