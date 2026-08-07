<?php

namespace App\Domain\Ledger\Enums;

/**
 * Stable roles for system-managed nominal and equity accounts (docs/03-ledger-model.md,
 * "System-managed nominal and equity accounts"). System accounts are created by a controlled
 * book-initialization service (ACC-007); this enum only names the closed vocabulary of roles that
 * schema-level system accounts may declare.
 */
enum SystemAccountRole: string
{
    case OpeningEquity = 'OpeningEquity';
    case IncomeControl = 'IncomeControl';
    case ExpenseControl = 'ExpenseControl';
    case RealizedFxGain = 'RealizedFxGain';
    case RealizedFxLoss = 'RealizedFxLoss';
    case Fees = 'Fees';
    case Rounding = 'Rounding';
    case CorrectionSuspense = 'CorrectionSuspense';
}
