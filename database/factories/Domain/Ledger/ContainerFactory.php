<?php

namespace Database\Factories\Domain\Ledger;

use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\Container;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Container>
 */
class ContainerFactory extends Factory
{
    protected $model = Container::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'name' => fake()->company(),
        ];
    }
}
