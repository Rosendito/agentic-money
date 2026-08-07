<?php

use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\Container;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Ledger\Models\Posting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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

test('the default factory produces canonical decimal strings, never a binary float', function () {
    $posting = Posting::factory()->create();

    expect($posting->getRawOriginal('native_quantity'))->toBeString()
        ->and($posting->getRawOriginal('native_quantity'))->toMatch('/^-?\d+\.\d{1,18}$/')
        ->and($posting->getRawOriginal('functional_amount'))->toBeString()
        ->and($posting->getRawOriginal('functional_amount'))->toMatch('/^-?\d+\.\d{1,18}$/');
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

test('the database rejects a monetary value with malformed decimal syntax on sqlite', function () {
    skipUnlessSqlite();

    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();
    $transaction = JournalTransaction::factory()->for($book)->create();

    expect(fn () => Posting::factory()->create([
        'book_id' => $book->id,
        'journal_transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'native_quantity' => '12.34.56',
    ]))->toThrow(QueryException::class);
});

test('the database rejects a monetary value that is not numeric on sqlite', function () {
    skipUnlessSqlite();

    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();
    $transaction = JournalTransaction::factory()->for($book)->create();

    expect(fn () => Posting::factory()->create([
        'book_id' => $book->id,
        'journal_transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'functional_amount' => 'not-a-number',
    ]))->toThrow(QueryException::class);
});

test('the database rejects a monetary value with more than 18 fractional digits on sqlite', function () {
    skipUnlessSqlite();

    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();
    $transaction = JournalTransaction::factory()->for($book)->create();

    expect(fn () => Posting::factory()->create([
        'book_id' => $book->id,
        'journal_transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'native_quantity' => '1.1234567890123456789',
    ]))->toThrow(QueryException::class);
});

function skipUnlessSqlite(): void
{
    if (DB::connection()->getDriverName() !== 'sqlite') {
        test()->markTestSkipped(
            'Canonical decimal syntax CHECK constraints apply to SQLite only; MySQL/PostgreSQL enforce '
            .'this natively through DECIMAL(38, 18) (ADR-001).'
        );
    }
}
