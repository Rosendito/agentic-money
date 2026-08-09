# ADR-004: Moving weighted-average cost basis and backdated acquisitions

## Status

Accepted (2026-08-08, product decision by the book owner).

Amended (2026-08-08, product decision by the book owner): disposals must be fully funded. The
original decision allowed disposals beyond the recorded native balance, negative native asset
balances, negative cost pools, and average preservation through a negative pool, on the assumption
that unrecorded acquisitions routinely finance already-posted disposals. That assumption does not
match the real workflow, which is funded first: funds exist in a savings asset (for example USDT),
a funding or exchange operation is registered first (for example a Binance P2P sale posting
outgoing USDT and incoming VES), and expenses are registered afterward against the available
balance. A negative asset is never a substitute for an unrecorded acquisition, missing funding
source, credit, or overdraft. The moving weighted-average method itself is unchanged.

## Context

Disposing of a non-functional monetary balance realizes an FX result: the difference between what the
disposed quantity carried as functional cost and what the economic event valued it at. Without an
approved cost-basis method, `SCN-EXP-002`, `SCN-FX-001`, `SCN-FX-002`, and `LED-013` are unimplementable
because the carrying value of the disposed quantity is undefined. `docs/04` proposed moving
weighted-average cost and required six mechanics to be defined before implementation.

A second, harder question is temporal. The intended product flow is narrative: the owner records a
day's spending the following day, reconciling it against bank and Binance history with an AI agent.
Backdating is therefore routine, not exceptional. A backdated acquisition changes the running average
that already-posted disposals used, but posted monetary facts are immutable (`LIF-003`, `MNY-008`), so
those realized FX results cannot be rewritten.

## Decision

**Method.** Moving weighted-average functional cost per account. Each account holding a
non-functional instrument has a cost pool: total native quantity and total functional cost. The
average is `total functional cost / total native quantity`.

**Acquisitions.** An incoming posting adds its native quantity and its functional amount to the pool.

**Disposals.** An outgoing posting removes `native quantity x current average` as functional cost.
The difference between that carrying value and the event's valuation is posted explicitly to the
realized FX gain or loss system account. The average itself is unchanged by a disposal.

**Funding (as amended 2026-08-08).** An ordinary disposal requires sufficient posted native balance
in the account, evaluated at the disposal's effective time. Exact exhaustion is valid and leaves
both the pool's native quantity and its functional cost at zero. When sufficient funds have not
been posted, the disposal — expense, transfer, or exchange — must not reach `Posted`; the funding
or acquisition event is registered first and the dependent disposal afterward. Negative native
asset balances, negative cost pools, and averages carried through a negative pool are not
representable states. Credit and overdraft are not part of the first release; if supported later,
they are expressed through explicit liability/credit semantics, never through a negative asset.

**Cost is fixed at posting time.** The disposal's carrying value is computed when the transaction is
posted and stored in its postings' functional amounts. Reading history never recomputes cost. The
running average is a rebuildable projection and never a source of truth (`LED-011`).

**Backdated acquisitions are allowed and adjusted forward.** A backdated acquisition is posted
normally. The running average is recomputed from its effective time onward. Realized FX results
already posted are never rewritten or reversed. The resulting difference in the account's carrying
value is posted as an explicit cost-basis adjustment transaction at the correction's recording time,
between the affected asset account and the realized FX gain or loss account, with zero native
quantity and a non-zero functional amount as permitted by `LED-005`. The adjustment exists to
repair the weighted average of already-valid, fully funded disposals whose cost the late
acquisition changes — never to retroactively justify a balance that was allowed to go negative,
which is not a representable state. A backdated disposal is subject to the funding rule across the
whole timeline: it must not make the account's running native balance negative at its own
effective time or at any later point in the existing effective-time sequence.

**Explicit revaluation** is deferred to a later slice. When introduced, it changes a pool's functional
cost without changing its native quantity, and therefore changes the average.

## Alternatives considered

- **FIFO or specific identification:** rejected. Both require tracking individual lots, which serves
  capital-gains lot reporting. `docs/01` lists tax lots and investment accounting as non-goals, and
  foreign cash held for spending has no lot identity for this product.
- **Rejecting backdated acquisitions that precede a posted disposal:** rejected. It is always correct
  and trivially simple, but it makes the intended next-day narrative workflow unusable.
- **Reversing and reposting every affected disposal when a backdated acquisition arrives:** rejected
  for the first release, not permanently. It is the most accurate option and reuses the existing
  correction mechanism, but one backdated purchase can cascade into many reversals. It may later be
  offered as an explicit user-requested operation, never as automatic behavior.
- **Recomputing carrying values on read instead of storing them:** rejected. It would make reported
  history change retroactively, contradicting `MNY-008` and `SCP-011`.

## Consequences

- Realized FX results become computable, unblocking cross-instrument exchanges and VES expenses.
- One running average per account is far cheaper to maintain and explain than a lot queue.
- A backdated acquisition leaves the original period's realized FX result as it was posted, and the
  correction appears as a dated adjustment in a later period. The error is visible rather than hidden.
- The cost-basis adjustment is a distinct transaction shape that reporting must classify, so that it
  is not read as ordinary spending or income.
- Negative native asset balances are unrepresentable. Missing funding surfaces as a rejected
  command at registration time, and gaps against external evidence are explained by the future
  reconciliation slice (`LIF-021`), not by overdraft.
- Registration order matters and is intended product behavior: the funding operation is recorded
  before the spending that depends on it, matching the funded-first workflow.
- Explicit revaluation and unrealized FX presentation remain unspecified.

## Affected rules and scenarios

- Resolves the cost-basis pending record and known open decision 1 in `docs/README.md`.
- Adds `VAL-010` (moving weighted-average cost) and `VAL-011` (backdated acquisitions adjust forward).
- Reinforces `LED-005`, `LED-011`, `LED-013`, `LIF-003`, `LIF-014`, `MNY-008`, `SCP-011` (the
  original `RPT-024` citation for surfaced negative balances no longer applies after the
  amendment).
- Required by `SCN-EXP-002`, `SCN-FX-001`, `SCN-FX-002`, `SCN-DEBT-002`, `SCN-LOAN-001`.
- Answers the deferred scenario extensions for insufficient-funds rejection (as amended) and
  backdated acquisition before a later disposal. Explicit revaluation and unrealized FX
  presentation remain deferred.
- The amendment strengthens `ACC-006` and rewrites `VAL-010`/`VAL-011` accordingly.

## Validation notes

- A feature test must reproduce `SCN-EXP-002` from two acquisitions at different rates and prove the
  realized FX result matches the weighted average, not the first or last acquisition rate.
- A feature test must prove that a backdated acquisition posts an explicit adjustment, changes no
  existing posting, and leaves the account's carrying balance correct afterward.
- A feature test must prove a disposal exceeding the available posted balance at its effective
  time is rejected and writes nothing; a companion test must prove exact exhaustion posts and
  leaves the pool at zero native quantity and zero functional cost.
- A feature test must prove a backdated disposal is rejected when it would make the running
  balance negative at any later point in the effective-time sequence.
- A test must prove the running average projection can be rebuilt from posted postings alone.
