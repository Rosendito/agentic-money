# Integrity and Lifecycle

## Status

Rules are **Locked** unless marked Proposed or Open.

## Transaction states

The canonical lifecycle distinguishes economic intent from posted accounting effects:

- **Draft**: editable proposal; does not affect balances.
- **Pending**: accepted external or user intent awaiting settlement or required evidence; does not
  affect authoritative balances unless a future policy explicitly introduces pending projections.
- **Posted**: validated immutable journal transaction; affects balances and reports.
- **Reversed**: descriptive condition derived when a posted transaction has a complete posted
  reversal. The original remains posted; the condition must not make queries omit its postings.
- **Cancelled**: draft or pending intent that will not post; never affected balances.

**LIF-001 — Only posted transactions affect authoritative balances.** Draft, pending, and cancelled
records are excluded from ledger balances and accounting statements. A reversed original and its
posted reversal are both included, producing their net accounting effect.

**LIF-002 — Posting is atomic.** Transaction header, postings, valuation evidence links, obligation
effects, and idempotency record become visible as one database transaction or not at all.

## Immutability and corrections

**LIF-003 — Posted financial data is immutable.** Postings, monetary values, effective time,
instrument, accounts, valuation evidence, and obligation allocations cannot be edited after posting.

**LIF-004 — Reversal is a new event.** A reversal contains equal and opposite postings, references
the original transaction, has its own effective and recorded times, and is itself immutable.

**LIF-005 — Correction is reversal plus replacement.** When an event was posted with incorrect
financial facts, the application reverses it and posts a corrected transaction linked through a
correction group.

**LIF-006 — Descriptive annotations are separate.** Non-financial notes or attachments may be added
without altering journal facts. Any editable metadata must be explicitly classified as
non-financial and audited.

**LIF-007 — No destructive deletion.** Posted transactions and accounts with posted history are not
deleted through normal application behavior.

## Idempotency

**LIF-008 — Idempotency is required for posting commands.** Every posting command carries an
idempotency key with a defined scope, including manual UI commands and external imports.

**LIF-009 — Same key, same intent.** Reusing a key with an identical canonical payload returns the
existing result. Reusing it with a materially different payload is a conflict and must not silently
return or overwrite the old transaction.

**LIF-010 — Provider identifiers are independently unique.** External source, account/connection,
event type, and provider event ID form an explicit uniqueness scope. Provider IDs do not replace
application command idempotency.

## Precision and rounding

**LIF-011 — No silent balance tolerance.** A transaction must balance exactly at the functional
storage scale. The posting service may create an explicit rounding posting under an approved rule;
it may not accept an unexplained tolerance such as `0.01` for every instrument.

**LIF-012 — Rounding happens at named boundaries.** Quote calculation, functional conversion,
provider precision, settlement, and display rounding are distinct. Each operation declares its
rounding mode and scale.

**Open — Exact decimal policy.** Before schema implementation, decide:

- database precision and scale for native quantities, functional amounts, and rates;
- value-object precision during intermediate calculations;
- supported rounding modes;
- maximum permitted explicit rounding adjustment;
- display precision versus storage precision.

## Temporal integrity

**LIF-013 — Effective and recorded times are distinct.** Reports use effective time unless their
contract explicitly requests recorded/imported time.

**LIF-014 — Backdated events are explicit.** Backdating is allowed only through a posting use case
that recalculates or invalidates affected cost-basis projections and cached read models. It does not
mutate later journal entries.

**LIF-015 — Quote time cannot travel forward.** A historical valuation may not use a quote that was
effective after the economic event unless an explicit estimation policy records that exception.

## Ownership and boundaries

**LIF-016 — Book isolation.** A journal transaction, posting, account, obligation, category, and
valuation allocation must belong to the same book. Cross-book postings are forbidden.

**LIF-017 — Controlled account creation.** Containers and ordinary accounts may be user-created,
but system and obligation accounts are created through dedicated services that enforce roles and
uniqueness.

**LIF-018 — Domain boundary cannot be bypassed.** UI components, importers, queue workers, console
commands, and future APIs do not write journal postings directly.

## External-event lifecycle readiness

An external order or notification is not automatically a posted financial event.

**LIF-019 — Import facts before mapping.** Provider payload identity, status, quantities, fees, and
timestamps are recorded as external evidence before or atomically with mapping to a journal event.

**LIF-020 — Settlement controls posting.** Binance orders, P2P operations, and bank transfers may
have pending and partially filled states. Provider-specific policy determines when actual financial
postings are created.

**LIF-021 — Reconciliation does not rewrite.** Matching a P2P sale to a VES bank credit links the
records and reports differences. It does not change the original quantities to force a match.

## Minimum integrity test families

Every posting implementation must eventually include tests for:

- balanced and unbalanced functional amounts;
- mixed native instruments;
- account/book ownership mismatch;
- account instrument mismatch;
- zero and excessive-precision values;
- duplicate and conflicting idempotency keys;
- transaction atomicity on validation or persistence failure;
- mutation attempts against posted data;
- exact reversal;
- backdated events and cost-basis invalidation;
- missing, stale, future, inverted, and wrong-side quotes;
- obligation over-settlement and partial settlement;
- direct-write prevention at interface boundaries.
