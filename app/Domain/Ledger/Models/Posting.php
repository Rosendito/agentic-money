<?php

namespace App\Domain\Ledger\Models;

use Database\Factories\Domain\Ledger\PostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signed effect on one ledger account, carrying both a native quantity and a functional amount
 * (docs/02-domain-glossary.md, "Posting"; LED-003, LED-004).
 */
#[Fillable(['book_id', 'journal_transaction_id', 'account_id', 'native_quantity', 'functional_amount', 'memo'])]
class Posting extends Model
{
    /** @use HasFactory<PostingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'native_quantity' => 'decimal:18',
            'functional_amount' => 'decimal:18',
        ];
    }

    protected static function newFactory(): PostingFactory
    {
        return PostingFactory::new();
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
}
