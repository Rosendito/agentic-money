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
            ['code' => 'USDC', 'name' => 'USD Coin'],
            ['code' => 'VES', 'name' => 'Venezuelan Bolívar'],
            ['code' => 'EUR', 'name' => 'Euro'],
            // ADR-005: physical cash USD and bank-held (BCV-rate) USD are distinct, non-fungible
            // instruments, not one merged "USD". The seeder has never run against real data, so the
            // former single 'USD' row is replaced in place rather than kept alongside these.
            ['code' => 'USD.CASH', 'name' => 'US Dollar (cash)'],
            ['code' => 'USD.BCV', 'name' => 'US Dollar (BCV official rate)'],
        ];

        foreach ($instruments as $instrument) {
            Instrument::query()->updateOrCreate(
                ['code' => $instrument['code']],
                ['name' => $instrument['name']],
            );
        }
    }
}
