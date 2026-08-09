<?php

namespace Database\Factories\Domain\Ledger;

use App\Domain\Ledger\Enums\TransactionStatus;
use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Ledger\Models\Posting;
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

    /**
     * A posted transaction with two balanced postings already attached (LED-001), created the same
     * way the posting kernel itself does: as a Draft, with its postings attached while still Draft,
     * then flipped to Posted as the final step. A direct `['status' => Posted]` insert, or a flip to
     * Posted with fewer than two postings or a nonzero functional sum, is rejected by
     * {@see JournalTransaction}'s own guard (external-review finding 1) —
     * this factory state produces a genuinely postable transaction instead of trying to bypass that
     * guard.
     */
    public function posted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionStatus::Draft,
        ])->afterCreating(function (JournalTransaction $transaction) {
            Posting::factory()->create([
                'book_id' => $transaction->book_id,
                'journal_transaction_id' => $transaction->id,
                'native_quantity' => '10.000000000000000000',
                'functional_amount' => '10.000000000000000000',
            ]);

            Posting::factory()->create([
                'book_id' => $transaction->book_id,
                'journal_transaction_id' => $transaction->id,
                'native_quantity' => '-10.000000000000000000',
                'functional_amount' => '-10.000000000000000000',
            ]);

            $transaction->update(['status' => TransactionStatus::Posted]);
        });
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionStatus::Cancelled,
        ]);
    }

    /**
     * A posted reversal of an existing transaction, in the same book (LIF-004), built the same
     * Draft-then-flip way {@see posted()} is, since a direct `['status' => Posted]` insert is
     * rejected unconditionally (external-review finding 1).
     */
    public function reversalOf(JournalTransaction $original): static
    {
        return $this->state(fn (array $attributes) => [
            'book_id' => $original->book_id,
            'status' => TransactionStatus::Draft,
            'reverses_transaction_id' => $original->id,
        ])->afterCreating(function (JournalTransaction $transaction) {
            Posting::factory()->create([
                'book_id' => $transaction->book_id,
                'journal_transaction_id' => $transaction->id,
                'native_quantity' => '10.000000000000000000',
                'functional_amount' => '10.000000000000000000',
            ]);

            Posting::factory()->create([
                'book_id' => $transaction->book_id,
                'journal_transaction_id' => $transaction->id,
                'native_quantity' => '-10.000000000000000000',
                'functional_amount' => '-10.000000000000000000',
            ]);

            $transaction->update(['status' => TransactionStatus::Posted]);
        });
    }
}
