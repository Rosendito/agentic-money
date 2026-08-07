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
            'name' => fake()->words(2, true).' book',
        ];
    }
}
