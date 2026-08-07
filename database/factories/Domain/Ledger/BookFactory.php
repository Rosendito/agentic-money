<?php

namespace Database\Factories\Domain\Ledger;

use App\Domain\Ledger\Models\Book;
use App\Domain\Money\Models\Instrument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Book>
 */
class BookFactory extends Factory
{
    protected $model = Book::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'functional_instrument_id' => Instrument::factory(),
            // Faker's `words()` is declared `array|string` regardless of `$asText`, so the result is
            // narrowed with a type guard instead of concatenated directly.
            'name' => $this->twoWords().' book',
        ];
    }

    /**
     * Two random words as a string, narrowing Faker's `array|string` return type.
     */
    private function twoWords(): string
    {
        $words = fake()->words(2, true);

        return is_string($words) ? $words : implode(' ', $words);
    }
}
