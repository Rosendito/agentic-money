<?php

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Exceptions\AccountAttributeIsImmutable;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Ledger\Models\Posting;
use App\Domain\Money\Models\Instrument;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function createPostedPostingFor(Account $account): Posting
{
    $transaction = JournalTransaction::factory()->for($account->book)->posted()->create();

    return Posting::factory()->create([
        'book_id' => $account->book_id,
        'journal_transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'native_quantity' => '10.000000000000000000',
        'functional_amount' => '10.000000000000000000',
    ]);
}

test("an account's native instrument cannot change once it has a posting", function () {
    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();
    createPostedPostingFor($account);

    $newInstrument = Instrument::factory()->create();

    expect(fn () => $account->update(['native_instrument_id' => $newInstrument->id]))
        ->toThrow(AccountAttributeIsImmutable::class);
});

test("an account's type cannot change once it has a posting", function () {
    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();
    createPostedPostingFor($account);

    expect(fn () => $account->update(['type' => AccountType::Liability]))
        ->toThrow(AccountAttributeIsImmutable::class);
});

test("an account's native instrument and type can still change before it has any posting", function () {
    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();

    $newInstrument = Instrument::factory()->create();
    $account->update(['native_instrument_id' => $newInstrument->id, 'type' => AccountType::Liability]);

    expect($account->fresh())
        ->native_instrument_id->toBe($newInstrument->id)
        ->type->toBe(AccountType::Liability);
});

test('a system account factory state produces an account with a system role', function () {
    $account = Account::factory()->system()->create();

    expect($account->system_role)->not->toBeNull();
});

test('an archived account factory state produces an account with an archive timestamp', function () {
    $account = Account::factory()->archived()->create();

    expect($account->archived_at)->not->toBeNull();
});

test('the database rejects an account type outside the closed vocabulary', function () {
    $account = Account::factory()->make();

    expect(fn () => DB::table('accounts')->insert([
        ...$account->toArray(),
        'type' => 'NotARealType',
    ]))->toThrow(QueryException::class);
});

test('the database rejects a system role outside the closed vocabulary', function () {
    $account = Account::factory()->make();

    expect(fn () => DB::table('accounts')->insert([
        ...$account->toArray(),
        'type' => AccountType::Asset->value,
        'system_role' => 'NotARealRole',
    ]))->toThrow(QueryException::class);
});
