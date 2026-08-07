<?php

namespace Database\Seeders;

use App\Domain\Money\Models\Instrument;
use Illuminate\Database\Seeder;

/**
 * Seeds the minimum instrument reference data every book needs to exist, including the initial
 * personal book's functional instrument, USDT (docs/README.md, "Locked direction").
 */
class InstrumentSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $instruments = [
            ['code' => 'USDT', 'name' => 'Tether'],
            ['code' => 'USD', 'name' => 'United States Dollar'],
            ['code' => 'VES', 'name' => 'Venezuelan Bolívar'],
            ['code' => 'USDC', 'name' => 'USD Coin'],
            ['code' => 'EUR', 'name' => 'Euro'],
        ];

        foreach ($instruments as $instrument) {
            Instrument::query()->updateOrCreate(
                ['code' => $instrument['code']],
                ['name' => $instrument['name']],
            );
        }
    }
}
