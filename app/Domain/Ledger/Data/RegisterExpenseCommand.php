<?php

namespace App\Domain\Ledger\Data;

use Carbon\CarbonInterface;

/**
 * Intent to register a functional-instrument expense paid from an asset account whose native
 * instrument is the book's functional instrument, so no valuation or FX result is involved
 * (SCN-EXP-001's shape; its VES valuation and cost basis are later slices). `amount` and
 * `feeAmount` are positive magnitudes, in both the asset account's native instrument and the book's
 * functional instrument. `categoryId`, when given, classifies the expense posting alone, never the
 * fee posting (LED-010, ADR-003).
 */
final readonly class RegisterExpenseCommand
{
    public function __construct(
        public int $bookId,
        public int $assetAccountId,
        public string $amount,
        public ?int $categoryId,
        public ?string $feeAmount,
        public string $idempotencyKey,
        public CarbonInterface $effectiveAt,
        public ?string $description = null,
    ) {}
}
