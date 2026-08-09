<?php

namespace App\Domain\Ledger\Actions;

use App\Domain\Ledger\Enums\SystemAccountRole;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Book;
use App\Domain\Money\Models\Instrument;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The controlled book-initialization service (ACC-007, LIF-017): creates a book with its functional
 * instrument and exactly one system account per {@see SystemAccountRole}, each denominated in the
 * book's functional instrument (docs/06-transaction-scenarios.md, "Shared assumptions"). No posting
 * can happen before a book is bootstrapped, since every intent action selects its system
 * counterpart accounts from here (ACC-008).
 *
 * Idempotent per book identity (user + name): a second call for the same book finds the existing
 * book and only fills in any missing system account, never duplicating either.
 */
class BootstrapBookAction
{
    public function handle(User $user, Instrument $functionalInstrument, string $name): Book
    {
        return DB::transaction(function () use ($user, $functionalInstrument, $name) {
            $book = Book::query()->firstOrCreate(
                ['user_id' => $user->id, 'name' => $name],
                ['functional_instrument_id' => $functionalInstrument->id],
            );

            foreach (SystemAccountRole::cases() as $role) {
                Account::query()->firstOrCreate(
                    ['book_id' => $book->id, 'system_role' => $role],
                    [
                        'name' => $role->value,
                        'type' => $role->accountType(),
                        'native_instrument_id' => $book->functional_instrument_id,
                    ],
                );
            }

            return $book;
        });
    }
}
