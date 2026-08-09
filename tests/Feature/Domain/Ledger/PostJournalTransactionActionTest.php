<?php

use App\Domain\Ledger\Actions\PostJournalTransactionAction;
use App\Domain\Ledger\Data\PostingInput;
use App\Domain\Ledger\Data\PostJournalTransactionCommand;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Exceptions\CategoryNotAllowedOnAccountType;
use App\Domain\Ledger\Exceptions\CrossBookReference;
use App\Domain\Ledger\Exceptions\IdempotencyConflict;
use App\Domain\Ledger\Exceptions\InsufficientPostings;
use App\Domain\Ledger\Exceptions\JournalTransactionIsUnbalanced;
use App\Domain\Ledger\Exceptions\PostingSignMismatch;
use App\Domain\Ledger\Exceptions\ZeroPostingIsNotAllowed;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Book;
use App\Domain\Ledger\Models\Category;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Ledger\Models\Posting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function twoAccountBook(): array
{
    $book = Book::factory()->create();
    $accountA = Account::factory()->for($book)->type(AccountType::Asset)->create();
    $accountB = Account::factory()->for($book)->type(AccountType::Equity)->create();

    return [$book, $accountA, $accountB];
}

test('the kernel rejects fewer than two postings', function () {
    [$book, $accountA] = twoAccountBook();

    expect(fn () => (new PostJournalTransactionAction)->handle(new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'single-posting',
        effectiveAt: Carbon::now(),
        description: null,
        postings: [new PostingInput($accountA->id, '10', '10')],
    )))->toThrow(InsufficientPostings::class);

    expect(JournalTransaction::query()->count())->toBe(0);
});

test('the kernel rejects a functional sum that is not exactly zero at scale 18', function () {
    [$book, $accountA, $accountB] = twoAccountBook();

    expect(fn () => (new PostJournalTransactionAction)->handle(new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'unbalanced',
        effectiveAt: Carbon::now(),
        description: null,
        postings: [
            new PostingInput($accountA->id, '10', '10'),
            new PostingInput($accountB->id, '-9.999999999999999999', '-9.999999999999999999'),
        ],
    )))->toThrow(JournalTransactionIsUnbalanced::class);

    expect(JournalTransaction::query()->count())->toBe(0);
});

test('the kernel rejects a posting violating the LED-005 zero policy', function () {
    [$book, $accountA, $accountB] = twoAccountBook();

    expect(fn () => (new PostJournalTransactionAction)->handle(new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'zero-posting',
        effectiveAt: Carbon::now(),
        description: null,
        postings: [
            new PostingInput($accountA->id, '0', '0'),
            new PostingInput($accountB->id, '10', '10'),
        ],
    )))->toThrow(ZeroPostingIsNotAllowed::class);

    expect(JournalTransaction::query()->count())->toBe(0);
});

test('the kernel rejects a sign contradicting the declared shape', function () {
    [$book, $accountA, $accountB] = twoAccountBook();

    expect(fn () => (new PostJournalTransactionAction)->handle(new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'sign-mismatch',
        effectiveAt: Carbon::now(),
        description: null,
        postings: [
            // Native quantity is positive but functional amount is negative for the same posting:
            // the same movement cannot be an increase in one denomination and a decrease in the
            // other (LED-008).
            new PostingInput($accountA->id, '10', '-10'),
            new PostingInput($accountB->id, '-10', '10'),
        ],
    )))->toThrow(PostingSignMismatch::class);

    expect(JournalTransaction::query()->count())->toBe(0);
});

test('the kernel rejects an account from another book', function () {
    [$book, $accountA] = twoAccountBook();
    $otherBook = Book::factory()->create();
    $accountInOtherBook = Account::factory()->for($otherBook)->create();

    expect(fn () => (new PostJournalTransactionAction)->handle(new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'cross-book-account',
        effectiveAt: Carbon::now(),
        description: null,
        postings: [
            new PostingInput($accountA->id, '10', '10'),
            new PostingInput($accountInOtherBook->id, '-10', '-10'),
        ],
    )))->toThrow(CrossBookReference::class);

    expect(JournalTransaction::query()->count())->toBe(0);
});

test('the kernel rejects a category from another book', function () {
    [$book, , $accountB] = twoAccountBook();
    // An Expense account, not the Asset account from twoAccountBook(): the categorized posting
    // must be one CategoryNotAllowedOnAccountType would otherwise accept, so this test isolates
    // the cross-book rejection instead of tripping the account-type rule first.
    $expenseAccount = Account::factory()->for($book)->type(AccountType::Expense)->create();
    $otherBook = Book::factory()->create();
    $categoryInOtherBook = Category::factory()->for($otherBook)->create();

    expect(fn () => (new PostJournalTransactionAction)->handle(new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'cross-book-category',
        effectiveAt: Carbon::now(),
        description: null,
        postings: [
            new PostingInput($expenseAccount->id, '10', '10', $categoryInOtherBook->id),
            new PostingInput($accountB->id, '-10', '-10'),
        ],
    )))->toThrow(CrossBookReference::class);

    expect(JournalTransaction::query()->count())->toBe(0);
});

