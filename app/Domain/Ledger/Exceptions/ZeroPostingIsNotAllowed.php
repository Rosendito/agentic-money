<?php

namespace App\Domain\Ledger\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a posting's native quantity and functional amount are both exactly zero (LED-005).
 * A zero-native posting with a non-zero functional amount remains allowed; only the combination of
 * both being zero is rejected.
 */
class ZeroPostingIsNotAllowed extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('A posting cannot have both a zero native quantity and a zero functional amount.');
    }
}
