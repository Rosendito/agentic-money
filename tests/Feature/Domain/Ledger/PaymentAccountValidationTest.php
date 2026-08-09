<?php

use App\Domain\Ledger\Actions\BootstrapBookAction;
use App\Domain\Ledger\Actions\PostJournalTransactionAction;
use App\Domain\Ledger\Actions\RegisterExpenseAction;
use App\Domain\Ledger\Actions\RegisterIncomeAction;
use App\Domain\Ledger\Actions\RegisterOpeningBalanceAction;
use App\Domain\Ledger\Data\RegisterExpenseCommand;
use App\Domain\Ledger\Data\RegisterIncomeCommand;
use App\Domain\Ledger\Data\RegisterOpeningBalanceCommand;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\SystemAccountRole;
use App\Domain\Ledger\Exceptions\ArchivedAccountNotSelectable;
use App\Domain\Ledger\Exceptions\PaymentAccountInstrumentMismatch;
use App\Domain\Ledger\Exceptions\PaymentAccountTypeMismatch;
use App\Domain\Ledger\Exceptions\SystemAccountNotSelectable;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Money\Models\Instrument;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Regression coverage for external-review finding 6: RegisterOpeningBalanceAction,
 * RegisterIncomeAction, and RegisterExpenseAction all resolve `assetAccountId` through the shared
 * App\Domain\Ledger\Actions\Concerns\AssertsValidPaymentAccount trait, so one dataset of scenarios
 * proves the guard for all three.
 */
function bookWithFunctionalInstrument(): array
{
    $user = User::factory()->create();
    $usdt = Instrument::factory()->create(['code' => 'USDT']);
    $book = (new BootstrapBookAction)->handle($user, $usdt, 'Personal');

    return [$book, $usdt];
}

function attemptWithAccount(string $action, int $bookId, int $accountId): void
{
    match ($action) {
        'opening' => (new RegisterOpeningBalanceAction(new PostJournalTransactionAction))->handle(new RegisterOpeningBalanceCommand(
            bookId: $bookId,
            assetAccountId: $accountId,
            amount: '10',
            idempotencyKey: 'attempt-'.$action.'-'.$accountId,
            effectiveAt: Carbon::now(),
        )),
        'income' => (new RegisterIncomeAction(new PostJournalTransactionAction))->handle(new RegisterIncomeCommand(
            bookId: $bookId,
            assetAccountId: $accountId,
            amount: '10',
            categoryId: null,
            idempotencyKey: 'attempt-'.$action.'-'.$accountId,
            effectiveAt: Carbon::now(),
        )),
        'expense' => (new RegisterExpenseAction(new PostJournalTransactionAction))->handle(new RegisterExpenseCommand(
            bookId: $bookId,
            assetAccountId: $accountId,
            amount: '10',
            categoryId: null,
            feeAmount: null,
            idempotencyKey: 'attempt-'.$action.'-'.$accountId,
            effectiveAt: Carbon::now(),
        )),
    };
}

test('a system account cannot be selected as the payment account', function (string $action) {
    // Exact external-review scenario: an OpeningEquity system account passed as assetAccountId used
    // to post successfully (e.g. income against equity with no asset posting).
    [$book] = bookWithFunctionalInstrument();
    $openingEquity = Account::query()->where('book_id', $book->id)->where('system_role', SystemAccountRole::OpeningEquity)->firstOrFail();

    expect(fn () => attemptWithAccount($action, $book->id, $openingEquity->id))
        ->toThrow(SystemAccountNotSelectable::class);

    expect(JournalTransaction::query()->where('book_id', $book->id)->count())->toBe(0);
})->with(['opening', 'income', 'expense']);

test('a non-asset account cannot be selected as the payment account', function (string $action) {
    [$book, $usdt] = bookWithFunctionalInstrument();
    $liability = Account::factory()->for($book)->type(AccountType::Liability)->create(['native_instrument_id' => $usdt->id]);

    expect(fn () => attemptWithAccount($action, $book->id, $liability->id))
        ->toThrow(PaymentAccountTypeMismatch::class);

    expect(JournalTransaction::query()->where('book_id', $book->id)->count())->toBe(0);
})->with(['opening', 'income', 'expense']);

test('an archived asset account cannot be selected as the payment account', function (string $action) {
    [$book, $usdt] = bookWithFunctionalInstrument();
    $archived = Account::factory()->for($book)->type(AccountType::Asset)->archived()->create(['native_instrument_id' => $usdt->id]);

    expect(fn () => attemptWithAccount($action, $book->id, $archived->id))
        ->toThrow(ArchivedAccountNotSelectable::class);

    expect(JournalTransaction::query()->where('book_id', $book->id)->count())->toBe(0);
})->with(['opening', 'income', 'expense']);

test('an asset account denominated in a different instrument than the book cannot be selected', function (string $action) {
    // Exact external-review scenario: a VES asset account posted +7 VES / +7 USDT by a
    // functional-instrument-only action, because nothing checked the account's instrument against
    // the book's functional instrument.
    [$book] = bookWithFunctionalInstrument();
    $ves = Instrument::factory()->create(['code' => 'VES']);
    $vesAccount = Account::factory()->for($book)->type(AccountType::Asset)->create(['native_instrument_id' => $ves->id]);

    expect(fn () => attemptWithAccount($action, $book->id, $vesAccount->id))
        ->toThrow(PaymentAccountInstrumentMismatch::class);

    expect(JournalTransaction::query()->where('book_id', $book->id)->count())->toBe(0);
})->with(['opening', 'income', 'expense']);

test('an ordinary, active asset account in the book instrument is accepted', function (string $action) {
    [$book, $usdt] = bookWithFunctionalInstrument();
    $asset = Account::factory()->for($book)->type(AccountType::Asset)->create(['native_instrument_id' => $usdt->id]);

    // Opening balance must run first for income/expense to have something to work with.
    (new RegisterOpeningBalanceAction(new PostJournalTransactionAction))->handle(new RegisterOpeningBalanceCommand(
        bookId: $book->id,
        assetAccountId: $asset->id,
        amount: '100',
        idempotencyKey: 'seed-'.$action,
        effectiveAt: Carbon::now(),
    ));

    attemptWithAccount($action, $book->id, $asset->id);

    expect(JournalTransaction::query()->where('book_id', $book->id)->count())->toBe(2);
})->with(['income', 'expense']);
