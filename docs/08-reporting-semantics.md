# Reporting Semantics

## Status

Rules are **Locked** unless marked Proposed or Open.

## General contract

**RPT-001 — Every monetary result declares its basis.** A report or API field must identify whether
it contains native quantity, historical functional carrying value, or current market value. A field
named only `amount` or `balance` is insufficient when more than one interpretation is possible.

**RPT-002 — Reports are derived.** Authoritative reports derive from posted journal transactions,
postings, accounts, obligations, and quote evidence. Caches and projections must be rebuildable.

**RPT-003 — As-of is explicit.** Balance-sheet and valuation reports accept an `as_of` time. Period
reports accept a half-open effective-time range `[start, end)` unless a specific contract says
otherwise.

**RPT-004 — Reproducible valuation.** A market-valued result identifies its quote policy, effective
quote time, source, side, and whether a fallback or estimate was used.

## Account balances

### Native balance

**RPT-005 — Native balance.** Sum native quantities for posted postings affecting the account up to
`as_of`. Present it in the account instrument.

Example:

```text
Banco Mercantil / VES: 44,400 VES
Binance / USDT: 250 USDT
```

### Functional carrying balance

**RPT-006 — Carrying balance.** Present the historical USDT book value associated with the native
position under the approved cost-basis and revaluation policy. Do not substitute the latest market
price.

### Current market value

**RPT-007 — Current value.** Value the native balance at `as_of` under an explicitly selected quote
policy. This is a computed view and does not rewrite carrying value.

The dashboard may show all three:

```text
Banco Mercantil
Native balance:       44,400 VES
USDT carrying value:  52.23 USDT
USDT current value:   50.00 USDT
Unrealized difference: -2.23 USDT
```

## Income statement

**RPT-008 — Functional amounts only.** Income, expenses, fees, and realized FX results are aggregated
using functional amounts in USDT. Never add native USD, EUR, VES, USDC, and USDT quantities directly.

**RPT-009 — Sign normalization is presentational.** Income accounts normally carry negative signed
postings and expense accounts positive signed postings. Reports display positive income and expense
totals while preserving canonical signs in query/domain results where appropriate.

**RPT-010 — Net income.** For a period:

```text
net income = displayed income + displayed realized gains
             - displayed expenses - displayed realized losses
```

Classification determines whether fees and FX results are included in operating, financial, or
other sections. That presentation choice must not change ledger postings.

## Spending reports

**RPT-011 — Spending follows expense postings.** Category spending comes from categorized expense
postings at their functional expense value, not from the absolute value of whichever asset posting
looks largest.

**RPT-012 — FX and fees remain separately identifiable.** A VES purchase may produce Food expense,
realized FX loss, and a payment fee in one transaction. Default category spending includes the Food
expense and reports FX/fees separately unless the user requests total economic cost.

**RPT-013 — Refunds are contra-expense.** Refund postings reduce net category spending while reports
may still expose gross purchases and refunds.

## Cashflow

**RPT-014 — Cashflow is not income statement.** Cashflow derives from changes in selected liquid
asset accounts and classifies the related economic event.

**RPT-015 — Internal transfers are excluded from net cashflow.** Moving USDT between the user's own
wallets changes location, not total liquidity.

**RPT-016 — Currency exchanges are separate.** Exchanging USDT for VES changes liquidity composition.
It is not income or spending. Cashflow views may show it in a dedicated exchange section.

## Net worth

**RPT-017 — Signed net assets.** Historical carrying net worth is the signed sum of asset and
liability carrying balances:

```text
carrying net worth = asset carrying balances + liability carrying balances
```

Liabilities have negative normal balances. Do not subtract a signed liability balance a second time.

**RPT-018 — Current net worth.** Current net worth uses current market values for monetary assets and
the applicable current settlement value for liabilities. It must be labeled as market-valued and
include quote freshness/evidence.

**RPT-019 — Income, expense, and equity are not double-counted.** Net worth is derived from assets
and liabilities. Period performance and equity reconciliation explain its movement but are not added
again to the net-worth total.

## Obligations

**RPT-020 — Native outstanding first.** Obligations display outstanding quantity in the denomination
instrument before any functional or settlement estimate.

**RPT-021 — Carrying and settlement values differ.** An obligation view may show:

- native outstanding quantity;
- USDT carrying value;
- estimated current USDT settlement value;
- accrued or realized differences;
- settlement history.

The current estimate must not replace the native outstanding amount.

**RPT-022 — Counterparty-focused grouping.** User-facing debt reports group obligations by
counterparty and agreement. Internal account identifiers are drill-down evidence, not the main UX.

## Quote and data-quality indicators

**RPT-023 — Staleness is visible.** Current-valued dashboards show when a quote is stale, estimated,
manual, or unavailable.

**RPT-024 — Partial data is not silently zero.** Missing valuation evidence yields an unavailable or
partial result with diagnostics. It must not present an unknown value as zero.

**RPT-025 — Native totals remain available.** Failure to value an instrument must not hide or alter
its native account balance.

## Minimum read-model contracts

The ledger foundation should eventually expose read services for:

- account native and carrying balances as of a time;
- account current valuation under a named policy;
- transaction history with expanded postings and evidence;
- income statement for an effective-time range;
- categorized spending with optional total economic cost;
- liquidity composition by instrument and container;
- carrying and market-valued net worth;
- obligation outstanding and settlement history;
- quote freshness and valuation diagnostics;
- reconciliation and unposted external-event status.

These are semantic contracts, not instructions to create one large reporting task.

## Open decisions

- Default dashboard valuation policy for each instrument/container combination.
- Whether unrealized FX remains report-only initially or explicit revaluation transactions are
  supported in the first release.
- Presentation classification of realized FX and provider fees.
- Whether category spending defaults to transaction-time market value, carrying cost consumed, or
  displays both. The current direction is market-valued expense with FX difference separate.
