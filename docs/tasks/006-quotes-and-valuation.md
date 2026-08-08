---
id: TASK-006
title: Quotes and valuation policies
status: blocked
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

This task stays `blocked` until two open decisions are resolved:

- which aggregation policy represents Binance P2P valuation;
- whether USD cash and constrained USD bank balances are one instrument or distinct instruments.

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
