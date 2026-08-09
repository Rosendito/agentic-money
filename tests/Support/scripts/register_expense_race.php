<?php

use App\Domain\Ledger\Actions\RegisterExpenseAction;
use App\Domain\Ledger\Data\RegisterExpenseCommand;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;

/**
 * Standalone script, not part of the application: boots the full Laravel application in its own OS
 * process and performs exactly one RegisterExpenseAction call. Used only by
 * tests/Feature/Domain/Ledger/Concurrency/AccountBalanceRaceTest.php
 * (docs/tasks/008-posting-kernel-hardening.md, finding 4) to obtain a second, genuinely independent
 * database connection racing against the main test process — a single PHP process cannot run two
 * overlapping blocking database calls at once, so proving the kernel's row lock actually serializes
 * two concurrent expenses requires two real OS processes, not two Laravel "connection" objects
 * inside one process.
 *
 * Usage: php register_expense_race.php <bookId> <assetAccountId> <amount> <idempotencyKey>
 *            <barrierFile> <resultFile>
 *
 * Waits for <barrierFile> to appear before calling the kernel, so both racing processes start their
 * call as close together in time as the OS scheduler allows. Writes "success" or
 * "failure:<ExceptionClass>" to <resultFile> when done.
 */

require __DIR__.'/../../../vendor/autoload.php';

[, $bookId, $assetAccountId, $amount, $idempotencyKey, $barrierFile, $resultFile] = $argv;

$app = require __DIR__.'/../../../bootstrap/app.php';
/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$deadline = microtime(true) + 5;

while (! file_exists($barrierFile)) {
    if (microtime(true) > $deadline) {
        file_put_contents($resultFile, 'failure:BarrierTimeout');
        exit(1);
    }

    usleep(1000);
}

try {
    $action = $app->make(RegisterExpenseAction::class);

    $action->handle(new RegisterExpenseCommand(
        bookId: (int) $bookId,
        assetAccountId: (int) $assetAccountId,
        amount: $amount,
        categoryId: null,
        feeAmount: null,
        idempotencyKey: $idempotencyKey,
        effectiveAt: Carbon::now(),
    ));

    file_put_contents($resultFile, 'success');
} catch (Throwable $e) {
    file_put_contents($resultFile, 'failure:'.get_class($e));
}
