<?php

use App\Domain\Ledger\Exceptions\FunctionalInstrumentIsImmutable;
use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Money\Models\Instrument;

test("a book's functional instrument cannot change once a posted transaction exists", function () {
    $book = Book::factory()->create();
    JournalTransaction::factory()->for($book)->posted()->create();

    $newInstrument = Instrument::factory()->create();

    expect(fn () => $book->update(['functional_instrument_id' => $newInstrument->id]))
        ->toThrow(FunctionalInstrumentIsImmutable::class);

    expect($book->fresh()->functional_instrument_id)->not->toBe($newInstrument->id);
});

test("a book's functional instrument can still change before any posted transaction exists", function () {
    $book = Book::factory()->create();
    JournalTransaction::factory()->for($book)->draft()->create();

    $newInstrument = Instrument::factory()->create();
    $book->update(['functional_instrument_id' => $newInstrument->id]);

    expect($book->fresh()->functional_instrument_id)->toBe($newInstrument->id);
});
