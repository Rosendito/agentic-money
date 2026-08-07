<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Enums\TransactionStatus;
use Database\Factories\Domain\Ledger\JournalTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The immutable accounting representation of one economic event (docs/02-domain-glossary.md,
 * "Journal transaction"). Stored statuses are Draft, Pending, Posted, and Cancelled; "Reversed" is
 * a derived condition expressed by a posted transaction referencing the original, never a stored
 * status (docs/07-integrity-and-lifecycle.md, "Transaction states").
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

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function postings(): HasMany
    {
        return $this->hasMany(Posting::class);
    }

    /**
     * The original transaction this one reverses, when it is itself a reversal.
     */
    public function reversesTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    /**
     * Posted transactions that reverse this one. A transaction is "reversed" (a derived condition)
     * when this relation contains a posted reversal.
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_transaction_id');
    }
}
