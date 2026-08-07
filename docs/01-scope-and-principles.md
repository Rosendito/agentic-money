# Scope and Principles

## Status

Unless marked otherwise, the rules in this document are **Locked**.

## Product objective

Build a reliable personal-finance ledger for an economy where several monetary instruments and
markets coexist, prices change frequently, and an obligation may be settled with an instrument
different from the one in which it is denominated.

The ledger must answer three questions independently:

1. What quantity is held or owed in its native instrument?
2. What was its book value in the functional instrument when the event occurred?
3. What is its value under a selected market policy at a later point in time?

## Locked scope

**SCP-001 — Ledger first.** The ledger, accounts, monetary instruments, valuations, obligations,
and derived queries must be reliable before budgets, purchase planning, forecasting, or other
product features are added.

**SCP-002 — Clean implementation.** The new ledger will be implemented from a clean foundation.
Legacy tables and behavior do not constrain the new domain model.

**SCP-003 — Historical migration is independent.** Preserving the legacy database is not a release
gate. A later migration may import recoverable history without weakening the new invariants.

**SCP-004 — USDT functional instrument.** The first ledger book uses USDT as its functional
instrument. Every posted transaction must have a balanced historical value in USDT while retaining
all native quantities.

**SCP-005 — Interface-independent domain.** Accounting rules live below HTTP, CLI, dashboard,
importers, and future agent interfaces. Every interface calls the same application use cases.

**SCP-006 — Read-oriented dashboard.** The custom dashboard presents history, balances, metrics,
obligations, and valuation information. It must not contain posting logic.

**SCP-007 — Integration-ready, not integration-led.** The core must be able to represent events
from BCV, cash markets, Binance spot conversion, Binance P2P, and bank settlement, but initial
ledger design must not depend on those providers being available.

## Accounting principles

**SCP-008 — One economic event, one journal transaction.** A currency exchange, debt settlement,
or imported trade should be represented as one economic event with all necessary postings. Do not
force the user to create a second transaction merely to expose a fee or exchange difference.

**SCP-009 — No unexplained balancing.** The system must never invent or distribute an unknown
functional amount merely to make a transaction balance. Every amount must come from native
quantity, an explicit valuation policy, an executed price, a carrying value, or an explicit
gain/loss, fee, or rounding posting.

**SCP-010 — Preserve facts and policy.** Store facts such as quantities, timestamps, external IDs,
and executed prices separately from policies such as which quote source should value a VES expense.

**SCP-011 — Historical values do not drift.** Newly fetched market prices do not rewrite posted
transactions. Current valuation and accounting revaluation are separate operations.

**SCP-012 — Complexity belongs in the domain, not the user's form.** Users express economic intent:
expense, income, transfer, exchange, borrowing, lending, repayment, collection, refund, or reversal.
The application derives the accounting postings and exposes them for review.

## Explicit non-goals for the ledger foundation

- Rebuilding the current Filament interface.
- Implementing MCP or natural-language parsing.
- Importing the existing SQLite history.
- Implementing production Binance or bank synchronization.
- Building budgets, scheduled purchases, goals, or forecasting.
- Supporting securities, tax lots, or investment accounting beyond what is necessary to keep the
  monetary-instrument model extensible.
- Implementing household sharing or multi-user collaboration.

## Quality principles

**SCP-013 — Scenario-driven design.** Migrations and services are not accepted until the relevant
canonical scenarios have executable tests.

**SCP-014 — Decimal safety.** Monetary values are accepted and manipulated as validated decimal
strings or dedicated value objects. Binary floating-point values are not part of domain contracts.

**SCP-015 — Database and domain defenses.** Use database constraints for local facts that can be
enforced there and domain services for aggregate rules such as balanced postings. Do not rely on UI
validation or model observers as the only integrity boundary.

**SCP-016 — Honest gates.** Unit tests can prove local accounting behavior. They do not prove live
BCV, Binance, bank, queue, or deployment behavior. External acceptance remains a separate gate.
