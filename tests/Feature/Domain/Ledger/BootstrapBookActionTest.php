<?php

use App\Domain\Ledger\Actions\BootstrapBookAction;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\SystemAccountRole;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Book;
use App\Domain\Money\Models\Instrument;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('bootstrapping a book creates exactly one system account per role', function () {
    $user = User::factory()->create();
    $instrument = Instrument::factory()->create();

    $book = (new BootstrapBookAction)->handle($user, $instrument, 'Personal');

    expect($book->exists)->toBeTrue()
        ->and($book->functional_instrument_id)->toBe($instrument->id);

    foreach (SystemAccountRole::cases() as $role) {
        $accounts = Account::query()->where('book_id', $book->id)->where('system_role', $role)->get();

        expect($accounts)->toHaveCount(1);
        expect($accounts->first()->native_instrument_id)->toBe($instrument->id);
    }

    expect(Account::query()->where('book_id', $book->id)->count())->toBe(count(SystemAccountRole::cases()));
});

test('a second bootstrap of the same book does not duplicate the book or its system accounts', function () {
    $user = User::factory()->create();
    $instrument = Instrument::factory()->create();

    $first = (new BootstrapBookAction)->handle($user, $instrument, 'Personal');
    $second = (new BootstrapBookAction)->handle($user, $instrument, 'Personal');

    expect($second->id)->toBe($first->id);
    expect(Account::query()->where('book_id', $first->id)->count())->toBe(count(SystemAccountRole::cases()));
});

test('the opening equity system account is created as an equity account', function () {
    $user = User::factory()->create();
    $instrument = Instrument::factory()->create();

    $book = (new BootstrapBookAction)->handle($user, $instrument, 'Personal');

    $openingEquity = Account::query()
        ->where('book_id', $book->id)
        ->where('system_role', SystemAccountRole::OpeningEquity)
        ->firstOrFail();

    expect($openingEquity->type)->toBe(AccountType::Equity);
});

test('the database rejects a second system account for the same role in the same book', function () {
    $book = Book::factory()->create();
    $instrument = Instrument::factory()->create();

    Account::factory()->for($book)->create([
        'system_role' => SystemAccountRole::OpeningEquity,
        'native_instrument_id' => $instrument->id,
    ]);

    expect(fn () => DB::table('accounts')->insert([
        'book_id' => $book->id,
        'name' => 'duplicate opening equity',
        'type' => AccountType::Equity->value,
        'native_instrument_id' => $instrument->id,
        'system_role' => SystemAccountRole::OpeningEquity->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('the database allows more than one ordinary account with no system role in the same book', function () {
    $book = Book::factory()->create();

    Account::factory()->for($book)->create(['system_role' => null]);
    $second = Account::factory()->for($book)->create(['system_role' => null]);

    expect($second->exists)->toBeTrue();
});
