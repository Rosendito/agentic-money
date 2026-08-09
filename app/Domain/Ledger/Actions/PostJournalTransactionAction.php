<?php

namespace App\Domain\Ledger\Actions;

use App\Domain\Ledger\Data\PostingInput;
use App\Domain\Ledger\Data\PostJournalTransactionCommand;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\TransactionStatus;
use App\Domain\Ledger\Events\JournalTransactionPosted;
use App\Domain\Ledger\Exceptions\CategoryNotAllowedOnAccountType;
use App\Domain\Ledger\Exceptions\CrossBookReference;
use App\Domain\Ledger\Exceptions\IdempotencyConflict;
use App\Domain\Ledger\Exceptions\InsufficientNativeBalance;
use App\Domain\Ledger\Exceptions\InsufficientPostings;
use App\Domain\Ledger\Exceptions\JournalTransactionIsUnbalanced;
use App\Domain\Ledger\Exceptions\PostingSignMismatch;
use App\Domain\Ledger\Exceptions\ZeroPostingIsNotAllowed;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Category;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Ledger\Models\Posting;
use App\Domain\Money\ValueObjects\MonetaryDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single posting kernel (LED-015, LIF-018): the only code path that writes journal transactions
 * and postings. Every intent action — book bootstrap, opening balance, income, expense, and every
 * future one — validates its own intent, selects the required system accounts (ACC-008), and calls
 * this action instead of touching the `journal_transactions` or `postings` tables itself.
 *
 * This slice posts directly; draft and pending workflows are out of scope, so every transaction this
 * kernel creates is immediately `Posted`.
 */
