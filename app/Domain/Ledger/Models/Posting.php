<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Actions\PostJournalTransactionAction;
use App\Domain\Ledger\Enums\TransactionStatus;
use App\Domain\Ledger\Exceptions\PostedDataIsImmutable;
use App\Domain\Money\Casts\MonetaryScale;
use Database\Factories\Domain\Ledger\PostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
     * The `creating` guard does not block the posting kernel's own legitimate writes
     * ({@see PostJournalTransactionAction}): the kernel creates every
     * posting for a transaction while that transaction is still `Draft` and flips it to `Posted`
     * only as its final step, once every posting already exists. `isPosted()` reads the
     * *currently persisted* status, so it is false throughout that window and true forever after.
     *
     * These guards do not cover writes that bypass Eloquent's attribute/event pipeline — a bulk
     * `Posting::query()->where(...)->update(...)`/`delete()`, `insert()`/`upsert()`, or raw SQL
     * fire no model events (the same class of gap `App\Domain\Money\Casts\MonetaryScale` documents
     * for `DB::table()`/query-builder writes). `LED-015`/`LIF-018` make the posting kernel the only
     * write path in `app/`, so no other application code performs such a write today.
     */
    protected static function booted(): void
    {
        static::creating(function (self $posting): void {
            if (self::transactionIsPosted($posting)) {
                throw PostedDataIsImmutable::forPosting();
            }
        });

        static::updating(function (self $posting): void {
            if (self::transactionIsPosted($posting)) {
                throw PostedDataIsImmutable::forPosting();
            }
        });

        static::deleting(function (self $posting): void {
            if (self::transactionIsPosted($posting)) {
                throw PostedDataIsImmutable::forPosting();
            }
        });
    }

    private static function transactionIsPosted(self $posting): bool
    {
        // Eloquent's Builder::value() fetches a model and reads the attribute, so the enum cast
        // still applies: this returns a TransactionStatus instance, not the raw column string.
        return JournalTransaction::query()
            ->whereKey($posting->journal_transaction_id)
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
