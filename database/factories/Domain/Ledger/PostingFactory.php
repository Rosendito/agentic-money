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
                ->for(Book::findOrFail($attributes['book_id']), 'book')
                ->create()
                ->id,
            'account_id' => fn (array $attributes) => Account::factory()
                ->for(Book::findOrFail($attributes['book_id']), 'book')
                ->create()
                ->id,
            'native_quantity' => fake()->randomFloat(2, -1000, 1000),
            'functional_amount' => fake()->randomFloat(2, -1000, 1000),
            'memo' => null,
        ];
    }
}
