# Decision Record Index

## Purpose

Decision records capture choices that materially affect accounting meaning, data compatibility, or
implementation boundaries. They are not a diary and should not be created for routine coding choices.

No decision record has been approved yet.

## Status vocabulary

- **Proposed**: written recommendation awaiting approval.
- **Accepted**: authoritative and safe for tasks to implement.
- **Superseded**: replaced by a newer decision record.
- **Rejected**: considered and intentionally not selected.

## Required decision-record structure

```markdown
# ADR-NNN: Decision title

## Status

## Context

## Decision

## Alternatives considered

## Consequences

## Affected rules and scenarios

## Validation notes
```

## Pending records

Create these only when each decision is ready to be evaluated:

| Candidate                                 | Blocking scope             | Current direction                             |
| ----------------------------------------- | -------------------------- | --------------------------------------------- |
| Functional instrument after first posting | Book/schema foundation     | Make USDT immutable per book                  |
| Cost-basis method                         | FX disposals and reporting | Moving weighted average                       |
| USD cash versus constrained USD bank      | Instruments and valuation  | Explicit fungibility decision                 |
| Decimal precision and rounding            | Schema and posting kernel  | High precision with named rounding boundaries |
| Binance P2P quote policy                  | Automatic VES valuation    | Side-aware filtered aggregate                 |
| Obligation indexing                       | Advanced debt settlement   | Defer; start fixed-denomination               |

## Rules

- A task must not mark an ADR accepted.
- Acceptance requires an explicit product/domain decision.
- An accepted ADR must update any affected canonical document in the same change.
- A superseding ADR must link both directions and preserve the old record.
- Do not use ADRs to override ledger invariants indirectly; update the referenced rules explicitly.
