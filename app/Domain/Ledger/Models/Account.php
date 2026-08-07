<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\SystemAccountRole;
use App\Domain\Ledger\Exceptions\AccountAttributeIsImmutable;
use App\Domain\Money\Models\Instrument;
use Database\Factories\Domain\Ledger\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ledger position with one account type and one immutable native instrument
 * (docs/02-domain-glossary.md, "Account").
 */
#[Fillable(['book_id', 'container_id', 'name', 'type', 'native_instrument_id', 'system_role', 'archived_at'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'system_role' => SystemAccountRole::class,
            'archived_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AccountFactory
    {
        return AccountFactory::new();
    }

    /**
     * Guard ACC-003 (native instrument is immutable) and ACC-004 (account type is stable) once the
     * account has a posting. This is a model-level guard, not a database constraint.
     */
    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            if (! $account->exists) {
                return;
            }

            if ($account->isDirty('native_instrument_id') && $account->postings()->exists()) {
                throw new AccountAttributeIsImmutable('native instrument');
            }

            if ($account->isDirty('type') && $account->postings()->exists()) {
                throw new AccountAttributeIsImmutable('type');
            }
        });
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    public function nativeInstrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'native_instrument_id');
    }

    public function postings(): HasMany
    {
        return $this->hasMany(Posting::class);
    }
}
