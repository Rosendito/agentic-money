<?php

namespace App\Domain\Ledger\Actions;

use App\Domain\Ledger\Actions\Concerns\RequiresPositiveAmount;
use App\Domain\Ledger\Actions\Concerns\ResolvesSystemAccount;
use App\Domain\Ledger\Data\PostingInput;
use App\Domain\Ledger\Data\PostJournalTransactionCommand;
use App\Domain\Ledger\Data\RegisterExpenseCommand;
use App\Domain\Ledger\Enums\SystemAccountRole;
use App\Domain\Ledger\Exceptions\InsufficientNativeBalance;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Money\ValueObjects\MonetaryDecimal;

/**
 * Registers a functional-instrument expense paid from an asset account (SCN-EXP-001's shape): the
 * expense control account absorbs the categorized amount, an optional fee is posted uncategorized
 * to the fees control account, and the asset decreases by their sum. Rejects, by default, spending
 * that would push the asset's posted native balance negative (ACC-006), computed from posted
 * postings with no balance column (LED-011).
 */
class RegisterExpenseAction
{
    use RequiresPositiveAmount;
    use ResolvesSystemAccount;

    public function __construct(private readonly PostJournalTransactionAction $kernel) {}

    public function handle(RegisterExpenseCommand $command): JournalTransaction
    {
        $expenseControl = $this->systemAccount($command->bookId, SystemAccountRole::ExpenseControl);
        $amount = MonetaryDecimal::fromString($command->amount);
        $this->assertPositiveAmount($amount, 'amount');

        $postings = [
            new PostingInput(
                accountId: $expenseControl->id,
                nativeQuantity: (string) $amount,
                functionalAmount: (string) $amount,
                categoryId: $command->categoryId,
            ),
        ];

        $totalOutflow = $amount;

        if ($command->feeAmount !== null) {
            $fees = $this->systemAccount($command->bookId, SystemAccountRole::Fees);
            $feeAmount = MonetaryDecimal::fromString($command->feeAmount);
            $this->assertPositiveAmount($feeAmount, 'feeAmount');

            $postings[] = new PostingInput($fees->id, (string) $feeAmount, (string) $feeAmount);
            $totalOutflow = MonetaryDecimal::sum([$totalOutflow, $feeAmount]);
        }

        $this->assertSufficientNativeBalance($command->bookId, $command->assetAccountId, $totalOutflow);

        $postings[] = new PostingInput(
            accountId: $command->assetAccountId,
            nativeQuantity: (string) $totalOutflow->negated(),
            functionalAmount: (string) $totalOutflow->negated(),
        );

        return $this->kernel->handle(new PostJournalTransactionCommand(
            bookId: $command->bookId,
            idempotencyKey: $command->idempotencyKey,
            effectiveAt: $command->effectiveAt,
            description: $command->description,
            postings: $postings,
        ));
    }

    private function assertSufficientNativeBalance(int $bookId, int $assetAccountId, MonetaryDecimal $outflow): void
    {
        // Scoped to the command's own book: an asset account id from another book must surface as
        // the kernel's CrossBookReference rejection, not as a balance read against a foreign
        // account (validation finding 5).
        $account = Account::query()->where('book_id', $bookId)->find($assetAccountId);

        if ($account === null) {
            return;
        }

        $available = MonetaryDecimal::fromString($account->postedNativeBalance());
        $projected = MonetaryDecimal::sum([$available, (string) $outflow->negated()]);

        if ($projected->isNegative()) {
            throw new InsufficientNativeBalance($assetAccountId, (string) $available, (string) $outflow);
        }
    }
}
