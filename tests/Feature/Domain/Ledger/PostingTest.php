<?php

use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\Container;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Ledger\Models\Posting;
use App\Domain\Money\Exceptions\ExcessiveDecimalScale;
use App\Domain\Money\Exceptions\InvalidMonetaryValueType;
use App\Domain\Money\Exceptions\MalformedMonetaryDecimal;
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

test('the application rejects a monetary value with malformed decimal syntax on every engine', function () {
    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();
    $transaction = JournalTransaction::factory()->for($book)->create();

    expect(fn () => Posting::factory()->create([
        'book_id' => $book->id,
        'journal_transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'native_quantity' => '12.34.56',
    ]))->toThrow(MalformedMonetaryDecimal::class);
});

test('the application rejects a monetary value that is not numeric on every engine', function () {
    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();
    $transaction = JournalTransaction::factory()->for($book)->create();

    expect(fn () => Posting::factory()->create([
        'book_id' => $book->id,
        'journal_transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'functional_amount' => 'not-a-number',
    ]))->toThrow(MalformedMonetaryDecimal::class);
});

test('a non-canonical decimal literal is rejected instead of falling through to engine-specific syntax rules', function (string $value) {
    // PostgreSQL's `numeric` accepts exponent notation, leading whitespace, and a missing integer
    // part; SQLite's CHECK constraint does not. Left unhandled, each of these forms reaches
    // PostgreSQL, is silently rounded (or, for '1e-19', collapsed to zero), and is rejected by
    // SQLite instead — the same engine-dependent divergence this task exists to close (validation
    // finding 1, 2026-08-08). The value object rejects all of them before either engine is reached.
    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();
    $transaction = JournalTransaction::factory()->for($book)->create();

    expect(fn () => Posting::factory()->create([
        'book_id' => $book->id,
        'journal_transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'native_quantity' => $value,
    ]))->toThrow(MalformedMonetaryDecimal::class);
})->with([
    'exponent notation' => '1.1234567890123456789e0',
    'exponent notation that underflows to zero' => '1e-19',
    'leading whitespace' => ' 1.1234567890123456789',
    'missing integer part' => '.1234567890123456789',
    'trailing newline' => "1.5\n",
    'trailing space' => '1.5 ',
    'trailing tab' => "1.5\t",
    'trailing carriage return' => "1.5\r",
]);

test('a non-canonical but syntactically valid literal persists in canonical form on every engine', function (string $value, string $canonical) {
    // PostgreSQL's `numeric` normalizes a leading '+', redundant leading zeros, and negative zero on
    // write; a plain text column on SQLite does not. Without normalizing in MonetaryDecimal, the
    // same accepted literal would read back differently depending on which engine stored it
    // (validation finding 6, 2026-08-08). Normalization changes representation only, never the
    // numeric value, so it introduces no new LIF-012 rounding boundary.
    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();
    $transaction = JournalTransaction::factory()->for($book)->create();

    $posting = Posting::factory()->create([
        'book_id' => $book->id,
        'journal_transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'native_quantity' => $value,
    ]);

    expect($posting->fresh()->native_quantity)->toBe($canonical);
})->with([
    'a redundant leading plus sign' => ['+1.5', '1.500000000000000000'],
    'redundant leading zeros' => ['007.5', '7.500000000000000000'],
    'negative zero' => ['-0.0', '0.000000000000000000'],
    'a redundant leading zero before the decimal point' => ['00.10', '0.100000000000000000'],
]);

test('a binary float is rejected instead of being silently truncated', function (float $value) {
    // Stringifying a float before the scale check would coerce it at PHP's `precision` setting —
    // 0.1 + 0.2 becomes the string "0.3" — which is exactly the silent-rounding boundary this guard
    // exists to prevent (ADR-001 forbids float/double for monetary values).
    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();
    $transaction = JournalTransaction::factory()->for($book)->create();

    expect(fn () => Posting::factory()->create([
        'book_id' => $book->id,
        'journal_transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'native_quantity' => $value,
    ]))->toThrow(InvalidMonetaryValueType::class);
})->with([
    0.1 + 0.2,
    1.1234567890123457,
]);

test('a monetary value with more than 18 fractional digits is rejected at the application boundary on every engine', function (string $column) {
    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();
    $transaction = JournalTransaction::factory()->for($book)->create();

    expect(fn () => Posting::factory()->create([
        'book_id' => $book->id,
        'journal_transaction_id' => $transaction->id,
        'account_id' => $account->id,
        $column => '1.1234567890123456789',
    ]))->toThrow(ExcessiveDecimalScale::class);
})->with([
    'native_quantity',
    'functional_amount',
]);

test('the application guard cannot be bypassed by constructing a posting model directly', function () {
    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();
    $transaction = JournalTransaction::factory()->for($book)->create();

    expect(fn () => new Posting([
        'book_id' => $book->id,
        'journal_transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'native_quantity' => '1.1234567890123456789',
        'functional_amount' => '1.00',
    ]))->toThrow(ExcessiveDecimalScale::class);
});

test('an update issued through the query builder bypasses the model cast, unlike an update through the model', function () {
    // Eloquent's query builder writes columns directly and never instantiates a model or invokes
    // any cast, so MonetaryScale::set() is not called here — the same "below the boundary" class as
    // DB::table() or raw SQL. This is a documented, accepted limitation (validation finding 2,
    // 2026-08-08), not a closed one: the ledger's own actions create and correct postings through
    // the model (LIF-003, LIF-004), never through a bulk query-builder update. SQLite's CHECK
    // constraint still catches this independently; PostgreSQL's DECIMAL(38, 18) does not.
    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();
    $transaction = JournalTransaction::factory()->for($book)->create();
    $posting = Posting::factory()->create([
        'book_id' => $book->id,
        'journal_transaction_id' => $transaction->id,
        'account_id' => $account->id,
    ]);

    if (DB::connection()->getDriverName() === 'sqlite') {
        expect(fn () => Posting::query()->whereKey($posting->id)->update([
            'native_quantity' => '1.1234567890123456789',
        ]))->toThrow(QueryException::class);

        return;
    }

    Posting::query()->whereKey($posting->id)->update([
        'native_quantity' => '1.1234567890123456789',
    ]);

    expect(DB::table('postings')->where('id', $posting->id)->value('native_quantity'))
        ->not->toBe('1.1234567890123456789');
});

test('sqlite rejects an over-scale monetary value written directly to the table as a redundant safety net', function () {
    skipUnlessSqlite();

    $book = Book::factory()->create();
    $account = Account::factory()->for($book)->create();
    $transaction = JournalTransaction::factory()->for($book)->create();

    expect(fn () => DB::table('postings')->insert([
        'book_id' => $book->id,
        'journal_transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'native_quantity' => '1.1234567890123456789',
        'functional_amount' => '1.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

function skipUnlessSqlite(): void
{
    if (DB::connection()->getDriverName() !== 'sqlite') {
        test()->markTestSkipped(
            'This proves SQLite\'s own CHECK constraint independently of the application guard '
            .'(the guard is bypassed here via a raw insert); PostgreSQL has no equivalent '
            .'database-level rejection for over-scale input because DECIMAL(38, 18) rounds it '
            .'silently instead of rejecting it (ADR-001, 2026-08-08 amendment).'
        );
    }
}
