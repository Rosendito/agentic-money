<?php

namespace App\Domain\Ledger\Models;

use Database\Factories\Domain\Ledger\ContainerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user-facing place or custodian that groups accounts, such as Binance, Banco Mercantil, PayPal,
 * or a physical wallet (docs/02-domain-glossary.md, "Container"). Containers improve UX only;
 * postings reference accounts, never a multi-instrument container balance (ACC-002).
 */
#[Fillable(['book_id', 'name'])]
class Container extends Model
{
    /** @use HasFactory<ContainerFactory> */
    use HasFactory;

    protected static function newFactory(): ContainerFactory
    {
        return ContainerFactory::new();
    }

    /**
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
