# Canonical Transaction Scenarios

## Status and use

These scenarios are **Locked examples** of the expected accounting shape. They are not final
database schemas or service APIs.

Before implementing a scenario, convert it into executable acceptance tests using the approved
decimal and cost-basis policies. If a later decision changes a number or posting shape, update the
scenario and cite the decision record.

## Shared assumptions

Unless a scenario says otherwise:

- the book functional instrument is USDT;
- debit-positive sign convention applies;
- all shown transactions are posted atomically;
- system income, expense, equity, FX, and fee accounts are denominated in USDT;
- no rounding is hidden;
- categories and counterparties are dimensions omitted from the posting table when not relevant.

## Scenario template

Every future scenario should specify:

1. User intent.
2. Preconditions and evidence.
3. Native facts supplied by the user or provider.
4. Valuation/cost policy applied.
5. Expected postings.
6. Resulting native balances or outstanding quantity.
7. Reporting effects.
8. Rejection and edge cases.

## Basic events

### SCN-OPEN-001 — Opening USDT balance

**Intent:** Start tracking an existing `500 USDT` Binance balance.

| Account              | Native quantity | Functional amount |
| -------------------- | --------------: | ----------------: |
| Binance / USDT asset |       +500 USDT |         +500 USDT |
| Opening equity       |       -500 USDT |         -500 USDT |

Expected effects:

- Binance native balance increases by 500 USDT.
- No income is reported.
- The event is identifiable as an opening balance, not salary or unexplained income.

### SCN-INC-001 — Salary received in USDT

**Intent:** Register a `100 USDT` salary payment deposited in Binance.

| Account              | Native quantity | Functional amount |
| -------------------- | --------------: | ----------------: |
| Binance / USDT asset |       +100 USDT |         +100 USDT |
| Income control       |       -100 USDT |         -100 USDT |

Expected effects:

- Native liquidity increases by 100 USDT.
- Income reporting shows positive 100 USDT under the Salary category.

### SCN-EXP-001 — VES expense with no FX difference

**Intent:** Spend `8,500 VES` from Banco Mercantil on Food.

Evidence and policy:

- Historical valuation: `1 USDT = 850 VES`.
- The disposed VES carrying cost is also `10 USDT`.

| Account                     | Native quantity | Functional amount |
| --------------------------- | --------------: | ----------------: |
| Expense control             |        +10 USDT |          +10 USDT |
| Banco Mercantil / VES asset |      -8,500 VES |          -10 USDT |

Expected effects:

- VES balance decreases by exactly 8,500 VES.
- Food spending increases by 10 USDT.
- No FX result is recognized.

### SCN-EXP-002 — VES expense with realized FX loss

**Intent:** Spend `8,500 VES` from Banco Mercantil on Food.

Evidence and policy:

- Historical expense valuation: `1 USDT = 850 VES`, so the expense value is `10 USDT`.
- Approved cost-basis calculation says the disposed VES carries `10.5 USDT`.

| Account                     | Native quantity | Functional amount |
| --------------------------- | --------------: | ----------------: |
| Expense control             |        +10 USDT |          +10 USDT |
| Realized FX loss            |       +0.5 USDT |         +0.5 USDT |
| Banco Mercantil / VES asset |      -8,500 VES |        -10.5 USDT |

Expected effects:

- Food spending is 10 USDT under the transaction-time valuation policy.
- A separate 0.5 USDT realized FX loss is visible.
- The ledger does not create a second user-facing transaction to explain the difference.

## Transfers and exchanges

### SCN-TRF-001 — Same-instrument transfer

**Intent:** Move `50 USDT` from Binance to another USDT wallet with no fee.

| Account                         | Native quantity | Functional amount |
| ------------------------------- | --------------: | ----------------: |
| Destination wallet / USDT asset |        +50 USDT |          +50 USDT |
| Binance / USDT asset            |        -50 USDT |          -50 USDT |

Expected effects:

- Total USDT liquidity and net worth do not change.
- Cashflow and income/expense reports exclude the transfer.

### SCN-FX-001 — Exchange USDT for VES

**Intent:** Deliver `100 USDT` and receive `85,000 VES` in Banco Mercantil.

Execution evidence:

- Executed rate implied by quantities: `1 USDT = 850 VES`.

| Account                     | Native quantity | Functional amount |
| --------------------------- | --------------: | ----------------: |
| Banco Mercantil / VES asset |     +85,000 VES |         +100 USDT |
| Binance / USDT asset        |       -100 USDT |         -100 USDT |

Expected effects:

- Native positions change exactly by the executed quantities.
- No income or expense is created.
- The VES position acquires 100 USDT of functional carrying value.
- The transaction retains the executed price and any comparison quote separately.

### SCN-FX-002 — USDC to USDT conversion with fee/spread

**Intent:** Deliver `100 USDC` and receive `99.8 USDT` after a `0.2 USDT` economic cost.

Assumption:

- The delivered USDC carrying value is 100 USDT.

| Account              | Native quantity | Functional amount |
| -------------------- | --------------: | ----------------: |
| Binance / USDT asset |      +99.8 USDT |        +99.8 USDT |
| Fee expense          |       +0.2 USDT |         +0.2 USDT |
| Binance / USDC asset |       -100 USDC |         -100 USDT |

Expected effects:

- The fee/spread is explicit and does not disappear into the received asset value.
- Provider evidence determines whether the 0.2 is a named commission, execution spread, or another
  result classification.

### SCN-FX-003 — P2P sale readiness

**Intent:** A settled Binance P2P operation delivers `100 USDT` and deposits `84,500 VES` in a bank.

