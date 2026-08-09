<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Enums\TransactionStatus;
use App\Domain\Ledger\Exceptions\FunctionalInstrumentIsImmutable;
use App\Domain\Money\Models\Instrument;
use App\Models\User;
use Database\Factories\Domain\Ledger\BookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An independent accounting ledger with one functional instrument and its own accounts,
 * transactions, posting sequence, and policies (docs/02-domain-glossary.md, "Book").
 */
#[Fillable(['user_id', 'functional_instrument_id', 'name'])]
class Book extends Model
{
    /** @use HasFactory<BookFactory> */
    use HasFactory;

    protected static function newFactory(): BookFactory
    {
        return BookFactory::new();
    }

    /**
     * Guard ADR-002: a book's functional instrument cannot change once the book has a posted
     * journal transaction. This is a domain/application-layer guarantee, not a database constraint.
     */
    protected static function booted(): void
    {
        static::saving(function (self $book): void {
            if (! $book->exists || ! $book->isDirty('functional_instrument_id')) {
                return;
            }

            if ($book->journalTransactions()->where('status', TransactionStatus::Posted)->exists()) {
                throw new FunctionalInstrumentIsImmutable;
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Instrument, $this>
     */
    public function functionalInstrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'functional_instrument_id');
    }

    /**
     * @return HasMany<Container, $this>
     */
    public function containers(): HasMany
    {
        return $this->hasMany(Container::class);
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasMany<JournalTransaction, $this>
     */
    public function journalTransactions(): HasMany
    {
        return $this->hasMany(JournalTransaction::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }
}
