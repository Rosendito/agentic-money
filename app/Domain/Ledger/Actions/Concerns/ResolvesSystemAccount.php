<?php

namespace App\Domain\Ledger\Actions\Concerns;

use App\Domain\Ledger\Enums\SystemAccountRole;
use App\Domain\Ledger\Models\Account;

/**
 * Shared lookup for the book-scoped system counterpart accounts every intent action selects
 * (ACC-008). Relies on the book bootstrap's one-account-per-role guarantee (ACC-007, LIF-017).
 */
trait ResolvesSystemAccount
{
    private function systemAccount(int $bookId, SystemAccountRole $role): Account
    {
        return Account::query()
            ->where('book_id', $bookId)
            ->where('system_role', $role)
            ->firstOrFail();
    }
}
