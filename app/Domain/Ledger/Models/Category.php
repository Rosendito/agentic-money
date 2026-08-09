<?php

namespace App\Domain\Ledger\Models;

use Database\Factories\Domain\Ledger\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A book-scoped classification dimension for income and expense postings, flat in this first
 * release (docs/03-ledger-model.md, LED-010; ADR-003). Categories are dimensions, never nominal
 * accounts, and never substitute for a balanced income or expense account.
 */
#[Fillable(['book_id', 'name'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
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
}