class PostJournalTransactionAction
{
    public function handle(PostJournalTransactionCommand $command): JournalTransaction
    {
        $this->validate($command);

        $existing = $this->findExisting($command->bookId, $command->idempotencyKey);

        if ($existing !== null) {
            return $this->resolveIdempotentDuplicate($existing, $command);
        }

        try {
            $transaction = DB::transaction(fn () => $this->createTransaction($command));
        } catch (QueryException $exception) {
            if (! $this->isIdempotencyKeyViolation($exception)) {
                throw $exception;
            }

            // LIF-009: concurrent duplicates are settled by the existing UNIQUE(book_id,
            // idempotency_key) constraint. The transaction above has already rolled back by the
            // time DB::transaction() rethrows, so the connection is usable again here.
            $existing = $this->findExisting($command->bookId, $command->idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            return $this->resolveIdempotentDuplicate($existing, $command);
        }

        // ARC-013: dispatched only after this method's transaction commits successfully.
        // ShouldDispatchAfterCommit on the event class makes Laravel hold and discard it on
        // rollback; no listener exists yet, and no invariant may ever depend on one (ARC-012).
        JournalTransactionPosted::dispatch($transaction);

        return $transaction;
    }

    /**
     * Aggregate-rule validation (LED-001, LED-005, LED-008, LIF-011, LIF-016). Runs before any write
     * so a rejected command leaves no partial state (LIF-002).
     */
    private function validate(PostJournalTransactionCommand $command): void
    {
        if (count($command->postings) < 2) {
            throw new InsufficientPostings(count($command->postings));
        }

        $functionalAmounts = [];

        foreach ($command->postings as $posting) {
            $native = MonetaryDecimal::fromString($posting->nativeQuantity);
            $functional = MonetaryDecimal::fromString($posting->functionalAmount);

            if ($native->isZero() && $functional->isZero()) {
                throw new ZeroPostingIsNotAllowed;
            }

            if (! $native->isZero() && ! $functional->isZero() && $native->isNegative() !== $functional->isNegative()) {
                throw new PostingSignMismatch((string) $native, (string) $functional);
            }

            $functionalAmounts[] = $functional;
        }

        $sum = MonetaryDecimal::sum($functionalAmounts);

        if (! $sum->isZero()) {
            throw new JournalTransactionIsUnbalanced((string) $sum);
        }

        $this->assertSameBook($command);
    }

    private function assertSameBook(PostJournalTransactionCommand $command): void
    {
        $accountIds = collect($command->postings)->pluck('accountId')->unique()->values();
        $accountsInBook = Account::query()
            ->where('book_id', $command->bookId)
            ->whereIn('id', $accountIds)
            ->get(['id', 'type'])
            ->keyBy('id');

        foreach ($accountIds as $accountId) {
            if (! $accountsInBook->has($accountId)) {
                throw CrossBookReference::forAccount($accountId, $command->bookId);
            }
        }

        $categoryIds = collect($command->postings)->pluck('categoryId')->filter()->unique()->values();
        $categoriesInBook = Category::query()
            ->where('book_id', $command->bookId)
            ->whereIn('id', $categoryIds)
            ->pluck('id');

        foreach ($categoryIds as $categoryId) {
            if (! $categoriesInBook->contains($categoryId)) {
                throw CrossBookReference::forCategory($categoryId, $command->bookId);
            }
        }

        // LED-010/ADR-003: category is a dimension of the income or expense posting only, enforced
        // here at the single posting boundary so the rule holds for every future caller, not only
        // the intent actions that happen to respect it today.
        foreach ($command->postings as $posting) {
            if ($posting->categoryId === null) {
                continue;
            }

            $account = $accountsInBook->get($posting->accountId);

            if ($account !== null && ! in_array($account->type, [AccountType::Income, AccountType::Expense], true)) {
                throw new CategoryNotAllowedOnAccountType($posting->accountId, $account->type);
            }
        }
    }

    private function createTransaction(PostJournalTransactionCommand $command): JournalTransaction
    {
        // ACC-006 (external-review finding 4): lock every posting account row for the duration of
        // this database transaction, then re-derive and verify any account the caller flagged as
        // native-balance-sensitive, before creating anything. Locking happens first and covers every
        // posting account (not only the guarded ones) so two commands touching the same accounts in
        // different orders cannot deadlock each other.
        $this->lockPostingAccounts($command);
        $this->assertNonNegativeBalances($command);

        // Created as Draft, not Posted: Posting::creating rejects attaching a row to an
        // already-posted transaction (LIF-003), so every posting for this transaction must exist
        // before it is marked Posted. The transition below is the one legitimate Draft -> Posted
        // update the JournalTransaction guard allows, since its currently persisted status is still
        // Draft at that point.
        $transaction = JournalTransaction::create([
            'book_id' => $command->bookId,
            'status' => TransactionStatus::Draft,
            // LIF-013/external-review finding 5: normalized to UTC before persistence. The
            // `effective_at` column has no timezone of its own (Eloquent's `datetime` cast formats
            // the given instant's *current* timezone as a naive string, it does not convert), and
            // the application timezone is UTC (config('app.timezone')), so a caller-supplied
            // non-UTC instant (e.g. America/Caracas wall time) would otherwise persist its
            // non-UTC wall-clock digits, then rehydrate as if they were already UTC — silently
            // shifting the stored instant and, since the idempotency canonical payload also
            // normalizes to UTC, making an identical replay disagree with what was actually stored.
            'effective_at' => $command->effectiveAt->clone()->utc(),
            'description' => $command->description,
            'idempotency_key' => $command->idempotencyKey,
        ]);

        foreach ($command->postings as $posting) {
            Posting::create([
                'book_id' => $command->bookId,
                'journal_transaction_id' => $transaction->id,
                'account_id' => $posting->accountId,
                'native_quantity' => $posting->nativeQuantity,
                'functional_amount' => $posting->functionalAmount,
                'category_id' => $posting->categoryId,
                'memo' => $posting->memo,
            ]);
        }

        $transaction->update(['status' => TransactionStatus::Posted]);

        return $transaction->load('postings');
    }

    /**
     * Locks every account this command posts to, in a stable ascending-id order (so two commands
     * sharing more than one account can never deadlock each other by locking in different orders).
     * Must run inside the same database transaction as the posting writes below and before any of
     * them, so a concurrent command touching the same account genuinely waits on this one to commit
     * or roll back before it can read or write anything (ACC-006, external-review finding 4).
     *
     * The ascending order has to be an actual `ORDER BY` in the query, not merely a PHP-side sort of
     * the id list passed to `whereIn()`: the database chooses its own scan/lock-acquisition order
     * for an unordered `IN (...)` (round-2 validation finding), so sorting only the bind parameters
     * made no real guarantee. `orderBy('id')` makes the row locks actually get taken in that order.
     */
    private function lockPostingAccounts(PostJournalTransactionCommand $command): void
    {
        $accountIds = collect($command->postings)->pluck('accountId')->unique()->sort()->values();

        if ($accountIds->isEmpty()) {
            return;
        }

        Account::query()->whereIn('id', $accountIds)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /**
     * For every account the caller flagged as native-balance-sensitive
     * ({@see PostJournalTransactionCommand::$accountsRequiringNonNegativeBalance}), re-derives the
     * posted native balance and adds this command's own net native movement for that account, under
     * the lock {@see lockPostingAccounts()} already took. Reading the balance here — inside the
     * locked transaction, immediately before writing — instead of before the transaction opens is
     * what closes the check-then-post race: a second, concurrent command against the same account
     * cannot begin its own read until this one has committed or rolled back.
     */
    private function assertNonNegativeBalances(PostJournalTransactionCommand $command): void
    {
        foreach ($command->accountsRequiringNonNegativeBalance as $accountId) {
            $account = Account::query()->find($accountId);

            if ($account === null) {
                continue;
            }

            $available = MonetaryDecimal::fromString($account->postedNativeBalance());

            $netMovement = MonetaryDecimal::sum(
                collect($command->postings)
                    ->filter(fn (PostingInput $posting): bool => $posting->accountId === $accountId)
                    ->map(fn (PostingInput $posting): string => $posting->nativeQuantity)
                    ->values()
                    ->all()
            );

            $projected = MonetaryDecimal::sum([$available, $netMovement]);

            if ($projected->isNegative()) {
                throw new InsufficientNativeBalance($accountId, (string) $available, (string) $netMovement->negated());
            }
        }
    }

    private function findExisting(int $bookId, string $idempotencyKey): ?JournalTransaction
    {
        return JournalTransaction::query()
            ->where('book_id', $bookId)
            ->where('idempotency_key', $idempotencyKey)
            ->with('postings')
            ->first();
    }

    /**
     * LIF-009: same key, same canonical payload returns the existing transaction; same key, a
     * materially different payload is a conflict.
     */
    private function resolveIdempotentDuplicate(JournalTransaction $existing, PostJournalTransactionCommand $command): JournalTransaction
    {
        if ($this->canonicalPayload($command) !== $this->canonicalPayloadFromExisting($existing)) {
            throw new IdempotencyConflict($command->idempotencyKey);
        }

        return $existing;
    }

    /**
     * @return array{effective_at: string, description: ?string, postings: array<int, array<string, mixed>>}
     */
    private function canonicalPayload(PostJournalTransactionCommand $command): array
    {
        $postings = collect($command->postings)
            ->map(fn (PostingInput $posting): array => [
                'account_id' => $posting->accountId,
                'native_quantity' => (string) MonetaryDecimal::fromString($posting->nativeQuantity),
                'functional_amount' => (string) MonetaryDecimal::fromString($posting->functionalAmount),
                'category_id' => $posting->categoryId,
                'memo' => $posting->memo,
            ])
            ->sortBy(fn (array $posting): string => $this->postingSortKey($posting))
            ->values()
            ->all();

        return [
            'effective_at' => $command->effectiveAt->clone()->utc()->toIso8601String(),
            'description' => $command->description,
            'postings' => $postings,
        ];
    }

    /**
     * @return array{effective_at: string, description: ?string, postings: array<int, array<string, mixed>>}
     */
    private function canonicalPayloadFromExisting(JournalTransaction $transaction): array
    {
        $postings = $transaction->postings
            ->map(fn (Posting $posting): array => [
                'account_id' => $posting->account_id,
                'native_quantity' => (string) MonetaryDecimal::fromString($posting->native_quantity),
                'functional_amount' => (string) MonetaryDecimal::fromString($posting->functional_amount),
                'category_id' => $posting->category_id,
                'memo' => $posting->memo,
            ])
            ->sortBy(fn (array $posting): string => $this->postingSortKey($posting))
            ->values()
            ->all();

        return [
            'effective_at' => $transaction->effective_at->clone()->utc()->toIso8601String(),
            'description' => $transaction->description,
            'postings' => $postings,
        ];
    }

    /**
     * A total order over every field that distinguishes one posting from another, not just
     * account and category. Two postings sharing an (account_id, category_id) pair but differing
     * in amount (or memo) previously sorted by their original position, so replaying the same
     * command with such postings reordered produced a different canonical payload and a false
     * `IdempotencyConflict` (validation finding 3). Postings that are identical in every one of
     * these fields are truly interchangeable, so any relative order between them is safe.
     *
     * @param  array<string, mixed>  $posting
     */
    private function postingSortKey(array $posting): string
    {
        return (string) json_encode([
            $posting['account_id'],
            $posting['category_id'] ?? null,
            $posting['native_quantity'],
            $posting['functional_amount'],
            $posting['memo'] ?? null,
        ]);
    }

    private function isIdempotencyKeyViolation(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true)
            && str_contains($exception->getMessage(), 'idempotency_key');
    }
}
