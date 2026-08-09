<?php

use App\Domain\Ledger\Actions\PostJournalTransactionAction;
use App\Domain\Ledger\Data\PostingInput;
use App\Domain\Ledger\Data\PostJournalTransactionCommand;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\JournalTransaction;
use Illuminate\Support\Carbon;

/**
 * Regression coverage for external-review finding 5: a command carrying non-UTC wall time (e.g.
 * America/Caracas, UTC-4) used to persist that wall clock's literal digits — Eloquent's `datetime`
 * cast formats a Carbon instance in whatever timezone it currently holds, it does not convert to
 * UTC — then rehydrate those same digits as if they had always been UTC, four hours off from the
 * real instant. Because idempotency canonicalization also normalizes to UTC, an identical replay
 * then disagreed with what was actually stored and raised IdempotencyConflict instead of returning
 * the existing transaction.
 */
function bookWithTwoAccounts(): array
{
    $book = Book::factory()->create();
    $accountA = Account::factory()->for($book)->type(AccountType::Asset)->create();
    $accountB = Account::factory()->for($book)->type(AccountType::Equity)->create();

    return [$book, $accountA, $accountB];
}

test('a non-UTC effective time round-trips to the correct instant', function () {
    [$book, $accountA, $accountB] = bookWithTwoAccounts();

    // 2026-08-08 10:00:00 America/Caracas (UTC-4) is 2026-08-08 14:00:00 UTC.
    $caracasTime = Carbon::parse('2026-08-08 10:00:00', 'America/Caracas');

    $transaction = (new PostJournalTransactionAction)->handle(new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'caracas-1',
        effectiveAt: $caracasTime,
        description: null,
        postings: [
            new PostingInput($accountA->id, '10', '10'),
            new PostingInput($accountB->id, '-10', '-10'),
        ],
    ));

    expect($transaction->effective_at->utc()->toIso8601String())->toBe('2026-08-08T14:00:00+00:00')
        ->and($transaction->fresh()->effective_at->utc()->toIso8601String())->toBe('2026-08-08T14:00:00+00:00');
});

test('an identical replay of a non-UTC effective time returns the existing transaction instead of conflicting', function () {
    [$book, $accountA, $accountB] = bookWithTwoAccounts();
    $caracasTime = Carbon::parse('2026-08-08 10:00:00', 'America/Caracas');

    $command = new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'caracas-replay',
        effectiveAt: $caracasTime,
        description: null,
        postings: [
            new PostingInput($accountA->id, '10', '10'),
            new PostingInput($accountB->id, '-10', '-10'),
        ],
    );

    $first = (new PostJournalTransactionAction)->handle($command);

    // The identical command, replayed: same object graph, but this also proves the replay works
    // when the caller reconstructs an equivalent (not object-identical) non-UTC Carbon instance.
    $secondCommand = new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'caracas-replay',
        effectiveAt: Carbon::parse('2026-08-08 10:00:00', 'America/Caracas'),
        description: null,
        postings: [
            new PostingInput($accountA->id, '10', '10'),
            new PostingInput($accountB->id, '-10', '-10'),
        ],
    );

    $second = (new PostJournalTransactionAction)->handle($secondCommand);

    expect($second->id)->toBe($first->id);
    expect(JournalTransaction::query()->where('idempotency_key', 'caracas-replay')->count())->toBe(1);
});
