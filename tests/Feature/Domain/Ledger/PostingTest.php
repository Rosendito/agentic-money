<?php

use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\Container;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Ledger\Models\Posting;
use Illuminate\Database\QueryException;

test('a value with 18 fractional digits survives a write/read round-trip unchanged', function () {
    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();
    $transaction = JournalTransaction::factory()->for($book)->create();

    $posting = Posting::factory()->create([
        'book_id' => $book->id,
        'journal_transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'native_quantity' => '12345.123456789012345678',
        'functional_amount' => '-12345.123456789012345678',
    ]);

    expect($posting->fresh())
        ->native_quantity->toBe('12345.123456789012345678')
        ->functional_amount->toBe('-12345.123456789012345678');
});

test('a posting whose account belongs to a different book is rejected', function () {
    $book = Book::factory()->create();
    $otherBook = Book::factory()->create();
    $transaction = JournalTransaction::factory()->for($book)->create();
    $accountInOtherBook = Account::factory()->for($otherBook)->create();

    expect(fn () => Posting::factory()->create([
        'book_id' => $book->id,
        'journal_transaction_id' => $transaction->id,
        'account_id' => $accountInOtherBook->id,
    ]))->toThrow(QueryException::class);
});

test('a posting whose transaction belongs to a different book is rejected', function () {
    $book = Book::factory()->create();
    $otherBook = Book::factory()->create();
    $transactionInOtherBook = JournalTransaction::factory()->for($otherBook)->create();
    $account = Account::factory()->for($book)->create();

    expect(fn () => Posting::factory()->create([
        'book_id' => $book->id,
        'journal_transaction_id' => $transactionInOtherBook->id,
        'account_id' => $account->id,
    ]))->toThrow(QueryException::class);
});

test('an account whose container belongs to a different book is rejected', function () {
    $book = Book::factory()->create();
    $otherBook = Book::factory()->create();
    $containerInOtherBook = Container::factory()->for($otherBook)->create();

    expect(fn () => Account::factory()->for($book)->create(['container_id' => $containerInOtherBook->id]))
        ->toThrow(QueryException::class);
});

test('a reversal referencing an original transaction in a different book is rejected', function () {
    $book = Book::factory()->create();
    $otherBook = Book::factory()->create();
    $originalInOtherBook = JournalTransaction::factory()->for($otherBook)->posted()->create();

    expect(fn () => JournalTransaction::factory()->for($book)->create([
        'reverses_transaction_id' => $originalInOtherBook->id,
    ]))->toThrow(QueryException::class);
});
