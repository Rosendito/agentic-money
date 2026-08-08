<?php

use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Ledger\Models\Posting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('deleting a user with a book is rejected instead of cascading', function () {
    $user = User::factory()->create();
    Book::factory()->for($user)->create();

    expect(fn () => DB::transaction(fn () => $user->delete()))->toThrow(QueryException::class);
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('deleting a book with an account is rejected instead of cascading', function () {
    $book = Book::factory()->create();
    Account::factory()->for($book)->create();

    expect(fn () => DB::transaction(fn () => $book->delete()))->toThrow(QueryException::class);
    expect(Book::query()->whereKey($book->id)->exists())->toBeTrue();
});

test('deleting a book with a posted transaction is rejected instead of cascading', function () {
    $book = Book::factory()->create();
    JournalTransaction::factory()->for($book)->posted()->create();

    expect(fn () => DB::transaction(fn () => $book->delete()))->toThrow(QueryException::class);
    expect(Book::query()->whereKey($book->id)->exists())->toBeTrue();
});

test('deleting a book with a posting is rejected instead of cascading', function () {
    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();
    $transaction = JournalTransaction::factory()->for($book)->posted()->create();

    Posting::factory()->create([
        'book_id' => $book->id,
        'journal_transaction_id' => $transaction->id,
        'account_id' => $account->id,
    ]);

    expect(fn () => DB::transaction(fn () => $book->delete()))->toThrow(QueryException::class);
    expect(Posting::query()->where('book_id', $book->id)->exists())->toBeTrue();
});
