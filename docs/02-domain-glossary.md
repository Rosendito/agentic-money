# Domain Glossary

## Status

The definitions in this document are **Locked** unless an entry is marked Proposed.

## Core ledger terms

### Book

An independent accounting ledger with one functional instrument and its own accounts,
transactions, posting sequence, and policies. The initial personal book uses USDT.

### Journal transaction

The immutable accounting representation of one economic event. It contains two or more postings
and metadata such as effective time, description, source, and idempotency information.

### Posting

One signed effect on one ledger account. A posting records a native quantity and a functional
amount. It may also reference the valuation or cost-basis evidence that produced its functional
amount.

### Account

A ledger position with one account type and one immutable native instrument. An account answers
what is owned, owed, earned, spent, or held as equity. It is not a person, category, exchange rate,
or API connection.

### Account type

The accounting nature of an account:

- **Asset**: value controlled by the book owner.
- **Liability**: an obligation owed by the book owner.
- **Equity**: the residual or owner-originated source of value.
- **Income**: value earned during a period.
- **Expense**: value consumed during a period.

### System account

An account created and managed by the application to complete legitimate accounting entries, such
as opening equity, income control, expense control, realized FX gain/loss, fees, or rounding. It is
normally hidden from day-to-day account selectors.

### Category

An analytical classification attached to relevant postings or transaction purposes, such as Food,
Transport, or Salary. Categories do not replace income or expense accounts and do not hold monetary
balances independently.

## Money and location terms

### Instrument

A unit in which a balance, price, or obligation can be expressed. Examples include USDT, USDC, USD,
EUR, and VES. Instrument codes are not restricted to three characters.

### Native quantity

The signed quantity expressed in an account's instrument. Native quantities from different
instruments are never added together to determine whether a transaction balances.

### Functional instrument

The single instrument used to assign a comparable historical book value to all postings in a book.
The initial functional instrument is USDT.

### Functional amount

The signed historical book value of a posting in the book's functional instrument. Functional
amounts, not mixed native quantities, balance a journal transaction.

### Container

A user-facing place or custodian that groups accounts, such as Binance, Banco Mercantil, PayPal, or
a physical wallet. A container can expose several internal accounts, one per instrument.

### Fungible

Two positions are fungible when their units can be substituted at par without a material economic
restriction. Whether USD cash and a constrained USD bank balance are fungible is an explicit domain
decision, not an assumption based only on the `USD` label.

## Prices and valuation terms

### Quote

An observed or calculated price for a pair of instruments, with an explicit convention, source,
side, effective time, retrieval time, and calculation metadata.

### Pair convention

`BASE/QUOTE = rate` means one unit of BASE equals `rate` units of QUOTE. For example,
`USDT/VES = 850` means `1 USDT = 850 VES`.

### Quote source

The origin of a quote, such as BCV, Binance P2P, a cash-market provider, a manual observation, or an
executed trade.

### Quote side

The economic direction represented by a quote. Buy, sell, bid, ask, and mid are not interchangeable.
For example, valuing a likely sale of USDT for VES should use a policy appropriate to that direction.

### Execution rate

The rate implied by quantities actually exchanged. It is a transaction fact. An executed rate takes
precedence over a generic market quote for the exchange itself.

### Valuation rate

The rate selected by a documented policy to assign a functional or current value when there is no
executed exchange for that event.

### Carrying value

The historical functional value currently associated with a position before a disposal,
settlement, or explicit revaluation.

### Current market value

The value of a native balance under a selected quote policy at an `as_of` time. It is a report result
and does not rewrite historical postings.

### Realized FX result

An exchange gain or loss recognized when a position is spent, exchanged, or settled for a value
different from its carrying value.

### Unrealized FX result

The difference between carrying value and current market value while the native position is still
held. It remains a report result unless an explicit revaluation transaction is posted.

## Obligations

### Counterparty

A person or organization involved in an obligation or transaction, such as Anthony. A counterparty
is not itself a ledger account.

### Obligation

A specific amount owed or receivable under defined terms. It has a direction, denomination,
counterparty, lifecycle, and settlement rules, and is linked to an internal liability or receivable
account.

### Denomination instrument

The instrument that defines the outstanding native quantity of an obligation. It is independent of
the instrument used to settle the obligation.

### Settlement instrument

The asset delivered or received to reduce an obligation. An obligation denominated in USD may be
settled with USDT when its terms and a valuation policy allow it.

### Indexed obligation

An obligation whose settlement amount depends on a documented index or quote source rather than a
fixed quantity of its settlement instrument.

## Lifecycle and evidence

### Effective time

When the economic event occurred and should affect balances and reports.

### Recorded time

When the application first stored the event.

### Posted transaction

A validated journal transaction that affects the ledger. Its financial fields and postings are
immutable.

### Reversal

A new posted transaction that negates every posting of an earlier transaction and links back to it.
A corrected event is represented by a reversal followed by a new transaction.

### Idempotency key

A stable key within a defined scope that prevents the same command or external event from producing
duplicate financial effects.

### External event

A provider-originated fact such as a Binance fill, P2P order update, bank payment, or imported file
row. External events may be pending or duplicated and are not journal transactions until mapped and
posted.

### Reconciliation

Evidence that a ledger event matches an external settlement or statement item. Reconciliation adds
links and status; it does not mutate the original financial postings.
