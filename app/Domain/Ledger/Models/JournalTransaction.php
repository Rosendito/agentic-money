<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Actions\PostJournalTransactionAction;
use App\Domain\Ledger\Enums\TransactionStatus;
use App\Domain\Ledger\Exceptions\PostedDataIsImmutable;
use Database\Factories\Domain\Ledger\JournalTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The immutable accounting representation of one economic event (docs/02-domain-glossary.md,
 * "Journal transaction"). Stored statuses are Draft, Pending, Posted, and Cancelled; "Reversed" is
 * a derived condition expressed by a posted transaction referencing the original, never a stored
 * status (docs/07-integrity-and-lifecycle.md, "Transaction states").
 *
 * @property int $id
 * @property int $book_id
 * @property TransactionStatus $status
 * @property Carbon $effective_at
 * @property string|null $description
 * @property string $idempotency_key
 * @property int|null $reverses_transaction_id
 * @property string|null $correction_group_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['book_id', 'status', 'effective_at', 'description', 'idempotency_key', 'reverses_transaction_id', 'correction_group_id'])]
class JournalTransaction extends Model
{
    /** @use HasFactory<JournalTransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TransactionStatus::class,
            'effective_at' => 'datetime',
        ];
    }

    protected static function newFactory(): JournalTransactionFactory
    {
        return JournalTransactionFactory::new();
    }

    /**
     * Guard LIF-003: a posted journal transaction's financial fields reject Eloquent updates and
     * deletes. This is a model-level guard, not a database constraint; the reversal path (TASK-005)
     * is the only sanctioned correction and posts a new, separate transaction rather than mutating
     * this one. Checked against the persisted status, not the in-memory attribute, so an update
     * cannot smuggle a status change past its own guard.
     *
     * This still permits the posting kernel's own `Draft` -> `Posted` transition
     * ({@see PostJournalTransactionAction}), since the currently
     * persisted status at that moment is `Draft`, not `Posted`.
     *
     * This guard does not cover writes that bypass Eloquent's attribute/event pipeline — a bulk
     * `JournalTransaction::query()->where(...)->update(...)`/`delete()`, `insert()`/`upsert()`, or
     * raw SQL fire no model events (the same class of gap `App\Domain\Money\Casts\MonetaryScale`
     * documents for `DB::table()`/query-builder writes). `LED-015`/`LIF-018` make the posting
     * kernel the only write path in `app/`, so no other application code performs such a write
     * today.
     */
    protected static function booted(): void
    {
        static::updating(function (self $transaction): void {
            if (self::isPosted($transaction)) {
                throw PostedDataIsImmutable::forTransaction();
            }
        });

        static::deleting(function (self $transaction): void {
            if (self::isPosted($transaction)) {
                throw PostedDataIsImmutable::forTransaction();
            }
        });
    }

    private static function isPosted(self $transaction): bool
    {
        // Eloquent's Builder::value() fetches a model and reads the attribute, so the enum cast
        // still applies: this returns a TransactionStatus instance, not the raw column string.
        return self::query()->whereKey($transaction->getKey())->value('status') === TransactionStatus::Posted;
    }

    /**
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * @return HasMany<Posting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(Posting::class);
    }

    /**
     * The original transaction this one reverses, when it is itself a reversal.
     *
     * @return BelongsTo<self, $this>
     */
    public function reversesTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    /**
     * Posted transactions that reverse this one. A transaction is "reversed" (a derived condition)
     * when this relation contains a posted reversal.
     *
     * @return HasMany<self, $this>
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_transaction_id');
    }
}
