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
     * @param  list<int>  $accountsRequiringNonNegativeBalance  Account ids the kernel must lock
     *                                                          (`lockForUpdate`) and verify would
     *                                                          not go native-balance-negative,
     *                                                          inside the same database
     *                                                          transaction that creates this
     *                                                          command's postings (ACC-006).
     *                                                          `RegisterExpenseAction` is the
     *                                                          first caller: without this, the
     *                                                          balance decision and the posting
     *                                                          would live in two separate,
     *                                                          unlocked steps, letting two
     *                                                          concurrent expenses both read a
     *                                                          sufficient balance before either
     *                                                          posts (external-review finding 4).
     */
    public function __construct(
        public int $bookId,
        public string $idempotencyKey,
        public CarbonInterface $effectiveAt,
        public ?string $description,
        public array $postings,
        public array $accountsRequiringNonNegativeBalance = [],
    ) {}
}
