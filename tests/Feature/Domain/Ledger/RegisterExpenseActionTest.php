<?php

use App\Domain\Ledger\Actions\BootstrapBookAction;
use App\Domain\Ledger\Actions\PostJournalTransactionAction;
use App\Domain\Ledger\Actions\RegisterExpenseAction;
use App\Domain\Ledger\Actions\RegisterOpeningBalanceAction;
use App\Domain\Ledger\Data\RegisterExpenseCommand;
use App\Domain\Ledger\Data\RegisterOpeningBalanceCommand;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\SystemAccountRole;
use App\Domain\Ledger\Enums\TransactionStatus;
use App\Domain\Ledger\Exceptions\CrossBookReference;
use App\Domain\Ledger\Exceptions\InsufficientNativeBalance;
use App\Domain\Ledger\Exceptions\NonPositiveAmount;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Category;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Money\Models\Instrument;
use App\Models\User;
use Illuminate\Support\Carbon;

function bootstrapBookWithFundedAsset(string $openingBalance = '100'): array
{
    $user = User::factory()->create();
    $usdt = Instrument::factory()->create(['code' => 'USDT']);
    $book = (new BootstrapBookAction)->handle($user, $usdt, 'Personal');
    $asset = Account::factory()->for($book)->type(AccountType::Asset)->create(['native_instrument_id' => $usdt->id]);

    (new RegisterOpeningBalanceAction(new PostJournalTransactionAction))->handle(new RegisterOpeningBalanceCommand(
        bookId: $book->id,
        assetAccountId: $asset->id,
        amount: $openingBalance,
        idempotencyKey: 'opening',
        effectiveAt: Carbon::now(),
    ));

    return [$book, $asset];
}

test('a functional-instrument expense posts expense control against the asset', function () {
    [$book, $asset] = bootstrapBookWithFundedAsset('100');
    $food = Category::factory()->for($book)->create(['name' => 'Food']);

    $transaction = (new RegisterExpenseAction(new PostJournalTransactionAction))->handle(new RegisterExpenseCommand(
        bookId: $book->id,
        assetAccountId: $asset->id,
        amount: '10',
        categoryId: $food->id,
        feeAmount: null,
        idempotencyKey: 'expense-1',
        effectiveAt: Carbon::now(),
    ));

    expect($transaction->status)->toBe(TransactionStatus::Posted)
        ->and($transaction->postings)->toHaveCount(2);

    $expenseControl = Account::query()->where('book_id', $book->id)->where('system_role', SystemAccountRole::ExpenseControl)->firstOrFail();
    $expensePosting = $transaction->postings->firstWhere('account_id', $expenseControl->id);
    $assetPosting = $transaction->postings->firstWhere('account_id', $asset->id);

    expect($expensePosting->native_quantity)->toBe('10.000000000000000000')
        ->and($expensePosting->category_id)->toBe($food->id)
        ->and($assetPosting->native_quantity)->toBe('-10.000000000000000000');

    expect($asset->fresh()->postedNativeBalance())->toBe('90.000000000000000000');
});

test('an expense with a fee posting categorizes only the expense posting', function () {
    [$book, $asset] = bootstrapBookWithFundedAsset('100');
    $food = Category::factory()->for($book)->create(['name' => 'Food']);

    $transaction = (new RegisterExpenseAction(new PostJournalTransactionAction))->handle(new RegisterExpenseCommand(
        bookId: $book->id,
        assetAccountId: $asset->id,
        amount: '10',
        categoryId: $food->id,
        feeAmount: '0.5',
        idempotencyKey: 'expense-fee-1',
        effectiveAt: Carbon::now(),
    ));

    expect($transaction->postings)->toHaveCount(3);

    $expenseControl = Account::query()->where('book_id', $book->id)->where('system_role', SystemAccountRole::ExpenseControl)->firstOrFail();
    $fees = Account::query()->where('book_id', $book->id)->where('system_role', SystemAccountRole::Fees)->firstOrFail();

    $expensePosting = $transaction->postings->firstWhere('account_id', $expenseControl->id);
    $feePosting = $transaction->postings->firstWhere('account_id', $fees->id);
    $assetPosting = $transaction->postings->firstWhere('account_id', $asset->id);

    expect($expensePosting->category_id)->toBe($food->id)
        ->and($feePosting->category_id)->toBeNull()
        ->and($feePosting->native_quantity)->toBe('0.500000000000000000')
        ->and($assetPosting->native_quantity)->toBe('-10.500000000000000000');

    expect($asset->fresh()->postedNativeBalance())->toBe('89.500000000000000000');
});

