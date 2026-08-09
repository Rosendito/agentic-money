<?php

namespace App\Domain\Ledger\Exceptions;

use RuntimeException;

/**
 * Thrown when a posting command reuses an idempotency key already recorded for a materially
 * different canonical payload (LIF-009). Reusing a key with an identical payload instead returns
 * the existing transaction; this exception is raised only when the payload differs.
 */
class IdempotencyConflict extends RuntimeException
{
    public function __construct(string $idempotencyKey)
    {
        parent::__construct(
            "Idempotency key \"{$idempotencyKey}\" was already used for a different transaction payload."
        );
    }
}
