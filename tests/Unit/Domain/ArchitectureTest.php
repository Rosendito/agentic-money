<?php

use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\Container;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Ledger\Models\Posting;
use App\Domain\Money\Models\Instrument;

arch('Money has no dependency on Ledger or Obligations')
    ->expect('App\Domain\Money')
    ->not->toUse(['App\Domain\Ledger', 'App\Domain\Obligations']);

test('no monetary attribute uses a float or double cast', function () {
    $models = [Instrument::class, Book::class, Container::class, Account::class, JournalTransaction::class, Posting::class];

    foreach ($models as $model) {
        $casts = (new $model)->getCasts();

        foreach ($casts as $attribute => $cast) {
            expect(str_starts_with($cast, 'float') || str_starts_with($cast, 'double'))
                ->toBeFalse("{$model}::\${$attribute} must not use a float or double cast (ADR-001).");
        }
    }
});
