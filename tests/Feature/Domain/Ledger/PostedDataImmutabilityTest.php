<?php

use App\Domain\Ledger\Enums\TransactionStatus;
use App\Domain\Ledger\Exceptions\InsufficientPostings;
use App\Domain\Ledger\Exceptions\JournalTransactionIsUnbalanced;
use App\Domain\Ledger\Exceptions\PostedDataIsImmutable;
use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Ledger\Models\Posting;
use App\Domain\Money\ValueObjects\MonetaryDecimal;
use Illuminate\Support\Str;

/**
 * A posting cannot be created against an already-posted transaction (validation finding 2), so
 * every test here that needs an existing posting on a posted transaction must create the posting
 * first, while the transaction is still Draft, and only then flip the transaction to Posted — the
 * same sequence the posting kernel itself follows. The transition itself now also validates
 * LED-001 (external-review finding 1), so a second, balancing posting is required for the flip to
 * Posted to succeed.
 */
function postedTransactionWithExistingPosting(): array
{
    $transaction = JournalTransaction::factory()->draft()->create();
    $posting = Posting::factory()->create([
        'book_id' => $transaction->book_id,
        'journal_transaction_id' => $transaction->id,
        'native_quantity' => '10.000000000000000000',
        'functional_amount' => '10.000000000000000000',
    ]);
    Posting::factory()->create([
        'book_id' => $transaction->book_id,
        'journal_transaction_id' => $transaction->id,
        'native_quantity' => '-10.000000000000000000',
        'functional_amount' => '-10.000000000000000000',
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

test('a draft transaction with two balanced postings can transition to posted through an Eloquent update', function () {
    $transaction = JournalTransaction::factory()->draft()->create();
    Posting::factory()->create([
        'book_id' => $transaction->book_id,
        'journal_transaction_id' => $transaction->id,
        'native_quantity' => '10.000000000000000000',
        'functional_amount' => '10.000000000000000000',
    ]);
    Posting::factory()->create([
        'book_id' => $transaction->book_id,
        'journal_transaction_id' => $transaction->id,
        'native_quantity' => '-10.000000000000000000',
        'functional_amount' => '-10.000000000000000000',
    ]);

    $transaction->update(['status' => TransactionStatus::Posted]);

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Posted);
});

test('an empty draft transaction cannot transition to posted through an Eloquent update', function () {
    // Regression for external-review finding 1: this test previously proved the opposite —
    // `$transaction->update(['status' => Posted])` on an empty Draft used to succeed, because the
    // guard only checked whether the transaction was *already* Posted, never whether the postings
    // being transitioned into Posted actually satisfied LED-001. Adapted, not deleted, per the same
    // finding's fix direction: the transition must now validate the persisted postings.
    $transaction = JournalTransaction::factory()->draft()->create();

    expect(fn () => $transaction->update(['status' => TransactionStatus::Posted]))
        ->toThrow(InsufficientPostings::class);

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Draft);
});

test('a draft transaction with one unbalanced posting cannot transition to posted through an Eloquent update', function () {
    // Regression for external-review finding 1's own scenario: "a Draft with one unbalanced
    // posting" used to post successfully.
    $transaction = JournalTransaction::factory()->draft()->create();
    Posting::factory()->create([
        'book_id' => $transaction->book_id,
        'journal_transaction_id' => $transaction->id,
        'native_quantity' => '10.000000000000000000',
        'functional_amount' => '10.000000000000000000',
    ]);

    expect(fn () => $transaction->update(['status' => TransactionStatus::Posted]))
        ->toThrow(InsufficientPostings::class);

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Draft);
});

test('a draft transaction whose postings do not sum to zero cannot transition to posted', function () {
    $transaction = JournalTransaction::factory()->draft()->create();
    Posting::factory()->create([
        'book_id' => $transaction->book_id,
        'journal_transaction_id' => $transaction->id,
        'native_quantity' => '10.000000000000000000',
        'functional_amount' => '10.000000000000000000',
    ]);
    Posting::factory()->create([
        'book_id' => $transaction->book_id,
        'journal_transaction_id' => $transaction->id,
        'native_quantity' => '-9.000000000000000000',
        'functional_amount' => '-9.000000000000000000',
    ]);

    expect(fn () => $transaction->update(['status' => TransactionStatus::Posted]))
        ->toThrow(JournalTransactionIsUnbalanced::class);

    expect($transaction->fresh()->status)->toBe(TransactionStatus::Draft);
});

test('a journal transaction cannot be created directly as posted, bypassing the kernel', function () {
    $book = Book::factory()->create();

    expect(fn () => JournalTransaction::factory()->for($book)->create(['status' => TransactionStatus::Posted]))
        ->toThrow(InsufficientPostings::class);

    expect(JournalTransaction::query()->where('book_id', $book->id)->count())->toBe(0);
});

test('quiet writes do not bypass the posted-immutability or LED-001 guards', function () {
    // Regression for external-review finding 3: saveQuietly()/createQuietly()/withoutEvents()
    // suppress model events, but the guards live in the model's low-level perform*() pipeline, not
    // in an event listener, so they still fire.
    $transaction = JournalTransaction::factory()->posted()->create();
    $transaction->description = 'changed quietly';

    expect(fn () => $transaction->saveQuietly())
        ->toThrow(PostedDataIsImmutable::class);
    expect($transaction->fresh()->description)->not->toBe('changed quietly');

    expect(fn () => JournalTransaction::withoutEvents(fn () => $transaction->update(['description' => 'changed via withoutEvents'])))
        ->toThrow(PostedDataIsImmutable::class);
    expect($transaction->fresh()->description)->not->toBe('changed via withoutEvents');

    $draft = JournalTransaction::factory()->draft()->create();

    expect(fn () => JournalTransaction::createQuietly(['book_id' => $draft->book_id, 'status' => TransactionStatus::Posted, 'effective_at' => now(), 'idempotency_key' => (string) Str::uuid()]))
        ->toThrow(InsufficientPostings::class);
});

