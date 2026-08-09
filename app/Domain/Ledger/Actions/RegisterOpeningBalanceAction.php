<?php

namespace App\Domain\Ledger\Actions;

use App\Domain\Ledger\Actions\Concerns\RequiresPositiveAmount;
use App\Domain\Ledger\Actions\Concerns\ResolvesSystemAccount;
use App\Domain\Ledger\Data\PostingInput;
use App\Domain\Ledger\Data\PostJournalTransactionCommand;
use App\Domain\Ledger\Data\RegisterOpeningBalanceCommand;
use App\Domain\Ledger\Enums\SystemAccountRole;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Money\ValueObjects\MonetaryDecimal;

/**
 * Registers an opening balance for an existing asset position (SCN-OPEN-001): the asset increases
 * and the book-owned opening equity account absorbs the counterpart, reporting no income (ACC-008).
 */
class RegisterOpeningBalanceAction
{
    use RequiresPositiveAmount;
    use ResolvesSystemAccount;

    public function __construct(private readonly PostJournalTransactionAction $kernel) {}

    public function handle(RegisterOpeningBalanceCommand $command): JournalTransaction
    {
        $openingEquity = $this->systemAccount($command->bookId, SystemAccountRole::OpeningEquity);
        $amount = MonetaryDecimal::fromString($command->amount);
        $this->assertPositiveAmount($amount, 'amount');

        return $this->kernel->handle(new PostJournalTransactionCommand(
            bookId: $command->bookId,
            idempotencyKey: $command->idempotencyKey,
            effectiveAt: $command->effectiveAt,
            description: $command->description,
            postings: [
                new PostingInput($command->assetAccountId, (string) $amount, (string) $amount),
                new PostingInput($openingEquity->id, (string) $amount->negated(), (string) $amount->negated()),
            ],
        ));
    }
}
