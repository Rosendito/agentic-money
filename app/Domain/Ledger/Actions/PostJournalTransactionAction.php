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
        // Created as Draft, not Posted: Posting::creating rejects attaching a row to an
        // already-posted transaction (LIF-003), so every posting for this transaction must exist
        // before it is marked Posted. The transition below is the one legitimate Draft -> Posted
        // update the JournalTransaction guard allows, since its currently persisted status is still
        // Draft at that point.
        $transaction = JournalTransaction::create([
            'book_id' => $command->bookId,
            'status' => TransactionStatus::Draft,
            'effective_at' => $command->effectiveAt,
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
