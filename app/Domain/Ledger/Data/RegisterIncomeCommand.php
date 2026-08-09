<?php

namespace App\Domain\Ledger\Data;

use Carbon\CarbonInterface;

/**
 * Intent to register income received into an asset account (SCN-INC-001). `amount` is the positive
 * magnitude, in both the asset account's native instrument and the book's functional instrument.
 * `categoryId`, when given, classifies the income posting, never the asset posting (LED-010).
 */
final readonly class RegisterIncomeCommand
{
    public function __construct(
        public int $bookId,
        public int $assetAccountId,
        public string $amount,
        public ?int $categoryId,
        public string $idempotencyKey,
        public CarbonInterface $effectiveAt,
        public ?string $description = null,
    ) {}
}