test('a posting on a posted transaction rejects a quiet update or delete', function () {
    [, $posting] = postedTransactionWithExistingPosting();
    $posting->memo = 'changed quietly';

    expect(fn () => $posting->saveQuietly())
        ->toThrow(PostedDataIsImmutable::class);
    expect($posting->fresh()->memo)->not->toBe('changed quietly');

    expect(fn () => $posting->deleteQuietly())->toThrow(PostedDataIsImmutable::class);
    expect(Posting::query()->whereKey($posting->id)->exists())->toBeTrue();
});

test('reparenting a posted posting onto a draft transaction is rejected', function () {
    // Regression for external-review finding 2: the guard used to check the *dirty* (new) target's
    // status instead of the posting's persisted parent, so setting journal_transaction_id to a
    // Draft transaction's id passed the guard, leaving the original posted transaction short a
    // posting and unbalanced.
    [$originalTransaction, $posting] = postedTransactionWithExistingPosting();
    $draftTransaction = JournalTransaction::factory()->for($originalTransaction->book)->draft()->create();

    expect(fn () => $posting->update(['journal_transaction_id' => $draftTransaction->id]))
        ->toThrow(PostedDataIsImmutable::class);

    expect($posting->fresh()->journal_transaction_id)->toBe($originalTransaction->id);
    expect(Posting::query()->where('journal_transaction_id', $originalTransaction->id)->count())->toBe(2);
});

/**
 * A posted transaction and a same-book Draft carrying one +5 posting — the exact shape of the
 * validator's probe. Same book on both sides matters: LIF-016's composite
 * `(journal_transaction_id, book_id)` foreign key would otherwise reject a cross-book reparent at
 * the database layer regardless of the application guard, which would prove nothing about the guard
 * itself.
 *
 * @return array{0: JournalTransaction, 1: JournalTransaction, 2: Posting}
 */
function postedTransactionAndSameBookDraftPosting(): array
{
    [$postedTransaction] = postedTransactionWithExistingPosting();
    $draftTransaction = JournalTransaction::factory()->for($postedTransaction->book)->draft()->create();
    $posting = Posting::factory()->create([
        'book_id' => $postedTransaction->book_id,
        'journal_transaction_id' => $draftTransaction->id,
        'native_quantity' => '5.000000000000000000',
        'functional_amount' => '5.000000000000000000',
    ]);

    return [$postedTransaction, $draftTransaction, $posting];
}

test('reparenting a draft-parented posting onto a posted transaction is rejected', function () {
    // Regression for the round-2 validation finding: the finding-2 fix above checked only the
    // posting's *persisted* parent (originalParentId()), which closed the escape-from-posted
    // direction but silently reopened the opposite one — a posting whose persisted parent is a
    // Draft could be updated to point journal_transaction_id at a Posted transaction, injecting new
    // content into posted history. The validator's probe left a posted transaction with 3 postings
    // summing to +5.000000000000000000. The guard must reject the write when *either* side is
    // posted.
    [$postedTransaction, $draftTransaction, $posting] = postedTransactionAndSameBookDraftPosting();

    expect(fn () => $posting->update(['journal_transaction_id' => $postedTransaction->id]))
        ->toThrow(PostedDataIsImmutable::class);

    expect($posting->fresh()->journal_transaction_id)->toBe($draftTransaction->id);
    expect(Posting::query()->where('journal_transaction_id', $postedTransaction->id)->count())->toBe(2);
    expect(MonetaryDecimal::sum(
        Posting::query()->where('journal_transaction_id', $postedTransaction->id)->pluck('functional_amount')
    )->isZero())->toBeTrue();
});

test('reparenting a draft-parented posting onto a posted transaction is rejected through a quiet update', function () {
    [$postedTransaction, $draftTransaction, $posting] = postedTransactionAndSameBookDraftPosting();
    $posting->journal_transaction_id = $postedTransaction->id;

    expect(fn () => $posting->saveQuietly())->toThrow(PostedDataIsImmutable::class);

    expect($posting->fresh()->journal_transaction_id)->toBe($draftTransaction->id);
    expect(Posting::query()->where('journal_transaction_id', $postedTransaction->id)->count())->toBe(2);
});

test('reparenting a draft-parented posting onto a posted transaction is rejected through withoutEvents', function () {
    [$postedTransaction, , $posting] = postedTransactionAndSameBookDraftPosting();
    $draftTransactionId = $posting->journal_transaction_id;

    expect(fn () => Posting::withoutEvents(fn () => $posting->update(['journal_transaction_id' => $postedTransaction->id])))
        ->toThrow(PostedDataIsImmutable::class);

    expect($posting->fresh()->journal_transaction_id)->toBe($draftTransactionId);
    expect(Posting::query()->where('journal_transaction_id', $postedTransaction->id)->count())->toBe(2);
});

test('a posting cannot be created against an already-posted transaction', function () {
    // The posted() factory state itself now attaches two balanced postings (LED-001, external
    // review finding 1), so the count this test protects is 2 (the factory's own), not 0: nothing
    // new was allowed to attach.
    $transaction = JournalTransaction::factory()->posted()->create();

    expect(fn () => Posting::factory()->create([
        'book_id' => $transaction->book_id,
        'journal_transaction_id' => $transaction->id,
    ]))->toThrow(PostedDataIsImmutable::class);

    expect(Posting::query()->where('journal_transaction_id', $transaction->id)->count())->toBe(2);
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
