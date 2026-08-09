# Accounts and Obligations

## Status

Rules are **Locked** unless marked Proposed or Open.

## Containers and accounts

**ACC-001 — Account per position.** A user-visible monetary position is represented internally by
an account scoped to one book, one account type, one container context when relevant, and one native
instrument.

Examples:

```text
Container: Binance
  Asset account: Binance / USDT
  Asset account: Binance / USDC

Container: Banco Mercantil
  Asset account: Banco Mercantil / VES

Container: Physical wallet
  Asset account: Physical wallet / USD
```

**ACC-002 — Containers improve UX, not accounting.** A container groups positions for display and
integration configuration. Postings reference accounts, not a multi-instrument container balance.

**ACC-003 — Account instrument is immutable.** An account's native instrument cannot change after
it has a posting. Moving value to another instrument is an exchange transaction, not an account edit.

**ACC-004 — Account type is stable.** An account with postings cannot switch between asset,
liability, equity, income, or expense. A classification correction uses a controlled migration or
new account, never a casual edit.

**ACC-005 — Archive rather than delete.** Accounts with posted history are archived when no longer
active. Their postings and report history remain available.

**ACC-006 — No negative assets.** Every ordinary action that spends, transfers, or exchanges an
asset must protect each of its outgoing asset accounts from a negative native balance: when
sufficient native funds have not been posted, the command must not reach `Posted`. The check and
the posting are one atomic decision under row-level account locks, so concurrent commands cannot
jointly overspend an asset (the mechanism TASK-008 established). A negative asset is never a
substitute for an unrecorded acquisition, missing funding source, credit, or overdraft; credit and
overdraft are outside the first release and, when introduced, use explicitly defined
liability/credit semantics (ADR-004 as amended 2026-08-08).

**ACC-010 — Availability is effective-time-aware.** The available balance that `ACC-006` protects
is derived from postings already posted whose effective time is at or before the new command's
effective time — an expense must never appear historically before the operation that funded it.
A backdated disposal must additionally leave the account's running native balance non-negative at
every later point in the existing effective-time sequence, not only at its own effective time.
Because availability reads only posted data, the funding event must be registered before any
disposal that depends on it, regardless of their effective-time order.

**ACC-007 — System accounts are book-owned.** System accounts are created by a controlled book
initialization service, have stable roles, and cannot be repurposed, deleted, or selected as ordinary
payment accounts.

## User-facing versus internal representation

The user should select recognizable sources and destinations:

- “Mercantil VES”;
- “Binance USDT”;
- “Cash USD”.

The user should not have to select:

- external expense control;
- external income control;
- FX gain or loss;
- opening equity;
- fee or rounding accounts.

**ACC-008 — Actions derive counterpart postings.** Application actions select the required system
accounts from the book configuration and produce a complete preview before posting.

## Counterparties

**ACC-009 — Counterparty is not account identity.** Anthony is stored as a counterparty. The user's
ledger account represents an amount owed or receivable, while the counterparty provides human and
contractual context.

A transaction may reference a counterparty without creating a new account, for example an ordinary
expense paid to a merchant. An obligation creates or links a dedicated subledger position because
it has an outstanding balance and lifecycle.

## Obligations

**OBL-001 — One record per economic obligation.** A loan or debt is represented by an obligation
with a counterparty, direction, denomination instrument, original terms, outstanding quantity, and
status.

**OBL-002 — Direction is explicit.** An obligation is either:

- **payable**: the book owner owes value, linked to a liability account; or
- **receivable**: the book owner is owed value, linked to an asset account.

**OBL-003 — Denomination is independent of settlement.** The obligation's outstanding native
quantity is expressed in its denomination instrument. Payment or collection may use another
instrument when the terms and valuation policy permit it.

**OBL-004 — Separate obligations when economic promises differ.** “Anthony USD” and “Anthony USDT”
are separate obligations only if there are genuinely separate promises denominated in USD and USDT.
Do not create one obligation per possible settlement instrument.

**OBL-005 — Settlement reduces native obligation quantity.** A settlement must state how much of the
denominated obligation is extinguished, independently of the native settlement quantity delivered
or received.

**OBL-006 — Cross-instrument differences are explicit.** When carrying value removed from an
obligation differs from the functional value of the settlement asset, the difference is posted to
the applicable FX gain/loss, fee, discount, forgiveness, or other explicitly selected account.

**OBL-007 — No silent over-settlement.** A normal settlement may not reduce an obligation below zero.
Overpayment must be rejected or represented as a separate receivable/refund event.

**OBL-008 — Partial settlement is first-class.** An obligation can be settled in multiple events.
Each event preserves the denominated quantity extinguished, settlement quantity, rates, and
remaining balance.

**OBL-009 — Terms are historical evidence.** Changes to obligation terms are versioned or appended.
They do not retroactively rewrite already posted originations or settlements.

## Fixed and indexed obligations

### Fixed-denomination obligation

Example: owe Anthony exactly `100 USDT`. The outstanding native balance is 100 USDT regardless of
the USDT/VES quote.

### Cross-settled fixed obligation

Example: owe Anthony `100 USD` but settle using USDT. The action records how much USD obligation is
extinguished and how much USDT is delivered under an explicit quote or agreed execution rate.

### Indexed obligation

Example: owe an amount whose settlement follows the value of `100 USD cash` under an agreed cash
market at settlement time. The index, source, side, timing, and fallback policy are part of the
terms.

**Proposed — First-release boundary.** Support fixed-denomination obligations and cross-instrument
settlement first. Add indexed obligations only after their terms and scenarios are approved.

## Obligation UX

The dashboard should group obligations by counterparty and show each economic agreement, not expose
a flat list of manually named accounts:

```text
Anthony
  Payable: 100 USD — due/open
  Receivable: 40 USDT — partially collected
```

An expandable accounting view may show the internal liability or receivable account and settlement
postings.

## Open decisions

- Whether every obligation gets a dedicated account or a control account plus a mandatory
  obligation dimension. The preferred direction is a dedicated internal account per obligation for
  simple native outstanding balances, hidden behind counterparty-focused UX.
- Which indexed terms are supported initially.
- Whether due dates, schedules, interest, and forgiveness are part of the first obligation model or
  deferred extensions. Principal-only fixed obligations are the preferred initial boundary.
