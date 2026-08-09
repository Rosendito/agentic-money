<?php

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\SystemAccountRole;

test('every system account role maps to exactly one account type', function (SystemAccountRole $role, AccountType $expected) {
    expect($role->accountType())->toBe($expected);
})->with([
    [SystemAccountRole::OpeningEquity, AccountType::Equity],
    [SystemAccountRole::IncomeControl, AccountType::Income],
    [SystemAccountRole::ExpenseControl, AccountType::Expense],
    [SystemAccountRole::RealizedFxGain, AccountType::Income],
    [SystemAccountRole::RealizedFxLoss, AccountType::Expense],
    [SystemAccountRole::Fees, AccountType::Expense],
    [SystemAccountRole::Rounding, AccountType::Expense],
    [SystemAccountRole::CorrectionSuspense, AccountType::Equity],
]);
