<?php

namespace App\Domain\Ledger\Actions;

use App\Domain\Ledger\Actions\Concerns\RequiresPositiveAmount;
use App\Domain\Ledger\Actions\Concerns\ResolvesSystemAccount;
use App\Domain\Ledger\Data\PostingInput;
use App\Domain\Ledger\Data\PostJournalTransactionCommand;
use App\Domain\Ledger\Data\RegisterIncomeCommand;
use App\Domain\Ledger\Enums\SystemAccountRole;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Money\ValueObjects\MonetaryDecimal;

/**
 * Registers income received into an asset account (SCN-INC-001): the asset increases and the
 * book-owned income control account absorbs the counterpart. The category, when given, classifies
 * the income posting alone (LED-010, ADR-003).
 */
class RegisterIncomeAction
{
    use RequiresPositiveAmount;
    use ResolvesSystemAccount;

    public function __construct(private readonly PostJournalTransactionAction $kernel) {}

    public function handle(RegisterIncomeCommand $command): JournalTransaction
    {
        $incomeControl = $this->systemAccount($command->bookId, SystemAccountRole::IncomeControl);
        $amount = MonetaryDecimal::fromString($command->amount);
        $this->assertPositiveAmount($amount, 'amount');

        return $this->kernel->handle(new PostJournalTransactionCommand(
            bookId: $command->bookId,
            idempotencyKey: $command->idempotencyKey,
            effectiveAt: $command->effectiveAt,
            description: $command->description,
            postings: [
                new PostingInput($command->assetAccountId, (string) $amount, (string) $amount),
                new PostingInput(
                    accountId: $incomeControl->id,
                    nativeQuantity: (string) $amount->negated(),
                    functionalAmount: (string) $amount->negated(),
                    categoryId: $command->categoryId,
                ),
            ],
        ));
    }
}