test('the kernel rejects a category attached to a posting whose account is not income or expense', function () {
    [$book, $accountA, $accountB] = twoAccountBook();
    $category = Category::factory()->for($book)->create();

    expect(fn () => (new PostJournalTransactionAction)->handle(new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'category-on-asset',
        effectiveAt: Carbon::now(),
        description: null,
        postings: [
            new PostingInput($accountA->id, '10', '10', $category->id),
            new PostingInput($accountB->id, '-10', '-10'),
        ],
    )))->toThrow(CategoryNotAllowedOnAccountType::class);

    expect(JournalTransaction::query()->count())->toBe(0);
});

test('reusing an idempotency key with the identical canonical payload returns the existing transaction', function () {
    [$book, $accountA, $accountB] = twoAccountBook();
    $effectiveAt = Carbon::now();

    $command = new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'replay',
        effectiveAt: $effectiveAt,
        description: 'first attempt',
        postings: [
            new PostingInput($accountA->id, '10', '10'),
            new PostingInput($accountB->id, '-10', '-10'),
        ],
    );

    $first = (new PostJournalTransactionAction)->handle($command);
    $second = (new PostJournalTransactionAction)->handle($command);

    expect($second->id)->toBe($first->id);
    expect(JournalTransaction::query()->where('idempotency_key', 'replay')->count())->toBe(1);
});

test('reordering two postings on the same account is still recognized as the identical payload', function () {
    // Regression for validation finding 3: the canonical sort key used to be account_id +
    // category_id only, so [A:+3, A:+7, C:-10] and [A:+7, A:+3, C:-10] canonicalized differently
    // and a genuine replay with the first two postings swapped raised a false IdempotencyConflict.
    [$book, $accountA, $accountC] = twoAccountBook();

    $first = (new PostJournalTransactionAction)->handle(new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'swap-replay',
        effectiveAt: Carbon::now(),
        description: null,
        postings: [
            new PostingInput($accountA->id, '3', '3'),
            new PostingInput($accountA->id, '7', '7'),
            new PostingInput($accountC->id, '-10', '-10'),
        ],
    ));

    $second = (new PostJournalTransactionAction)->handle(new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'swap-replay',
        effectiveAt: $first->effective_at,
        description: null,
        postings: [
            new PostingInput($accountA->id, '7', '7'),
            new PostingInput($accountA->id, '3', '3'),
            new PostingInput($accountC->id, '-10', '-10'),
        ],
    ));

    expect($second->id)->toBe($first->id);
    expect(JournalTransaction::query()->where('idempotency_key', 'swap-replay')->count())->toBe(1);
});

test('reusing an idempotency key with a different payload raises a conflict', function () {
    [$book, $accountA, $accountB] = twoAccountBook();
    $effectiveAt = Carbon::now();

    (new PostJournalTransactionAction)->handle(new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'conflict',
        effectiveAt: $effectiveAt,
        description: null,
        postings: [
            new PostingInput($accountA->id, '10', '10'),
            new PostingInput($accountB->id, '-10', '-10'),
        ],
    ));

    expect(fn () => (new PostJournalTransactionAction)->handle(new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'conflict',
        effectiveAt: $effectiveAt,
        description: null,
        postings: [
            new PostingInput($accountA->id, '99', '99'),
            new PostingInput($accountB->id, '-99', '-99'),
        ],
    )))->toThrow(IdempotencyConflict::class);

    expect(JournalTransaction::query()->where('idempotency_key', 'conflict')->count())->toBe(1);
});

test('posting is atomic: a persistence failure after partial work leaves no header or posting rows behind', function () {
    $book = Book::factory()->create();
    $accountA = Account::factory()->for($book)->type(AccountType::Asset)->create();
    $accountB = Account::factory()->for($book)->type(AccountType::Equity)->create();
    $accountC = Account::factory()->for($book)->type(AccountType::Equity)->create();

    $postingInsertCount = 0;

    DB::listen(function ($query) use (&$postingInsertCount) {
        if (str_contains(strtolower($query->sql), 'insert into "postings"') || str_contains(strtolower($query->sql), 'insert into postings')) {
            $postingInsertCount++;

            if ($postingInsertCount === 2) {
                throw new RuntimeException('Simulated persistence failure after partial work.');
            }
        }
    });

    expect(fn () => (new PostJournalTransactionAction)->handle(new PostJournalTransactionCommand(
        bookId: $book->id,
        idempotencyKey: 'atomic-failure',
        effectiveAt: Carbon::now(),
        description: null,
        postings: [
            new PostingInput($accountA->id, '10', '10'),
            new PostingInput($accountB->id, '-5', '-5'),
            new PostingInput($accountC->id, '-5', '-5'),
        ],
    )))->toThrow(RuntimeException::class);

    expect(JournalTransaction::query()->where('idempotency_key', 'atomic-failure')->count())->toBe(0)
        ->and(Posting::query()->where('book_id', $book->id)->count())->toBe(0);
});
