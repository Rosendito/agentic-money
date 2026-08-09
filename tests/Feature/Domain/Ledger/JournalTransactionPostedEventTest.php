<?php

use App\Domain\Ledger\Actions\PostJournalTransactionAction;
use App\Domain\Ledger\Data\PostingInput;
use App\Domain\Ledger\Data\PostJournalTransactionCommand;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Events\JournalTransactionPosted;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\JournalTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Deliberately not shared with PostJournalTransactionActionTest.php's twoAccountBook(): Pest's
 * `--parallel` mode partitions test files across separate processes, so a global function defined
 * in one file is not guaranteed to be loaded in another file's process.
 *
 * @return array{0: Book, 1: Account, 2: Account}
 */
function twoAccountBookForEventTest(): array
{
    $book = Book::factory()->create();
    $accountA = Account::factory()->for($book)->type(AccountType::Asset)->create();
    $accountB = Account::factory()->for($book)->type(AccountType::Equity)->create();

    return [$book, $accountA, $accountB];
}

test('JournalTransactionPosted dispatches after a successful posting commits', function () {
    Event::fake([JournalTransactionPosted::class]);
    [$book, $accountA, $accountB] = twoAccountBookForEventTest();

    $transaction = (new PostJournalTransactionAction)->handle(new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'event-commit',
        effectiveAt: Carbon::now(),
        description: null,
        postings: [
            new PostingInput($accountA->id, '10', '10'),
            new PostingInput($accountB->id, '-10', '-10'),
        ],
    ));

    Event::assertDispatched(
        JournalTransactionPosted::class,
        fn (JournalTransactionPosted $event) => $event->transaction->id === $transaction->id,
    );
});

test('a rolled-back posting dispatches nothing', function () {
    Event::fake([JournalTransactionPosted::class]);
    [$book, $accountA, $accountB] = twoAccountBookForEventTest();

    try {
        DB::transaction(function () use ($book, $accountA, $accountB) {
            (new PostJournalTransactionAction)->handle(new PostJournalTransactionCommand(
                bookId: $book->id,
                idempotencyKey: 'event-rollback',
                effectiveAt: Carbon::now(),
                description: null,
                postings: [
                    new PostingInput($accountA->id, '10', '10'),
                    new PostingInput($accountB->id, '-10', '-10'),
                ],
            ));

            // Force the outer transaction (the caller's, not the kernel's own) to roll back after
            // the kernel has already returned a seemingly successful result.
            throw new RuntimeException('force rollback of the outer transaction');
        });
    } catch (RuntimeException) {
        // Expected: proves the rollback path, not a silent swallow.
    }

    Event::assertNotDispatched(JournalTransactionPosted::class);
    expect(JournalTransaction::query()->where('idempotency_key', 'event-rollback')->count())->toBe(0);
});
