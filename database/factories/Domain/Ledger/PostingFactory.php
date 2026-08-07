<?php

namespace Database\Factories\Domain\Ledger;

use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Ledger\Models\Posting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A posting requires its transaction and account to belong to the same book (LIF-016). The default
 * definition creates a single shared book so a plain `Posting::factory()->create()` respects that
 * constraint out of the box.
 *
 * @extends Factory<Posting>
 */
class PostingFactory extends Factory
{
    protected $model = Posting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'journal_transaction_id' => fn (array $attributes) => JournalTransaction::factory()
                ->for($this->resolveBook($attributes['book_id']), 'book')
                ->create()
                ->id,
            'account_id' => fn (array $attributes) => Account::factory()
                ->for($this->resolveBook($attributes['book_id']), 'book')
                ->create()
                ->id,
            // ADR-001: monetary values are decimal strings, never binary floats. `randomFloat()`
            // would produce a float, losing the exactness the decimal representation exists to
            // guarantee, so the sign, integer part, and fractional part are assembled from integers.
            'native_quantity' => $this->decimalString(),
            'functional_amount' => $this->decimalString(),
            'memo' => null,
        ];
    }

    /**
     * Resolve the book a posting belongs to as a single model instance, never a collection.
     */
    private function resolveBook(int $bookId): Book
    {
        return Book::query()->whereKey($bookId)->firstOrFail();
    }

    /**
     * Build a random decimal string without ever producing a binary float (ADR-001).
     */
    private function decimalString(): string
    {
        $sign = fake()->boolean() ? '-' : '';
        $integer = fake()->numberBetween(0, 100000);
        $fraction = str_pad((string) fake()->numberBetween(0, 99), 2, '0', STR_PAD_LEFT);

        return "{$sign}{$integer}.{$fraction}";
    }
}
