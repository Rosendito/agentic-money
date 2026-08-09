<?php

namespace App\Domain\Ledger\Data;

use Carbon\CarbonInterface;

/**
 * Intent to register an opening balance (SCN-OPEN-001). `amount` is the positive magnitude, in both
 * the asset account's native instrument and the book's functional instrument, since an opening
 * balance is declared in the account's own instrument with no conversion involved.
 */
final readonly class RegisterOpeningBalanceCommand
{
    public function __construct(
        public int $bookId,
        public int $assetAccountId,
        public string $amount,
        public string $idempotencyKey,
        public CarbonInterface $effectiveAt,
        public ?string $description = null,
    ) {}
}
