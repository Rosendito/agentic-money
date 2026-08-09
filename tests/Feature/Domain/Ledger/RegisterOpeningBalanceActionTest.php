<?php

use App\Domain\Ledger\Actions\BootstrapBookAction;
use App\Domain\Ledger\Actions\PostJournalTransactionAction;
use App\Domain\Ledger\Actions\RegisterOpeningBalanceAction;
use App\Domain\Ledger\Data\RegisterOpeningBalanceCommand;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\SystemAccountRole;
use App\Domain\Ledger\Enums\TransactionStatus;
use App\Domain\Ledger\Exceptions\NonPositiveAmount;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Money\Models\Instrument;
use App\Models\User;
use Illuminate\Support\Carbon;

test('SCN-OPEN-001: an opening balance posts the asset against opening equity and increases the native balance', function () {
    $user = User::factory()->create();
    $usdt = Instrument::factory()->create(['code' => 'USDT']);
    $book = (new BootstrapBookAction)->handle($user, $usdt, 'Personal');
    $asset = Account::factory()->for($book)->type(AccountType::Asset)->create(['native_instrument_id' => $usdt->id]);

    $transaction = (new RegisterOpeningBalanceAction(new PostJournalTransactionAction))->handle(new RegisterOpeningBalanceCommand(
        bookId: $book->id,
        assetAccountId: $asset->id,
        amount: '500',
        idempotencyKey: 'open-1',
        effectiveAt: Carbon::now(),
        description: 'Opening Binance balance',
    ));

    expect($transaction->status)->toBe(TransactionStatus::Posted)
        ->and($transaction->postings)->toHaveCount(2);

    $assetPosting = $transaction->postings->firstWhere('account_id', $asset->id);
    $equityAccount = Account::query()->where('book_id', $book->id)->where('system_role', SystemAccountRole::OpeningEquity)->firstOrFail();
    $equityPosting = $transaction->postings->firstWhere('account_id', $equityAccount->id);

    expect($assetPosting->native_quantity)->toBe('500.000000000000000000')
        ->and($assetPosting->functional_amount)->toBe('500.000000000000000000')
        ->and($equityPosting->native_quantity)->toBe('-500.000000000000000000')
        ->and($equityPosting->functional_amount)->toBe('-500.000000000000000000');

    expect($asset->fresh()->postedNativeBalance())->toBe('500.000000000000000000');

    // No income is reported: the counterpart is equity, not the income control account.
    expect($equityAccount->type)->toBe(AccountType::Equity);
});

test('a negative or zero opening balance amount is rejected before any posting', function (string $amount) {
    $user = User::factory()->create();
    $usdt = Instrument::factory()->create(['code' => 'USDT']);
    $book = (new BootstrapBookAction)->handle($user, $usdt, 'Personal');
    $asset = Account::factory()->for($book)->type(AccountType::Asset)->create(['native_instrument_id' => $usdt->id]);

    expect(fn () => (new RegisterOpeningBalanceAction(new PostJournalTransactionAction))->handle(new RegisterOpeningBalanceCommand(
        bookId: $book->id,
        assetAccountId: $asset->id,
        amount: $amount,
        idempotencyKey: 'open-non-positive',
        effectiveAt: Carbon::now(),
    )))->toThrow(NonPositiveAmount::class);

    expect(JournalTransaction::query()->count())->toBe(0)
        ->and($asset->fresh()->postedNativeBalance())->toBe('0.000000000000000000');
})->with([
    'negative' => '-500',
    'zero' => '0',
]);
