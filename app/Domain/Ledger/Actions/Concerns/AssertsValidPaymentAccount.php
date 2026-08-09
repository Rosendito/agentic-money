<?php

namespace App\Domain\Ledger\Actions\Concerns;

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Exceptions\ArchivedAccountNotSelectable;
use App\Domain\Ledger\Exceptions\PaymentAccountInstrumentMismatch;
use App\Domain\Ledger\Exceptions\PaymentAccountTypeMismatch;
use App\Domain\Ledger\Exceptions\SystemAccountNotSelectable;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Book;

/**
 * Shared validation for the account a caller selects to pay from or receive into (`assetAccountId`
 * on `RegisterOpeningBalanceCommand`, `RegisterIncomeCommand`, and `RegisterExpenseCommand`). None
 * of these actions have a valuation or FX policy in this slice, so they require an ordinary,
 * active Asset account denominated in the book's functional instrument (external-review finding 6):
 * a system account, a wrong account type, an archived account, or an instrument mismatch each
 * produce a nonsensical posting shape if left unchecked.
 *
 * An account id that does not belong to the given book is left unrejected here: it falls through to
 * the kernel's own `CrossBookReference` rejection (LIF-016), the same book-scoping precedent
 * `RegisterExpenseAction`'s ACC-006 check already followed.
 */
trait AssertsValidPaymentAccount
{
    private function assertValidPaymentAccount(int $bookId, int $accountId): void
    {
        $account = Account::query()->where('book_id', $bookId)->find($accountId);

        if ($account === null) {
            return;
        }

        if ($account->system_role !== null) {
            throw new SystemAccountNotSelectable($accountId, $account->system_role);
        }

        if ($account->type !== AccountType::Asset) {
            throw new PaymentAccountTypeMismatch($accountId, $account->type);
        }

        if ($account->archived_at !== null) {
            throw new ArchivedAccountNotSelectable($accountId);
        }

        $book = Book::query()->find($bookId);

        if ($book !== null && $account->native_instrument_id !== $book->functional_instrument_id) {
            throw new PaymentAccountInstrumentMismatch(
                $accountId,
                $account->native_instrument_id,
                $book->functional_instrument_id,
            );
        }
    }
}
