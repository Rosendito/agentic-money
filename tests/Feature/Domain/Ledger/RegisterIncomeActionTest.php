<?php

use App\Domain\Ledger\Actions\BootstrapBookAction;
use App\Domain\Ledger\Actions\PostJournalTransactionAction;
use App\Domain\Ledger\Actions\RegisterIncomeAction;
use App\Domain\Ledger\Data\RegisterIncomeCommand;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\SystemAccountRole;
use App\Domain\Ledger\Enums\TransactionStatus;
use App\Domain\Ledger\Exceptions\NonPositiveAmount;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Category;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Money\Models\Instrument;
use App\Models\User;
use Illuminate\Support\Carbon;

test('SCN-INC-001: income posts the asset against income control and carries its category on the income posting', function () {
    $user = User::factory()->create();
    $usdt = Instrument::factory()->create(['code' => 'USDT']);
    $book = (new BootstrapBookAction)->handle($user, $usdt, 'Personal');
    $asset = Account::factory()->for($book)->type(AccountType::Asset)->create(['native_instrument_id' => $usdt->id]);
    $salary = Category::factory()->for($book)->create(['name' => 'Salary']);

    $transaction = (new RegisterIncomeAction(new PostJournalTransactionAction))->handle(new RegisterIncomeCommand(
        bookId: $book->id,
        assetAccountId: $asset->id,
        amount: '100',
        categoryId: $salary->id,
        idempotencyKey: 'income-1',
        effectiveAt: Carbon::now(),
        description: 'Salary payment',
    ));

    expect($transaction->status)->toBe(TransactionStatus::Posted)
        ->and($transaction->postings)->toHaveCount(2);

    $incomeControl = Account::query()->where('book_id', $book->id)->where('system_role', SystemAccountRole::IncomeControl)->firstOrFail();
    $assetPosting = $transaction->postings->firstWhere('account_id', $asset->id);
    $incomePosting = $transaction->postings->firstWhere('account_id', $incomeControl->id);

    expect($assetPosting->native_quantity)->toBe('100.000000000000000000')
        ->and($assetPosting->category_id)->toBeNull()
        ->and($incomePosting->native_quantity)->toBe('-100.000000000000000000')
        ->and($incomePosting->functional_amount)->toBe('-100.000000000000000000')
        ->and($incomePosting->category_id)->toBe($salary->id);

    expect($asset->fresh()->postedNativeBalance())->toBe('100.000000000000000000');
});

test('a negative income amount is rejected instead of driving the asset negative', function (string $amount) {
    $user = User::factory()->create();
    $usdt = Instrument::factory()->create(['code' => 'USDT']);
    $book = (new BootstrapBookAction)->handle($user, $usdt, 'Personal');
    $asset = Account::factory()->for($book)->type(AccountType::Asset)->create(['native_instrument_id' => $usdt->id]);

    // Regression for validation finding 1: RegisterIncomeCommand(amount: '-100') used to post
    // successfully and drive the asset to -100.000000000000000000 — the exact accidental negative
    // asset ACC-006 exists to prevent, on a path that has no balance check at all.
    expect(fn () => (new RegisterIncomeAction(new PostJournalTransactionAction))->handle(new RegisterIncomeCommand(
        bookId: $book->id,
        assetAccountId: $asset->id,
        amount: $amount,
        categoryId: null,
        idempotencyKey: 'income-non-positive',
        effectiveAt: Carbon::now(),
    )))->toThrow(NonPositiveAmount::class);

    expect(JournalTransaction::query()->count())->toBe(0)
        ->and($asset->fresh()->postedNativeBalance())->toBe('0.000000000000000000');
})->with([
    'negative' => '-100',
    'zero' => '0',
]);