test('ACC-006: an expense that would push the asset native balance negative is rejected by default', function () {
    [$book, $asset] = bootstrapBookWithFundedAsset('10');

    expect(fn () => (new RegisterExpenseAction(new PostJournalTransactionAction))->handle(new RegisterExpenseCommand(
        bookId: $book->id,
        assetAccountId: $asset->id,
        amount: '10.000000000000000001',
        categoryId: null,
        feeAmount: null,
        idempotencyKey: 'expense-overdraft',
        effectiveAt: Carbon::now(),
    )))->toThrow(InsufficientNativeBalance::class);

    expect($asset->fresh()->postedNativeBalance())->toBe('10.000000000000000000');
});

test('an expense that exactly exhausts the asset native balance is accepted', function () {
    [$book, $asset] = bootstrapBookWithFundedAsset('10');

    (new RegisterExpenseAction(new PostJournalTransactionAction))->handle(new RegisterExpenseCommand(
        bookId: $book->id,
        assetAccountId: $asset->id,
        amount: '10',
        categoryId: null,
        feeAmount: null,
        idempotencyKey: 'expense-exact',
        effectiveAt: Carbon::now(),
    ));

    expect($asset->fresh()->postedNativeBalance())->toBe('0.000000000000000000');
});

test('a negative or zero expense amount is rejected instead of funding the asset from nothing', function (string $amount) {
    // No opening balance action here (an empty asset account, never funded): the regression this
    // proves is specifically the "money from nothing" case, and RegisterOpeningBalanceAction
    // itself now also rejects a zero amount, so it cannot be used to set up a zero starting balance.
    $user = User::factory()->create();
    $usdt = Instrument::factory()->create(['code' => 'USDT']);
    $book = (new BootstrapBookAction)->handle($user, $usdt, 'Personal');
    $asset = Account::factory()->for($book)->type(AccountType::Asset)->create(['native_instrument_id' => $usdt->id]);

    // Regression for validation finding 1: RegisterExpenseCommand(amount: '-1000') on an empty
    // asset account used to post successfully (expense control -1000, asset +1000) and leave the
    // asset at +1000.000000000000000000 — money created from nothing, undetected by ACC-006
    // because "available + 1000" is positive.
    expect(fn () => (new RegisterExpenseAction(new PostJournalTransactionAction))->handle(new RegisterExpenseCommand(
        bookId: $book->id,
        assetAccountId: $asset->id,
        amount: $amount,
        categoryId: null,
        feeAmount: null,
        idempotencyKey: 'expense-non-positive',
        effectiveAt: Carbon::now(),
    )))->toThrow(NonPositiveAmount::class);

    expect(JournalTransaction::query()->where('idempotency_key', 'expense-non-positive')->count())->toBe(0)
        ->and($asset->fresh()->postedNativeBalance())->toBe('0.000000000000000000');
})->with([
    'negative' => '-1000',
    'zero' => '0',
]);

test('an asset account from another book surfaces as a cross-book rejection, not a balance error', function () {
    // Regression for validation finding 5: ACC-006's balance read used to query the account by id
    // with no book scope, so an asset account id from another book was read and reported as
    // InsufficientNativeBalance instead of the kernel's own CrossBookReference rejection.
    [$book] = bootstrapBookWithFundedAsset('100');
    $otherBook = Account::factory()->create(['type' => AccountType::Asset]);

    expect(fn () => (new RegisterExpenseAction(new PostJournalTransactionAction))->handle(new RegisterExpenseCommand(
        bookId: $book->id,
        assetAccountId: $otherBook->id,
        amount: '10',
        categoryId: null,
        feeAmount: null,
        idempotencyKey: 'expense-cross-book-asset',
        effectiveAt: Carbon::now(),
    )))->toThrow(CrossBookReference::class);

    expect(JournalTransaction::query()->where('idempotency_key', 'expense-cross-book-asset')->count())->toBe(0);
});

test('a negative or zero fee amount is rejected', function (string $feeAmount) {
    [$book, $asset] = bootstrapBookWithFundedAsset('100');

    expect(fn () => (new RegisterExpenseAction(new PostJournalTransactionAction))->handle(new RegisterExpenseCommand(
        bookId: $book->id,
        assetAccountId: $asset->id,
        amount: '10',
        categoryId: null,
        feeAmount: $feeAmount,
        idempotencyKey: 'expense-non-positive-fee',
        effectiveAt: Carbon::now(),
    )))->toThrow(NonPositiveAmount::class);

    expect(JournalTransaction::query()->where('idempotency_key', 'expense-non-positive-fee')->count())->toBe(0)
        ->and($asset->fresh()->postedNativeBalance())->toBe('100.000000000000000000');
})->with([
    'negative' => '-0.5',
    'zero' => '0',
]);
