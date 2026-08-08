# Ledger Model

## Status

Unless marked otherwise, the rules in this document are **Locked**.

## Book and transaction model

**LED-001 — Functional balance.** Every posted journal transaction must contain at least two
postings and the exact sum of their functional amounts must be zero.

**LED-002 — Native quantities do not balance across instruments.** A transaction exchanging
`100 USDT` for `85,000 VES` is not unbalanced because `100 - 85,000` is not zero. Only its USDT
functional amounts are compared for journal balance.

**LED-003 — One posting, one account.** Every posting references exactly one account and uses that
account's immutable native instrument.

**LED-004 — Dual monetary representation.** Every financial posting records:

- a signed native quantity in the account instrument; and
- a signed functional amount in the book functional instrument.

The domain may also retain valuation evidence, cost-basis allocations, memo, category, or other
dimensions, but those never replace the two monetary values.

**LED-005 — Explicit zero policy.** Posted financial postings may not have both native quantity and
functional amount equal to zero. A zero-native posting with a non-zero functional amount is allowed
only for a documented valuation, FX, fee, or rounding mechanism and should normally use a system
account denominated in the functional instrument.

**LED-006 — Facts are not inferred merely to balance.** The posting engine may calculate amounts
from explicit inputs and policies. It may not assign the remaining balance to an unexplained posting.

**LED-007 — Arbitrary posting count.** A transaction may contain two or more postings. Domain
actions must add explicit postings for fees, FX gain/loss, discounts, withholding, or rounding when
the economic event requires them.

## Sign convention

The canonical convention is traditional debit-positive journal notation:

| Account type | Increase | Decrease | Normal balance |
| ------------ | -------- | -------- | -------------- |
| Asset        | Positive | Negative | Positive       |
| Expense      | Positive | Negative | Positive       |
| Liability    | Negative | Positive | Negative       |
| Equity       | Negative | Positive | Negative       |
| Income       | Negative | Positive | Negative       |

**LED-008 — Signs are domain facts.** Storage, value objects, queries, and tests use the canonical
sign convention. UI labels may say “enters”, “leaves”, “owed”, or “available” instead of exposing
debit/credit terminology.

## Account roles

### User-visible balance accounts

These represent positions the user recognizes, such as:

- Banco Mercantil / VES;
- Binance / USDT;
- Binance / USDC;
- Physical wallet / USD;
- a credit card liability;
- an obligation-specific payable or receivable.

### System-managed nominal and equity accounts

The book must be able to use system accounts for:

- opening equity;
- income control;
- expense control;
- realized FX gain;
- realized FX loss;
- fees;
- rounding differences;
- migration or correction suspense, if explicitly enabled for controlled operations.

**LED-009 — No fake sources or sinks.** System accounts are real accounting counterparties, not
placeholder labels. Their use must follow a defined action or policy.

**LED-010 — Categories are dimensions.** Income and expense categories classify relevant postings.
They do not substitute for balanced income or expense accounts. A category is a book-scoped record
referenced from a posting, never a nominal account, and only the postings the classification applies
to carry it: an expense transaction that also produces a fee and a realized FX result categorizes the
expense posting alone ([ADR-003](decisions/ADR-003-expense-classification-boundary.md)).

**LED-016 — Classification boundary.** A classification dimension belongs to the ledger only when an
external auditor would require it to read the financial statements. Nature of expense qualifies;
personal analysis axes such as who the spending benefited do not. Those are satellite features that
store their own classification, written by an application use case inside the posting transaction, and
they never add columns to journal or posting tables
([ADR-003](decisions/ADR-003-expense-classification-boundary.md)).

## Canonical posting shapes

### Income received

```text
Asset account                     +native quantity / +functional amount
Income system account             -functional quantity / -functional amount
```

### Expense paid

```text
Expense system account            +functional quantity / +functional amount
Asset account                     -native quantity / -functional amount
Optional FX gain/loss             explicit difference
```

### Same-instrument transfer

```text
Destination asset                 +native quantity / +functional amount
Source asset                      -native quantity / -functional amount
Optional fee expense              +fee functional amount
Fee-paying asset                  -fee native quantity / -fee functional amount
```

### Cross-instrument exchange

```text
Asset received                    +received native quantity / +functional value
Asset delivered                   -delivered native quantity / -carrying value
FX gain/loss or fee               explicit difference when required
```

### Borrowing

```text
Asset received                    +native quantity / +functional amount
Liability obligation              -denominated quantity / -functional amount
```

### Debt settlement

```text
Liability obligation              +denominated quantity / +carrying value removed
Settlement asset                  -settlement quantity / -functional value delivered
FX gain/loss or fee               explicit difference
```

## Derived balances

**LED-011 — No mutable running balances.** Authoritative account balances are derived from all
posted postings up to an `as_of` time, including original postings and any later posted reversal
postings. Cached projections may exist for performance, but must be rebuildable and must never
become the source of truth.

**LED-012 — Native account balance.** An account's native balance is the sum of its posted native
quantities.

**LED-013 — Carrying balance.** An account's functional carrying balance is derived from the
functional amounts of its posted postings and any explicit revaluations, subject to the approved
cost-basis policy.

**LED-014 — Reversal-safe queries.** Queries must treat a reversal as a new financial event. They
must not delete or rewrite the original event to make reports appear clean.

## Validation responsibilities

The database should enforce local facts where possible:

- account, transaction, and posting ownership within the same book;
- valid instrument references;
- immutable instrument on an account after posting;
- unique idempotency/external identifiers in their scopes;
- valid lifecycle states and reversal links;
- decimal range and scale constraints.

The domain posting service must enforce aggregate rules:

- permitted account roles for the requested action;
- at least two postings;
- exact functional balance;
- correct sign and amount relationships;
- valuation evidence and policy selection;
- obligation limits and settlement rules;
- posting atomicity.

**LED-015 — Single posting boundary.** All interfaces must publish transactions through the same
posting boundary. Direct inserts into journal or posting tables are not an application feature.

## Open implementation decisions

- Whether native and functional amounts use one high-scale decimal type or per-instrument scaled
  integer value objects backed by high-precision database decimals.

Resolved: nominal system accounts are one set per book and categories are always dimensions
([ADR-003](decisions/ADR-003-expense-classification-boundary.md)).
