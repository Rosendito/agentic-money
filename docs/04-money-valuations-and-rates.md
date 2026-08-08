# Money, Valuations, and Rates

## Status

Rules are **Locked** unless marked Proposed or Open.

## Instruments and precision

**MNY-001 — Flexible instrument codes.** Instrument identifiers must support codes longer than
three characters, including USDT and USDC. The model must not assume every instrument is an ISO-4217
currency.

**MNY-002 — Instrument-defined precision.** Each instrument declares its supported native
precision. Storage must preserve at least that precision. Domain input must reject excess precision
or normalize it only through an explicit rounding policy.

**MNY-003 — Decimal-only contracts.** Native quantities, functional amounts, and rates cross domain
boundaries as decimal strings or dedicated decimal value objects, never binary floats.

**MNY-004 — Instrument versus container.** USDT, USDC, USD, EUR, and VES are instruments. Binance,
bank accounts, and physical cash are containers or account contexts.

**MNY-005 — Fungibility is explicit.** Two accounts with the same instrument code are presumed to
hold the same unit only when no material restriction prevents par substitution. Non-fungible USD
positions must be distinguished through an approved instrument or position policy.

## Functional instrument

**MNY-006 — Initial functional instrument.** The initial book functional instrument is USDT.

**MNY-007 — Native truth is permanent.** Functional valuation never replaces native quantity. A
VES account continues to answer exactly how many VES are held regardless of its USDT value.

**MNY-008 — Functional history is permanent.** Once a transaction is posted, its functional amounts
are immutable. Later prices affect current valuation or explicit revaluation transactions only.

**Open — Functional-instrument changes.** The first implementation may prohibit changing the
functional instrument after the first posted transaction. Supporting a change would require a new
book or an explicit conversion boundary; silent rebasing is forbidden.

## Quote model

**VAL-001 — Explicit pair convention.** Every quote stores base instrument, quote instrument, and a
rate following `1 BASE = rate QUOTE`.

**VAL-002 — Complete quote evidence.** A quote records at least:

- base and quote instruments;
- rate;
- source and source-specific market identifier;
- side or price kind when applicable;
- effective time;
- retrieval time;
- whether it is observed, calculated, executed, manual, or estimated;
- calculation metadata sufficient to explain aggregates such as median or weighted average.

**VAL-003 — Direction matters.** Quote resolution must not treat P2P buy and sell prices as
interchangeable. A policy defines which side reflects the intended economic action.

**VAL-004 — Executed exchange uses executed facts.** When both delivered and received quantities are
known, the exchange execution rate is derived from them and retained. A market quote may be stored
as comparison evidence, but it does not replace the executed rate.

**VAL-005 — As-of resolution.** Valuation for an event uses a quote effective at or before the event
time according to the selected policy. It must not accidentally use a later observation.

**VAL-006 — Freshness is policy.** Each valuation policy defines a maximum acceptable quote age and
an ordered fallback strategy. The resulting transaction records which branch was used.

**VAL-007 — Missing rates fail honestly.** When no allowed quote exists, the command must either
require an explicit manual value, remain unposted, or use a policy-approved estimate that is visibly
marked. It must not invent a rate.

**VAL-008 — Workers only collect evidence.** Scheduled jobs fetch and calculate quotes. They do not
rewrite posted transactions or silently create revaluations.

## Required price distinctions

### Execution price

Used for a real exchange or provider fill. It is derived from what was delivered and received,
including separately recorded fees.

### Historical valuation price

Used to assign a functional amount to an event without a direct exchange, such as valuing a VES
expense in USDT at its effective time.

### Carrying cost

Derived from how the disposed position was acquired and the selected cost-basis method. The last
personal USDT-to-VES sale may affect carrying cost, but it is not automatically the current market
price.

### Current reporting price

Used to answer what a balance is worth at a later `as_of` time. It does not change the historical
transaction.

**VAL-009 — Do not conflate the four prices.** Execution price, historical valuation price, carrying
cost, and current reporting price may be equal, but the model must not assume that they are.

## Valuing a VES expense in a USDT book

The intended automated flow is:

1. Preserve the exact VES quantity spent.
2. Resolve the VES/USDT value at `effective_at` using the book's expense valuation policy.
3. Determine the carrying USDT value removed from the VES position using the approved cost-basis
   method.
4. Post the expense at the policy valuation.
5. Post any difference between carrying value removed and expense value to explicit realized FX
   gain or loss.
6. Retain the quote, policy, and calculation evidence.

This allows the UI to show both the USDT economic value of the expense and the exchange result
without asking the user to enter a rate every time.

## Cost basis

**VAL-010 — Moving weighted-average cost.** Each account holding a non-functional instrument keeps a
cost pool of total native quantity and total functional cost; its average cost is their quotient.
An acquisition adds its native quantity and functional amount to the pool. A disposal removes
`native quantity x current average` as carrying value, leaves the average unchanged, and posts the
difference against the event's valuation to the realized FX gain or loss account. Carrying value is
computed when the transaction is posted and stored in its functional amounts; the running average is
a rebuildable projection, never a source of truth (`LED-011`). A disposal beyond the recorded balance
is allowed, carries the current average, and yields a negative native balance that read models must
surface (`RPT-024`).

**VAL-011 — Backdated acquisitions adjust forward.** A backdated acquisition is posted normally and
the running average is recomputed from its effective time onward, but realized FX results already
posted are never rewritten or reversed (`LIF-003`, `MNY-008`). The resulting carrying-value difference
is posted as an explicit cost-basis adjustment transaction at the correction's recording time,
between the affected asset account and the realized FX gain or loss account, with zero native
quantity ([ADR-004](decisions/ADR-004-cost-basis-and-backdating.md)).

Explicit revaluation and unrealized FX presentation remain deferred. When introduced, a revaluation
changes a pool's functional cost without changing its native quantity.

## Market-source examples

These are conceptual policies, not provider commitments:

| Position or purpose     | Pair      | Possible source/policy              |
| ----------------------- | --------- | ----------------------------------- |
| USDT value in VES       | USDT/VES  | Side-aware Binance P2P aggregate    |
| Official USD value      | USD/VES   | BCV                                 |
| Official EUR value      | EUR/VES   | BCV                                 |
| Physical USD cash value | USD/VES   | Cash-market observation             |
| USDC conversion         | USDC/USDT | Executed Binance fill or spot quote |

**Open — USD cash representation.** Decide whether physical cash and constrained bank USD are the
same USD instrument with account-specific valuation policies or distinct instruments. Use a
decision record because this choice affects transfers, reports, and obligations.

**Open — Binance P2P aggregation.** Define filters, side, sample selection, outlier handling,
minimum liquidity, and freshness before the rate is accepted for automatic posting.
