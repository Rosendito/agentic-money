<?php

namespace App\Domain\Ledger\Exceptions;

use RuntimeException;

/**
 * Thrown when spending would push an asset account's posted native balance negative (ACC-006). The
 * default action policy rejects this; a future explicit override flag may relax it, but no such
 * flag exists yet.
 */
class InsufficientNativeBalance extends RuntimeException
{
    public function __construct(int $accountId, string $availableBalance, string $requestedNativeQuantity)
    {
        parent::__construct(
            "Account {$accountId} has a native balance of {$availableBalance}, insufficient to post ".
            "a native quantity of {$requestedNativeQuantity}."
        );
    }
}
