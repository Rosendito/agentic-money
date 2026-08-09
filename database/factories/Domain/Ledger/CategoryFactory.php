<?php

namespace Database\Factories\Domain\Ledger;

use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'name' => fake()->unique()->word(),
        ];
    }
}
