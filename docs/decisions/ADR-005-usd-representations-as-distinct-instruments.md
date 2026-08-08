# ADR-005: USD representations are distinct instruments

## Status

Accepted (2026-08-08, product decision by the book owner).

## Context

In Venezuela the several representations of the US dollar do not trade at par, and the gap is
structural rather than transient:

- **Physical cash USD** is valued by the street (cash) market.
- **Bank-held electronic USD** is valued at the official BCV rate (https://www.bcv.org.ve/), which
  is government-regulated and typically the lowest of the dollar prices.
- Stablecoins such as USDT and USDC are already separate instruments whose value depends on their
  own markets (for example Binance P2P).

`MNY-005` presumes par substitution only when no material restriction prevents it, and requires
non-fungible USD positions to be distinguished through an approved instrument or position policy.
Moving value between cash USD and bank USD changes real wealth, so treating them as one instrument
would hide genuine exchange results inside "transfers". `docs/README.md` tracked this as known open
decision 1.

## Decision

- Each non-fungible USD representation is its own instrument. The initial codes are `USD.CASH`
  (physical cash) and `USD.BCV` (bank-held electronic USD valued at the official rate). Long codes
  are already supported by `MNY-001`.
- Each instrument carries its own valuation policy: `USD.CASH` values against cash-market
  observations; `USD.BCV` values against the BCV official rate.
- Moving value between USD representations is a cross-instrument exchange with an explicit realized
  FX result, exactly like a VES-to-USDT exchange. It is never a par transfer.
- Instruments are data, not code. A future representation (for example Zelle balances or another
  stablecoin) is added as a new instrument row with its own valuation policy, without changing the
  posting engine.
- Obligations denominate in one specific instrument. "Owe 100 USD" must state which representation,
  consistent with fixed-denomination settlement in the first release.

## Alternatives considered

- **One `USD` instrument with per-account valuation policies:** rejected. Valuation policy would
  explain reporting differences, but transfers between cash and bank accounts would still post at
  par, silently absorbing a real gain or loss the moment value moves between representations.
- **One instrument plus a position policy distinguishing constrained balances:** rejected as more
  machinery for the same outcome; the instrument boundary already expresses non-fungibility and
  reuses the existing exchange semantics.

## Consequences

- Reports show one line per representation. Presentation layers may group them as a "USD family"
  for display without merging balances.
- Every obligation, transfer form, and import mapping must name the concrete instrument; the
  ambiguity previously resolved mentally becomes explicit data.
- Migrating from one merged `USD` to split instruments later would have required rewriting posted
  immutable data; deciding the split before the first posting avoids that entirely.

## Affected rules and scenarios

- Resolves known open decision 1 in `docs/README.md` and the "USD cash versus constrained USD bank"
  pending record.
- Applies `MNY-005`; relies on `MNY-001`, `MNY-004`.
- Updates the market-source examples and the open-decision block in
  `docs/04-money-valuations-and-rates.md` and the fungibility entry in
  `docs/02-domain-glossary.md`.

## Validation notes

- Seed or factory data for instruments must include `USD.CASH` and `USD.BCV` as distinct rows once
  instruments are implemented.
- A future exchange-slice test must prove that moving value between `USD.CASH` and `USD.BCV`
  requires an exchange posting with a realized FX result and cannot be posted as a par transfer.
