<?php

namespace App\Domain\Ledger\Events;

use App\Domain\Ledger\Models\JournalTransaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A journal transaction was posted (LED-015). Dispatched only after the posting kernel's database
 * transaction commits (ARC-013): {@see ShouldDispatchAfterCommit} makes Laravel hold the dispatch
 * until commit and discard it entirely on rollback. No listener exists for this event in this task,
 * and no invariant may ever depend on one (ARC-012) — every rule the kernel enforces already ran
 * inside the same transaction that produced this event.
 */
final class JournalTransactionPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly JournalTransaction $transaction,
    ) {}
}
