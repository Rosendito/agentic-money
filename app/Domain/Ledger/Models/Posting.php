<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Actions\PostJournalTransactionAction;
use App\Domain\Ledger\Enums\TransactionStatus;
use App\Domain\Ledger\Exceptions\PostedDataIsImmutable;
use App\Domain\Money\Casts\MonetaryScale;
use Database\Factories\Domain\Ledger\PostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One signed effect on one ledger account, carrying both a native quantity and a functional amount
 * (docs/02-domain-glossary.md, "Posting"; LED-003, LED-004), and an optional book-scoped category
 * dimension applied only to the income or expense posting of a transaction (LED-010, ADR-003).
 *
 * @property int $id
 * @property int $book_id
 * @property int $journal_transaction_id
 * @property int $account_id
 * @property string $native_quantity
 * @property string $functional_amount
 * @property int|null $category_id
 * @property string|null $memo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['book_id', 'journal_transaction_id', 'account_id', 'native_quantity', 'functional_amount', 'category_id', 'memo'])]
class Posting extends Model
{
    /** @use HasFactory<PostingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            // Rejects more than 18 fractional digits at the application boundary instead of
            // relying on the database (ADR-001, 2026-08-08 amendment): PostgreSQL's
            // DECIMAL(38, 18) would otherwise round an over-scale value silently.
            'native_quantity' => MonetaryScale::class,
            'functional_amount' => MonetaryScale::class,
        ];
    }

    protected static function newFactory(): PostingFactory
    {
        return PostingFactory::new();
    }

    /**
     * Guard LIF-003: a posting cannot be created against an already-posted journal transaction, and
     * an existing posting belonging to a posted transaction rejects Eloquent updates and deletes.
     * This is a model-level guard, not a database constraint; the reversal path (TASK-005) is the
     * only sanctioned correction and posts new, separate rows.
     *
     * Enforced in the model's low-level `perform*` pipeline, not through model events
     * (`creating`/`updating`/`deleting`): `saveQuietly()`, `createQuietly()`, and
     * `Model::withoutEvents()` mute the event dispatcher entirely but still call `save()`, which
     * unconditionally calls `performInsert()`/`performUpdate()` (external-review finding 3). A guard
     * living only in an event listener is bypassed by any of those APIs; overriding the pipeline
     * methods directly is not.
     *
     * `performInsert()` does not block the posting kernel's own legitimate writes
     * ({@see PostJournalTransactionAction}): the kernel creates every
     * posting for a transaction while that transaction is still `Draft` and flips it to `Posted`
     * only as its final step, once every posting already exists. `transactionIsPosted()` reads the
     * *currently persisted* status, so it is false throughout that window and true forever after.
     *
     * `performUpdate()`/`performDeleteOnModel()` check **both** the posting's persisted parent
     * (`getOriginal('journal_transaction_id')`) and, on update, its possibly-dirty new target —
     * never only one side. A guard that trusted only the dirty target would let an update that
     * *escapes* a posting from a posted transaction pass, by pointing `journal_transaction_id` at a
     * different, non-posted transaction while the original stays posted and now short a posting
     * (external-review finding 2, the defect this task originally fixed). A guard that trusted only
     * the persisted parent has the opposite hole: an update whose persisted parent is a Draft can
     * reparent the posting *onto* a posted transaction, injecting new content into posted history
     * and unbalancing it (round-2 validation finding — a regression finding 2's own fix introduced,
     * since the previous, event-based `main` guard happened to read the dirty target and rejected
     * this direction, even though it read the *wrong* side for the away direction). Checking both
     * sides closes both directions: a posting can be written only when *neither* its current parent
     * nor its prospective new parent is posted.
     *
     * None of this covers writes that bypass Eloquent's attribute/model pipeline entirely — a bulk
     * `Posting::query()->where(...)->update(...)`/`delete()`, `insert()`/`upsert()`, or raw SQL
     * never instantiate a model and so never reach `performInsert()`/`performUpdate()` (the same
     * class of gap `App\Domain\Money\Casts\MonetaryScale` documents for `DB::table()`/query-builder
     * writes). `LED-015`/`LIF-018` make the posting kernel the only write path in `app/`, so no other
     * application code performs such a write today.
     */
    protected function performInsert(Builder $query): bool
    {
        if (self::transactionIsPosted($this->journal_transaction_id)) {
            throw PostedDataIsImmutable::forPosting();
        }

        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query): bool
    {
        if (self::transactionIsPosted($this->originalParentId()) || self::transactionIsPosted($this->journal_transaction_id)) {
            throw PostedDataIsImmutable::forPosting();
        }

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel(): void
    {
        if (self::transactionIsPosted($this->originalParentId())) {
            throw PostedDataIsImmutable::forPosting();
        }

        parent::performDeleteOnModel();
    }

    /**
     * The posting's persisted `journal_transaction_id`, from before any in-memory mutation on this
     * instance. Falls back to the current value when there is no original (a freshly-loaded,
     * never-dirtied instance has no recorded original for an unmodified attribute).
     */
    private function originalParentId(): int
    {
        return $this->getOriginal('journal_transaction_id') ?? $this->journal_transaction_id;
    }

    private static function transactionIsPosted(int $journalTransactionId): bool
    {
        // Eloquent's Builder::value() fetches a model and reads the attribute, so the enum cast
        // still applies: this returns a TransactionStatus instance, not the raw column string.
        return JournalTransaction::query()
            ->whereKey($journalTransactionId)
            ->value('status') === TransactionStatus::Posted;
    }

    /**
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * @return BelongsTo<JournalTransaction, $this>
     */
    public function journalTransaction(): BelongsTo
    {
        return $this->belongsTo(JournalTransaction::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
