<?php

namespace App\Domain\Ledger\Actions\Concerns;

use App\Domain\Ledger\Exceptions\NonPositiveAmount;
use App\Domain\Money\ValueObjects\MonetaryDecimal;

/**
 * Every intent action documents its amount fields as positive magnitudes and derives each
 * posting's sign itself. A zero or negative input would silently invert the declared shape (an
 * "expense" that funds the asset instead of spending it, an "income" that drains it) rather than
 * contradicting it, so the kernel's same-posting sign-consistency check cannot catch it — the
 * inverted posting is still internally consistent. This is the intent-action-side half of
 * enforcing the declared shape.
 */
trait RequiresPositiveAmount
{
    private function assertPositiveAmount(MonetaryDecimal $amount, string $field): void
    {
        if ($amount->isZero() || $amount->isNegative()) {
            throw new NonPositiveAmount($field, (string) $amount);
        }
    }
}
