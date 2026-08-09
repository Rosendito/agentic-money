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

    /**
     * The account type a controlled book-initialization service (ACC-007) must use when creating
     * this role's account, following the canonical sign convention (LED-008): a gain behaves like
     * income (credit-normal), a loss like an expense (debit-normal), and the correction suspense
     * account is a nominal balancing account like opening equity, never a real asset or liability.
     */
    public function accountType(): AccountType
    {
        return match ($this) {
            self::OpeningEquity, self::CorrectionSuspense => AccountType::Equity,
            self::IncomeControl, self::RealizedFxGain => AccountType::Income,
            self::ExpenseControl, self::RealizedFxLoss, self::Fees, self::Rounding => AccountType::Expense,
        };
    }
}
