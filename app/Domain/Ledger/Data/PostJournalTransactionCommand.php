<?php

namespace App\Domain\Ledger\Data;

use App\Domain\Ledger\Actions\PostJournalTransactionAction;
use Carbon\CarbonInterface;

/**
 * The command handled by the single posting kernel,
 * {@see PostJournalTransactionAction} (LED-015, LIF-018). Every intent
 * action (book bootstrap, opening balance, income, expense, and every future one) builds this
 * command and calls the kernel instead of writing journal or posting rows itself.
 */
final readonly class PostJournalTransactionCommand
{
    /**
     * @param  list<PostingInput>  $postings
     */
    public function __construct(
        public int $bookId,
        public string $idempotencyKey,
        public CarbonInterface $effectiveAt,
        public ?string $description,
        public array $postings,
    ) {}
}
