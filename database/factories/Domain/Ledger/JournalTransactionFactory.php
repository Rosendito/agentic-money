<?php

namespace Database\Factories\Domain\Ledger;

use App\Domain\Ledger\Enums\TransactionStatus;
use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\JournalTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<JournalTransaction>
 */
class JournalTransactionFactory extends Factory
{
    protected $model = JournalTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'status' => TransactionStatus::Draft,
            'effective_at' => now(),
            'description' => fake()->sentence(),
            'idempotency_key' => (string) Str::uuid(),
            'reverses_transaction_id' => null,
            'correction_group_id' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionStatus::Draft,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionStatus::Pending,
        ]);
    }

    public function posted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionStatus::Posted,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionStatus::Cancelled,
        ]);
    }

    /**
     * A posted reversal of an existing transaction, in the same book (LIF-004).
     */
    public function reversalOf(JournalTransaction $original): static
    {
        return $this->state(fn (array $attributes) => [
            'book_id' => $original->book_id,
            'status' => TransactionStatus::Posted,
            'reverses_transaction_id' => $original->id,
        ]);
    }
}
