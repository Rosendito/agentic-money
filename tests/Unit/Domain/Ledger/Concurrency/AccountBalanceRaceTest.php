<?php

use App\Domain\Ledger\Actions\BootstrapBookAction;
use App\Domain\Ledger\Actions\PostJournalTransactionAction;
use App\Domain\Ledger\Actions\RegisterOpeningBalanceAction;
use App\Domain\Ledger\Data\RegisterOpeningBalanceCommand;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\JournalTransaction;
use App\Domain\Ledger\Models\Posting;
use App\Domain\Money\Models\Instrument;
use App\Models\User;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Tests\Support\NonTransactionalTestCase;

uses(NonTransactionalTestCase::class);

/**
 * Regression coverage for external-review finding 4 (docs/tasks/008-posting-kernel-hardening.md): a
 * check-then-post race in ACC-006's balance guard. Two concurrent 80-unit expenses against a
 * 100-unit balance used to both read the balance before either posted, both project a sufficient 20,
 * and both post — final balance -60.
 *
 * This needs two genuinely independent database connections and two genuinely overlapping calls,
 * which a single, synchronous PHP process cannot produce on its own: two separate OS processes (see
 * tests/Support/scripts/register_expense_race.php) each open their own connection to the same
 * PostgreSQL instance and race for real. SQLite cannot exhibit this race at all (see the dedicated
 * test below) because it serializes all writers through a single database-level lock; Postgres
 * allows genuinely concurrent writers, which is exactly what a check-then-post gap needs to be
 * exploitable.
 *
 * Runs against a NonTransactionalTestCase (see tests/Support/NonTransactionalTestCase.php), not the
 * project's default RefreshDatabase binding: RefreshDatabase wraps each test in a transaction that
 * is rolled back at the end, which would make this test's own fixture rows invisible to the racing
 * child processes' independent connections.
 */
test('two concurrent expenses cannot both post past the asset balance', function () {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped(
            'Requires two real, concurrently-writing database connections. SQLite serializes every '.
            'writer through a single database-level lock (there is no row-level lockForUpdate), so '.
            'two connections can never both be mid-transaction against the same row at once — the '.
            'race this test proves impossible on PostgreSQL cannot even be constructed on SQLite. '.
            'Run via: docker compose up --wait -d && composer test:pgsql.'
        );
    }

    $user = User::factory()->create();
    $usdt = Instrument::factory()->create(['code' => 'USDT']);
    $book = (new BootstrapBookAction)->handle($user, $usdt, 'Personal');
    $asset = Account::factory()->for($book)->type(AccountType::Asset)->create(['native_instrument_id' => $usdt->id]);

    (new RegisterOpeningBalanceAction(new PostJournalTransactionAction))->handle(new RegisterOpeningBalanceCommand(
        bookId: $book->id,
        assetAccountId: $asset->id,
        amount: '100',
        idempotencyKey: 'race-opening',
        effectiveAt: Carbon::now(),
    ));

    $barrierFile = tempnam(sys_get_temp_dir(), 'race-barrier-');
    unlink($barrierFile);
    $resultFileA = tempnam(sys_get_temp_dir(), 'race-result-a-');
    $resultFileB = tempnam(sys_get_temp_dir(), 'race-result-b-');
    $script = __DIR__.'/../../../../Support/scripts/register_expense_race.php';

    // Read the *resolved* connection config, not raw getenv(): under `--parallel` (this project's
    // own composer test:pgsql script), Laravel's ParallelTesting rewrites the database name per
    // worker process via config(), not via a real environment variable, so getenv('DB_DATABASE')
    // would still report the base "testing" name while this worker's own connection is actually
    // against "testing_test_N". Passing the wrong database to the child processes made them unable
    // to see the fixture rows at all, surfacing as neither a "success" nor an
    // InsufficientNativeBalance rejection.
    $connectionConfig = config('database.connections.'.config('database.default'));

    $env = array_merge($_ENV, $_SERVER, array_filter([
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => $connectionConfig['host'] ?? null,
        'DB_PORT' => $connectionConfig['port'] ?? null,
        'DB_DATABASE' => $connectionConfig['database'] ?? null,
        'DB_USERNAME' => $connectionConfig['username'] ?? null,
        'DB_PASSWORD' => $connectionConfig['password'] ?? null,
    ]));

    $processA = new Process([PHP_BINARY, $script, (string) $book->id, (string) $asset->id, '80', 'race-expense-a', $barrierFile, $resultFileA], env: $env);
    $processB = new Process([PHP_BINARY, $script, (string) $book->id, (string) $asset->id, '80', 'race-expense-b', $barrierFile, $resultFileB], env: $env);

    $processA->setTimeout(10);
    $processB->setTimeout(10);
    $processA->start();
    $processB->start();

    // Give both child processes time to boot the framework and reach the barrier wait before
    // releasing them, so their kernel calls start as close together as the OS scheduler allows.
    usleep(400_000);
    file_put_contents($barrierFile, '1');

    $processA->wait();
    $processB->wait();

    $resultA = trim(file_get_contents($resultFileA) ?: '');
    $resultB = trim(file_get_contents($resultFileB) ?: '');

    @unlink($barrierFile);
    @unlink($resultFileA);
    @unlink($resultFileB);

    // NonTransactionalTestCase does not roll back, so every row created above is genuinely
    // committed: a `finally` guarantees the cleanup below still runs even if one of the
    // expect() calls fails, instead of leaving committed books/accounts/postings/a user behind
    // to cascade into unrelated failures in this worker's database (round-2 validation finding).
    try {
        expect($processA->getErrorOutput())->toBe('')
            ->and($processB->getErrorOutput())->toBe('');

        $results = [$resultA, $resultB];
        $successes = array_filter($results, fn (string $r): bool => $r === 'success');
        $rejections = array_filter($results, fn (string $r): bool => str_starts_with($r, 'failure:App\Domain\Ledger\Exceptions\InsufficientNativeBalance'));

        // Reproduced against the pre-fix code (see the execution record): both processes used to
        // report "success", leaving the asset at -60. With the fix, the kernel locks the asset
        // account row and re-derives the balance inside that lock, so the second racing expense to
        // reach the lock always observes the first one's already-posted effect.
        expect($successes)->toHaveCount(1);
        expect($rejections)->toHaveCount(1);
        expect($asset->fresh()->postedNativeBalance())->toBe('20.000000000000000000');
    } finally {
        // Bulk query-builder deletes bypass the LIF-003 model guard by design (documented, out of
        // scope) — exactly the escape hatch this test needs to remove posted rows in a teardown.
        Posting::query()->where('book_id', $book->id)->delete();
        JournalTransaction::query()->where('book_id', $book->id)->delete();
        Account::query()->where('book_id', $book->id)->delete();
        $book->delete();
        $usdt->delete();
        $user->delete();
    }
});
