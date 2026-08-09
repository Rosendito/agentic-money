<?php

namespace App\Domain\Ledger\Actions;

use App\Domain\Ledger\Actions\Concerns\AssertsValidPaymentAccount;
use App\Domain\Ledger\Actions\Concerns\RequiresPositiveAmount;
use App\Domain\Ledger\Actions\Concerns\ResolvesSystemAccount;
use App\Domain\Ledger\Data\PostingInput;
use App\Domain\Ledger\Data\PostJournalTransactionCommand;
use App\Domain\Ledger\Data\RegisterExpenseCommand;
use App\Domain\Ledger\Enums\SystemAccountRole;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Money\ValueObjects\MonetaryDecimal;

/**
 * Registers a functional-instrument expense paid from an asset account (SCN-EXP-001's shape): the
 * expense control account absorbs the categorized amount, an optional fee is posted uncategorized
 * to the fees control account, and the asset decreases by their sum. Rejects, by default, spending
 * that would push the asset's posted native balance negative (ACC-006), computed from posted
 * postings with no balance column (LED-011).
 *
 * The ACC-006 balance decision is delegated to the kernel
 * ({@see PostJournalTransactionAction::$accountsRequiringNonNegativeBalance} via the command), not
 * decided here: the kernel locks the asset account row (`lockForUpdate`) and re-derives the
 * available balance inside the same database transaction that creates the postings, so the
 * check-then-post race a concurrent expense could otherwise win (external-review finding 4) is
 * closed by construction rather than by this action reading the balance first, unlocked.
 */
class RegisterExpenseAction
{
    use AssertsValidPaymentAccount;
    use RequiresPositiveAmount;
    use ResolvesSystemAccount;

    public function __construct(private readonly PostJournalTransactionAction $kernel) {}

    public function handle(RegisterExpenseCommand $command): JournalTransaction
    {
        $this->assertValidPaymentAccount($command->bookId, $command->assetAccountId);

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
            accountsRequiringNonNegativeBalance: [$command->assetAccountId],
        ));
    }
}
