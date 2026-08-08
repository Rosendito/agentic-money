---
id: TASK-006
title: Quotes and valuation policies
status: draft
created_at: 2026-08-08
---

# TASK-006: Quotes and valuation policies

## Intention

Let the ledger answer what a quantity of one instrument was worth in another at a given moment, from
recorded evidence rather than from a number typed into a form. After this task the book can value a
VES expense in USDT using a quote that existed at the time of the event, keep the evidence behind that
valuation, and fail honestly when no acceptable quote exists instead of guessing.

This is the capability that unblocks everything multi-currency: exchanges, realized FX results,
current market value in reports, and eventually automatic valuation from providers.

## Context

The ledger can record transactions but every functional amount has to be supplied by the caller. That
is workable while the book only moves USDT, and insufficient as soon as bolívares are involved.

Quotes are recorded evidence, not live lookups: a posted transaction must keep the rate it used so its
history never drifts when newer prices arrive.

## Blocking decisions

Both former blockers were settled on 2026-08-08:

- USD cash and bank USD are distinct instruments — `USD.CASH` and `USD.BCV`
  ([ADR-005](../decisions/ADR-005-usd-representations-as-distinct-instruments.md)).
- The Binance P2P aggregation policy is **deferred to the provider-integration slice** and no
  longer blocks this task, because live integrations are already out of scope here. This slice
  records manual and observed quotes and resolves valuations from them; the quote model must keep
  the calculation-metadata fields (`VAL-002`) that a future aggregate will populate. The noted
  direction (executable best offer for a configurable reference amount) is recorded in
  `docs/04-money-valuations-and-rates.md`.

## Required reading

> Filled when the task is planned.

## Rules that must remain true

> Filled when the task is planned.

## Design and hidden risks

> Filled when the task is planned.

## Acceptance criteria

> Filled when the task is planned.

## Out of scope

- Live Binance, BCV, or cash-market integrations.
- Cross-instrument exchange postings and realized FX results.
- Explicit revaluation and unrealized FX presentation.

## Execution

> Filled by the executor.

- **Summary:** Pending.
- **Important decisions or deviations:** None.
- **Verification:** Pending.
- **Commit:** Pending.

## Validation

> Filled by the validator.

- **Verdict:** Pending.
- **Findings:** Pending.
- **Evidence:** Pending.
- **Follow-ups:** None.
