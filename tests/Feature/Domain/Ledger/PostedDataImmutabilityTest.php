<?php

use App\Domain\Ledger\Enums\TransactionStatus;
use App\Domain\Ledger\Exceptions\PostedDataIsImmutable;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Ledger\Models\Posting;

/**
 * A posting cannot be created against an already-posted transaction (validation finding 2), so
 * every test here that needs an existing posting on a posted transaction must create the posting
 * first, while the transaction is still Draft, and only then flip the transaction to Posted — the
 * same sequence the posting kernel itself follows.
 */
function postedTransactionWithExistingPosting(): array
{
    $transaction = JournalTransaction::factory()->draft()->create();
    $posting = Posting::factory()->create([
        'book_id' => $transaction->book_id,
        'journal_transaction_id' => $transaction->id,
    ]);
    $transaction->update(['status' => TransactionStatus::Posted]);

    return [$transaction, $posting];
}

test('a posted journal transaction rejects an Eloquent update', function () {
    $transaction = JournalTransaction::factory()->posted()->create();

    expect(fn () => $transaction->update(['description' => 'changed']))
        ->toThrow(PostedDataIsImmutable::class);

    expect($transaction->fresh()->description)->not->toBe('changed');
});

test('a posted journal transaction rejects an Eloquent delete', function () {
    $transaction = JournalTransaction::factory()->posted()->create();

    expect(fn () => $transaction->delete())->toThrow(PostedDataIsImmutable::class);

    expect(JournalTransaction::query()->whereKey($transaction->id)->exists())->toBeTrue();
});

test('a draft journal transaction accepts an Eloquent update', function () {
    $transaction = JournalTransaction::factory()->draft()->create();

    $transaction->update(['description' => 'still editable']);

    expect($transaction->fresh()->description)->toBe('still editable');
});

test('a draft transaction can transition to posted through an Eloquent update', function () {
    $transaction = JournalTransaction::factory()->draft()->create();

    $transaction->update(['status' => TransactionStatus::Posted]);

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Posted);
});

test('a posting cannot be created against an already-posted transaction', function () {
    $transaction = JournalTransaction::factory()->posted()->create();

    expect(fn () => Posting::factory()->create([
        'book_id' => $transaction->book_id,
        'journal_transaction_id' => $transaction->id,
    ]))->toThrow(PostedDataIsImmutable::class);

    expect(Posting::query()->where('journal_transaction_id', $transaction->id)->count())->toBe(0);
});

test('a posting can be created against a draft transaction', function () {
    $transaction = JournalTransaction::factory()->draft()->create();

    $posting = Posting::factory()->create([
        'book_id' => $transaction->book_id,
        'journal_transaction_id' => $transaction->id,
    ]);

    expect($posting->exists)->toBeTrue();
});

test('a posting belonging to a posted transaction rejects an Eloquent update', function () {
    [, $posting] = postedTransactionWithExistingPosting();

    expect(fn () => $posting->update(['memo' => 'changed']))
        ->toThrow(PostedDataIsImmutable::class);

    expect($posting->fresh()->memo)->not->toBe('changed');
});

test('a posting belonging to a posted transaction rejects an Eloquent delete', function () {
    [, $posting] = postedTransactionWithExistingPosting();

    expect(fn () => $posting->delete())->toThrow(PostedDataIsImmutable::class);

    expect(Posting::query()->whereKey($posting->id)->exists())->toBeTrue();
});

test('a posting belonging to a draft transaction accepts an Eloquent update', function () {
    $transaction = JournalTransaction::factory()->draft()->create();
    $posting = Posting::factory()->create([
        'book_id' => $transaction->book_id,
        'journal_transaction_id' => $transaction->id,
    ]);

    $posting->update(['memo' => 'still editable']);

    expect($posting->fresh()->memo)->toBe('still editable');
});

test('the posted status factory state produces the expected status', function () {
    expect(JournalTransaction::factory()->posted()->create()->status)->toBe(TransactionStatus::Posted);
});
