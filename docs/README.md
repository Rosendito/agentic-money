# Agentic Money Ledger Knowledge Base

## Purpose

This directory is the canonical knowledge base for rebuilding the personal-finance ledger.
It defines how the new ledger must behave before database tables, Laravel services, UI flows,
imports, or implementation tasks are designed.

Documents from the legacy `laravel-personal-finance` project may provide context, but they are not
authoritative for Agentic Money. If a legacy rule conflicts with this directory, the rule in
`docs/` wins.

## Current phase

The current phase is **foundation implementation**: the ledger schema slice is unblocked and
implementation tasks for it may exist. Posting behavior, valuation, and obligations remain
specification-first.

Do not create implementation tasks or application code from incomplete assumptions. First:

1. Review every locked rule.
2. Resolve decisions that block the intended implementation slice.
3. Convert the numbered scenarios into executable acceptance tests.
4. Design implementation tasks that reference these rules instead of restating them.

Historical-data migration is not part of the critical path. It may be assessed separately after
the new ledger is reliable.

## Authority labels

Each document distinguishes between:

- **Locked**: approved behavior. Implementations must follow it.
- **Proposed**: the preferred direction, but still subject to explicit approval.
- **Open**: a decision that has not been made. An agent must not silently choose an option when
  the choice materially affects accounting behavior.

Normative rules have stable identifiers:

- `SCP-*`: scope and product boundaries.
- `LED-*`: ledger and posting rules.
- `MNY-*`: instruments, quantities, and precision.
- `VAL-*`: prices, valuation, and foreign-exchange rules.
- `ACC-*`: accounts and containers.
- `OBL-*`: debts, loans, and settlement.
- `LIF-*`: lifecycle, immutability, and idempotency.
- `RPT-*`: reporting semantics.
- `SCN-*`: canonical accounting scenarios.
- `ARC-*`: project architecture and module boundaries.

Tests and implementation tasks should cite rule and scenario IDs.

## Required reading order

1. [Scope and principles](01-scope-and-principles.md)
2. [Domain glossary](02-domain-glossary.md)
3. [Ledger model](03-ledger-model.md)
4. [Money, valuations, and rates](04-money-valuations-and-rates.md)
5. [Accounts and obligations](05-accounts-and-obligations.md)
6. [Transaction scenarios](06-transaction-scenarios.md)
7. [Integrity and lifecycle](07-integrity-and-lifecycle.md)
8. [Reporting semantics](08-reporting-semantics.md)
9. [Domain architecture](09-domain-architecture.md)
10. [Decision records](decisions/README.md)

## Agent context loading

Agents load documentation at three levels:

1. **Baseline:** this README, the assigned task document when one exists, and
   [Domain architecture](09-domain-architecture.md).
2. **Task-specific:** every document and rule ID named by the task. Use the routing table below to
   fill gaps.
3. **Foundation:** the complete knowledge base when changing ledger invariants, the domain model,
   module boundaries, multiple domains, or a foundational decision, or when documents conflict.

Do not load the complete knowledge base by default when task-specific context is sufficient.

| Work area                           | Task-specific documents                      |
| ----------------------------------- | -------------------------------------------- |
| Tables, models, and relationships   | `02`, `03`, `05`, `07`, `09`                 |
| Income and expense registration     | `03`, `06`, `07`, `09`                       |
| Transfers and currency exchanges    | `03`, `04`, `06`, `07`                       |
| Debts and loans                     | `03`, `05`, `06`, `07`                       |
| BCV, Binance, and cash-market rates | `04`, `09`, and the relevant decision record |
| Events and listeners                | `07`, `09`                                   |
| Reports and statistics              | `03`, `04`, `08`                             |
| Foundational architecture review    | Complete knowledge base                      |

## Locked direction

- The new application will be built from a clean foundation using Laravel.
- Filament is not part of the new application architecture.
- The ledger is the first product milestone; budgets and planned purchases come later.
- The initial book functional instrument is USDT.
- The application must preserve native balances and historical USDT book values separately.
- Posted financial events are immutable and corrected by reversal.
- Asset accounts never go negative: spending, transfers, and exchanges require sufficient posted
  native balance at their effective time, and credit or overdraft — when introduced — uses
  explicit liability semantics (ADR-004 as amended 2026-08-08).
- The dashboard is primarily a read model; accounting behavior belongs in domain/application
  services, not UI components.
- Backend capabilities are organized as vertical modules under `app/Domain/`.
- Laravel discovers listeners under both `app/Listeners/` and `app/Domain/*/Listeners/`.
- The core must be able to support BCV, cash-market, and Binance quotes and future Binance imports,
  without implementing those integrations in the first ledger slice.
- Agent and MCP interfaces are outside the current scope. Future interfaces must call the same
  application use cases as every other client.

## Known open decisions

The following decisions must be resolved before their affected implementation slices:

1. Which quote aggregation policy represents Binance P2P valuation. Deferred to the Binance
   provider-integration slice; it does not block manual or observed quote recording. The noted
   direction is the executable best offer for a configurable reference amount with merchant trust
   filters (see `04-money-valuations-and-rates.md`).
2. Which obligation indexing rules are supported in the first release. The noted direction is to
   defer indexing and start with fixed-denomination obligations.

Resolved decisions: precision and rounding
([ADR-001](decisions/ADR-001-decimal-precision-and-rounding.md)), functional-instrument
immutability ([ADR-002](decisions/ADR-002-functional-instrument-immutability.md)), the expense
classification boundary ([ADR-003](decisions/ADR-003-expense-classification-boundary.md)),
cost basis with backdated acquisitions
([ADR-004](decisions/ADR-004-cost-basis-and-backdating.md), amended 2026-08-08: disposals must be
fully funded and asset accounts never go negative), and USD representations as distinct
instruments ([ADR-005](decisions/ADR-005-usd-representations-as-distinct-instruments.md)).

Open decisions are tracked in [the decision records directory](decisions/README.md). Do not infer them from
legacy database columns or UI behavior.

## Rules for implementation tasks

Implementation tasks belong under [docs/tasks](tasks/README.md), are created from the
[task template](tasks/TEMPLATE.md), and remain committed as project history. Each task must
contain:

- one clear intention and the context needed to understand it;
- required reading and relevant rule or scenario IDs;
- important design boundaries and easily missed security or integrity risks;
- observable acceptance criteria and explicit non-goals;
- short execution and independent validation records.

A task may implement a horizontal foundation or a bounded vertical capability. It must not combine
schema design, every domain service, every user action, external integrations, and reporting into a
single task.
