<?php

use App\Domain\Money\Models\Instrument;
use Database\Seeders\InstrumentSeeder;

test('the seeded instruments are USDT, USDC, VES, EUR, USD.CASH, and USD.BCV', function () {
    (new InstrumentSeeder)->run();

    expect(Instrument::query()->pluck('code')->sort()->values()->all())
        ->toBe(['EUR', 'USD.BCV', 'USD.CASH', 'USDC', 'USDT', 'VES']);
});

test('the seeder does not create a merged USD instrument', function () {
    (new InstrumentSeeder)->run();

    expect(Instrument::query()->where('code', 'USD')->exists())->toBeFalse();
});