| Account              | Native quantity | Functional amount |
| -------------------- | --------------: | ----------------: |
| Bank / VES asset     |     +84,500 VES |         +100 USDT |
| Binance / USDT asset |       -100 USDT |         -100 USDT |

Expected effects:

- Executed rate is `1 USDT = 845 VES`.
- A created or pending P2P order does not post this event until the settlement policy is satisfied.
- The external order, payment, and bank credit can be reconciled without changing the postings.
- If the bank receives less because of a fee, the fee is a separate posting.

## Obligations

### SCN-DEBT-001 — Borrow and repay USDT

**Origination intent:** Borrow `100 USDT` from Anthony into Binance.

| Account                          | Native quantity | Functional amount |
| -------------------------------- | --------------: | ----------------: |
| Binance / USDT asset             |       +100 USDT |         +100 USDT |
| Anthony payable / USDT liability |       -100 USDT |         -100 USDT |

**Settlement intent:** Repay exactly `100 USDT` from Binance.

| Account                          | Native quantity | Functional amount |
| -------------------------------- | --------------: | ----------------: |
| Anthony payable / USDT liability |       +100 USDT |         +100 USDT |
| Binance / USDT asset             |       -100 USDT |         -100 USDT |

Expected effects:

- Origination is not income and repayment is not expense.
- Outstanding payable returns to zero without becoming a positive fictitious balance.

### SCN-DEBT-002 — USD obligation settled with USDT

**Origination intent:** Borrow `100 USD cash` from Anthony.

Origination valuation: `1 USD = 1.04 USDT`.

| Account                         | Native quantity | Functional amount |
| ------------------------------- | --------------: | ----------------: |
| Physical wallet / USD asset     |        +100 USD |         +104 USDT |
| Anthony payable / USD liability |        -100 USD |         -104 USDT |

**Later settlement intent:** Extinguish the full `100 USD` obligation by delivering
`104.470588 USDT` under the agreed settlement rate.

| Account                         |  Native quantity | Functional amount |
| ------------------------------- | ---------------: | ----------------: |
| Anthony payable / USD liability |         +100 USD |         +104 USDT |
| Realized FX loss                |   +0.470588 USDT |    +0.470588 USDT |
| Binance / USDT asset            | -104.470588 USDT |  -104.470588 USDT |

Expected effects:

- The USD liability becomes exactly zero.
- The system does not create an “Anthony USDT” obligation merely because USDT was used to pay.
- The additional USDT economic cost is explicit as FX loss.

### SCN-DEBT-003 — Partial cross-instrument settlement

**Intent:** From the same `100 USD` obligation, extinguish `40 USD` using an agreed quantity of
USDT.

Required facts:

- exact USD obligation quantity extinguished;
- exact USDT quantity delivered;
- carrying value of the 40 USD liability portion;
- settlement quote or agreement evidence.

Expected effects:

- Remaining obligation is exactly `60 USD`.
- The payment cannot be recorded using only the USDT amount.
- Any difference is explicit in the same journal transaction.

### SCN-LOAN-001 — Lend USDT and collect VES

**Origination intent:** Lend `50 USDT` to Anthony.

| Account                         | Native quantity | Functional amount |
| ------------------------------- | --------------: | ----------------: |
| Anthony receivable / USDT asset |        +50 USDT |          +50 USDT |
| Binance / USDT asset            |        -50 USDT |          -50 USDT |

**Collection intent:** Extinguish the `50 USDT` receivable by receiving `42,500 VES` at the agreed
rate `1 USDT = 850 VES`.

| Account                         | Native quantity | Functional amount |
| ------------------------------- | --------------: | ----------------: |
| Banco Mercantil / VES asset     |     +42,500 VES |          +50 USDT |
| Anthony receivable / USDT asset |        -50 USDT |          -50 USDT |

Expected effects:

- Lending and collection are not expense or income unless interest, forgiveness, fees, or an FX
  result is explicitly present.

## Corrections and exceptional flows

### SCN-REF-001 — Expense refund

**Original event:** `10 USDT` expense paid from Binance.

```text
Expense control                    +10 USDT
Binance / USDT asset               -10 USDT
```

**Refund event:** Full `10 USDT` returned.

```text
Binance / USDT asset               +10 USDT
Expense control                    -10 USDT
```

Expected effects:

- The refund links to the original expense.
- Reporting can show gross expense and refund while net expense is zero.

### SCN-REV-001 — Reverse an incorrect posted transaction

Given any posted transaction, create a new transaction with:

- the same accounts and native instruments;
- exact opposite native quantities;
- exact opposite functional amounts;
- a link to the original and an explicit reason.

Expected effects:

- Combined financial effect is zero.
- Original and reversal remain visible.
- A corrected replacement uses a separate idempotency key and transaction.

### SCN-RATE-001 — Missing or stale valuation quote

**Intent:** Register a historical VES expense for which no policy-compliant quote is available.

Expected behavior:

- No posted transaction is created with an invented functional amount.
- The command returns a structured missing/stale-rate result.
- The event may remain draft/pending, request a manual quote, or use an explicitly approved and
  marked estimation fallback.
- Retrying with the same idempotency key after evidence becomes available produces at most one
  posted transaction.

## Required scenario extensions before implementation

The following cases must be added or explicitly deferred when tasks are created:

- negative asset balance/overdraft;
- credit card purchase and payment;
- backdated VES acquisition before a later disposal;
- refund in a different instrument;
- debt forgiveness or negotiated discount;
- quote inversion and cross-rate calculation;
- provider partial fill and multiple fees;
- explicit revaluation and unrealized FX presentation;
- rounding at approved storage scale.
