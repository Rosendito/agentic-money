<?php

use App\Domain\Ledger\Enums\TransactionStatus;
use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\JournalTransaction;
use Illuminate\Database\QueryException;

test('a duplicate idempotency key within the same book is rejected', function () {
    $book = Book::factory()->create();
    JournalTransaction::factory()->for($book)->create(['idempotency_key' => 'duplicate-key']);

    expect(fn () => JournalTransaction::factory()->for($book)->create(['idempotency_key' => 'duplicate-key']))
        ->toThrow(QueryException::class);
});

test('the same idempotency key is accepted in a different book', function () {
    $bookA = Book::factory()->create();
    $bookB = Book::factory()->create();

    JournalTransaction::factory()->for($bookA)->create(['idempotency_key' => 'shared-key']);
    $transaction = JournalTransaction::factory()->for($bookB)->create(['idempotency_key' => 'shared-key']);

    expect($transaction->exists)->toBeTrue();
});

test('a posted transaction factory state produces a posted status', function () {
    $transaction = JournalTransaction::factory()->posted()->create();

    expect($transaction->status)->toBe(TransactionStatus::Posted);
});

test('a draft transaction factory state produces a draft status', function () {
    $transaction = JournalTransaction::factory()->draft()->create();

    expect($transaction->status)->toBe(TransactionStatus::Draft);
});

test('a reversal factory state links back to the original transaction in the same book', function () {
    $original = JournalTransaction::factory()->posted()->create();
    $reversal = JournalTransaction::factory()->reversalOf($original)->create();

    expect($reversal->reverses_transaction_id)->toBe($original->id)
        ->and($reversal->book_id)->toBe($original->book_id)
        ->and($reversal->status)->toBe(TransactionStatus::Posted);
});
