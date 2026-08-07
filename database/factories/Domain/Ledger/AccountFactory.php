<?php

namespace Database\Factories\Domain\Ledger;

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\SystemAccountRole;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Book;
use App\Domain\Money\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'container_id' => null,
            'name' => fake()->words(2, true),
            'type' => AccountType::Asset,
            'native_instrument_id' => Instrument::factory(),
            'system_role' => null,
            'archived_at' => null,
        ];
    }

    public function type(AccountType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
        ]);
    }

    /**
     * A system-managed nominal or equity account (docs/03-ledger-model.md, "System-managed nominal
     * and equity accounts").
     */
    public function system(SystemAccountRole $role = SystemAccountRole::ExpenseControl): static
    {
        return $this->state(fn (array $attributes) => [
            'system_role' => $role,
        ]);
    }

    /**
     * An account archived after being retired from active use (ACC-005).
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }
}
