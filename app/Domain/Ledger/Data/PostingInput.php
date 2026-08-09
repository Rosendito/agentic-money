<?php

namespace App\Domain\Ledger\Data;

use App\Domain\Ledger\Actions\PostJournalTransactionAction;

/**
 * One posting's inputs to {@see PostJournalTransactionAction}. Native
 * quantity and functional amount are caller-supplied decimal strings (LED-004): this slice has no
 * quote lookup (TASK-006), so a future valuation policy can populate `functionalAmount` without
 * changing this shape or the kernel's contract.
 */
final readonly class PostingInput
{
    public function __construct(
        public int $accountId,
        public string $nativeQuantity,
        public string $functionalAmount,
        public ?int $categoryId = null,
        public ?string $memo = null,
    ) {}
}
