<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Actions\PostJournalTransactionAction;
use App\Domain\Ledger\Enums\TransactionStatus;
use App\Domain\Ledger\Exceptions\InsufficientPostings;
use App\Domain\Ledger\Exceptions\JournalTransactionIsUnbalanced;
use App\Domain\Ledger\Exceptions\PostedDataIsImmutable;
use App\Domain\Money\ValueObjects\MonetaryDecimal;
use Database\Factories\Domain\Ledger\JournalTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
     * Guard LIF-003 (posted financial data is immutable) and, on the transition into `Posted`
     * itself, LED-001 (a posted transaction has at least two postings summing to exactly zero).
     *
     * These are enforced in the model's low-level `perform*` pipeline, not through model events
     * (`saving`/`updating`/`deleting`): `saveQuietly()`, `createQuietly()`, and
     * `Model::withoutEvents()` mute the event dispatcher entirely but still call `save()`/`delete()`,
     * which unconditionally call `performInsert()`/`performUpdate()`/`performDeleteOnModel()`
     * (external-review finding 3). A guard living only in an event listener is bypassed by any of
     * those APIs; overriding the pipeline methods directly is not.
     *
     * `performUpdate()` reads the *persisted* status straight from the database rather than trusting
     * `$this->getOriginal('status')` (which reflects whatever this in-memory instance last loaded,
     * not necessarily what is currently stored), so a stale in-memory copy cannot be used to smuggle
     * an update past the guard. This still permits the posting kernel's own `Draft` -> `Posted`
     * transition ({@see PostJournalTransactionAction}): the persisted status at that moment is
     * `Draft`, so the immutability branch does not fire, and the transition itself is validated by
     * {@see assertPostable()} against this transaction's already-persisted postings (created by the
     * kernel, while still `Draft`, before this update runs) — closing the gap where a plain
     * `$transaction->update(['status' => Posted])` on an empty or unbalanced Draft used to succeed
     * (external-review finding 1).
     *
     * `performInsert()` rejects creating a row as `Posted` directly: no posting could possibly exist
     * yet for a transaction that does not have an id, so a direct `Posted` insert can never satisfy
     * LED-001 and is refused unconditionally, the same way the kernel's own two-step Draft-then-flip
     * sequence requires.
     *
     * None of this covers writes that bypass Eloquent's attribute/model pipeline entirely — a bulk
     * `JournalTransaction::query()->where(...)->update(...)`/`delete()`, `insert()`/`upsert()`, or
     * raw SQL never instantiate a model and so never reach `performUpdate()` and friends (the same
     * class of gap `App\Domain\Money\Casts\MonetaryScale` documents for `DB::table()`/query-builder
     * writes). `LED-015`/`LIF-018` make the posting kernel the only write path in `app/`, so no other
     * application code performs such a write today.
     */
    protected function performInsert(Builder $query): bool
    {
        if ($this->status === TransactionStatus::Posted) {
            throw new InsufficientPostings(0);
        }

        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query): bool
    {
        if (self::persistedStatus($this->getKey()) === TransactionStatus::Posted) {
            throw PostedDataIsImmutable::forTransaction();
        }

        if ($this->isDirty('status') && $this->status === TransactionStatus::Posted) {
            $this->assertPostable();
        }

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel(): void
    {
        if (self::persistedStatus($this->getKey()) === TransactionStatus::Posted) {
            throw PostedDataIsImmutable::forTransaction();
        }

        parent::performDeleteOnModel();
    }

    /**
     * LED-001, enforced at the moment a transaction transitions into `Posted` (validation finding
     * 1): at least two persisted postings, whose functional amounts sum to exactly zero at scale 18
     * (LIF-011). Reads postings directly from the database rather than a possibly stale in-memory
     * relation, since the kernel's own postings were created immediately before this call in the
     * same database transaction.
     */
    private function assertPostable(): void
    {
        $functionalAmounts = Posting::query()
            ->where('journal_transaction_id', $this->getKey())
            ->pluck('functional_amount');

        if (count($functionalAmounts) < 2) {
            throw new InsufficientPostings(count($functionalAmounts));
        }

        $sum = MonetaryDecimal::sum($functionalAmounts);

        if (! $sum->isZero()) {
            throw new JournalTransactionIsUnbalanced((string) $sum);
        }
    }

    /**
     * The currently persisted status for a transaction id, read straight from the database.
     * Eloquent's `Builder::value()` fetches a model and reads the attribute, so the enum cast still
     * applies: this returns a `TransactionStatus` instance, not the raw column string.
     */
    private static function persistedStatus(int|string $id): ?TransactionStatus
    {
        return self::query()->whereKey($id)->value('status');
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
