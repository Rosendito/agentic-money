# ADR-002: Book functional instrument is immutable after first posting

## Status

Accepted (2026-08-06, product decision by the book owner).

## Context

Every posting stores a functional amount denominated in its book's functional instrument
(`LED-004`). If the functional instrument could change after transactions were posted, every
historical functional amount would become incomparable, breaking journal balance semantics
(`LED-001`) and all carrying-value reporting. `docs/README.md` listed this as known open decision 4
with the current direction "Make USDT immutable per book".

## Decision

- A book declares its functional instrument at creation. The initial personal book uses USDT.
- Once the book has at least one posted journal transaction, its functional instrument can never
  change through any application behavior.
- Changing functional currency is represented by creating a new book; no in-place conversion or
  restatement mechanism exists.
- This is a domain/application-layer guarantee: the model rejects updates to the functional
  instrument once posted transactions exist, no application service exposes such an update, and
  tests prove the guard. The schema stores the reference and must not invite mutation, but the
  database itself does not enforce the temporal condition.

## Alternatives considered

- Allowing a controlled redenomination workflow that restates all historical functional amounts:
  rejected as high-risk rewriting of immutable posted data (`LIF-003`) with no current product
  need.
- Enforcing the rule with a database trigger: rejected for now. The condition is temporal, not
  declarative, so it would require connection-specific trigger code (SQLite today, possibly
  PostgreSQL later) for a single-writer application whose only write path is the domain layer.
  Revisit if the project reaches Production with writers outside the application.

## Consequences

- Functional amounts within one book are always mutually comparable for the book's full history.
- Multi-currency reporting across books remains possible via quotes at report time without
  touching stored functional amounts.

## Affected rules and scenarios

- Resolves known open decision 4 in `docs/README.md` and the "Functional instrument after first
  posting" pending record.
- Reinforces `LED-001`, `LED-004`, `LIF-003`.

## Validation notes

- A feature test must prove that updating a book's functional instrument fails once a posted
  transaction exists.
