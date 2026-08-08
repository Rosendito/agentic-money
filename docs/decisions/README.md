# Decision Record Index

## Purpose

Decision records capture choices that materially affect accounting meaning, data compatibility, or
implementation boundaries. They are not a diary and should not be created for routine coding choices.

Decision records are the `ADR-*.md` files in this directory. There is no separate index: the
directory listing is the catalog, and each record carries its own status.

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

| Candidate                | Blocking scope                     | Current direction                                            |
| ------------------------ | ---------------------------------- | ------------------------------------------------------------ |
| Binance P2P quote policy | Automatic VES valuation (deferred) | Executable best offer for a configurable reference amount    |
| Obligation indexing      | Advanced debt settlement           | Defer; start fixed-denomination                              |

## Rules

- A task must not mark an ADR accepted.
- Acceptance requires an explicit product/domain decision.
- An accepted ADR must update any affected canonical document in the same change.
- A superseding ADR must link both directions and preserve the old record.
- Do not use ADRs to override ledger invariants indirectly; update the referenced rules explicitly.
