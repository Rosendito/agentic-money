# ADR-003: Expense classification boundary and category model

## Status

Accepted (2026-08-08, product decision by the book owner).

## Context

`LED-010` locks categories as posting dimensions rather than nominal accounts, and `SystemAccountRole`
already declares one control account per nominal role, so a hierarchy of nominal accounts was
implicitly excluded by TASK-001. Two questions remained unanswered and blocked the posting engine:

1. How a category attaches to a posting, given that TASK-001 shipped `postings` with `memo` only.
2. Where other ways of classifying the same expense belong. Personal analysis needs axes such as
   *who the spending benefited* (self, partner, family), which are independent of category: "clothes
   for my partner" and "restaurant with my partner" cross both axes freely. Encoding them as
   compound categories multiplies the vocabulary; encoding each as a new posting column grows the
   ledger core for every product need.

`RPT-011`, `RPT-012`, and `RPT-013` already define category spending, so category cannot be optional
for the reporting core. Nothing in the knowledge base defines a beneficiary axis.

## Decision

**Categories are a book-scoped table referenced from the posting.** A `categories` table is owned by
the Ledger module. A posting carries an optional category reference. Categories are flat in the
first release; a parent reference may be added later without touching posted monetary data.

**A classification belongs to the ledger only when an external auditor would require it to read the
financial statements. Every other axis is a satellite feature built on top of the ledger.** Nature of
expense — food, rent, utilities — is part of the income statement, so it lives in the ledger. Who the
spending benefited never appears in any financial statement, so it does not.

**Satellite classification is written by application use cases, not by listeners.** A use case above
the ledger calls the posting action, receives the created postings, and writes its own classification
rows inside the same database transaction. Events remain reserved for retry-safe downstream
reactions per `ARC-012` and `ARC-013`; an after-commit listener must never be the only writer of data
the user supplied, because its failure would silently lose that data.

**Reclassifying a posted transaction is not a reversal.** Changing a posting's category is permitted
and does not create reversal postings, because no balance, quantity, effective time, instrument, or
account changes. Every reclassification is appended to a classification history that is never
deleted, so any past state of the income statement remains reconstructible.

## Alternatives considered

- **Free-text category on the posting:** rejected. Grouping by exact string makes typos permanent
  categories, and the immutability of posted data leaves no clean way to fix them.
- **One nominal account per category:** rejected. It contradicts `LED-010`, turns renaming or merging
  a category into an accounting operation on posted history, and multiplies accounts combinatorially
  once a second axis is wanted.
- **Category as a satellite table like every other axis, with the income statement composed one layer
  above the ledger:** rejected. Category is required by `RPT-011`–`RPT-013`, so the reporting core
  would depend on a satellite for its primary breakdown.
- **A generic dimension registry (dimension, dimension value, at most one value per dimension per
  posting) serving category and every future axis:** deferred, not rejected. It is the likely shape of
  the satellite mechanism when the second axis is actually built. Adding it later is additive: a new
  axis introduces tables and references without recomputing a single monetary value.
- **Reclassification through reversal plus replacement:** rejected. It would use the accounting
  correction mechanism to fix a label, filling history with reversals that correct no monetary fact.

## Consequences

- The posting engine can classify income and expenses from its first slice, and category spending
  reports have an authoritative source.
- The ledger core gains no classification columns for product features. The audit criterion, not a
  bare prohibition, decides each future case.
- Renaming or merging a category updates the category row and leaves posted transactions untouched.
- Reclassification requires a classification-history table and a use case that appends to it. Reports
  read current classification; the history exists for audit and point-in-time reconstruction.
- TASK-001 migrations must add the category table and the posting reference. They have not run in any
  real environment, so they are rewritten rather than extended by a follow-up migration.
- A beneficiary axis remains unimplemented and is explicitly a satellite feature, not a ledger gap.

## Affected rules and scenarios

- Resolves the second open implementation decision in `docs/03-ledger-model.md`: nominal system
  accounts are one set per book and categories are always dimensions.
- Adds `LED-016` (classification boundary) and `LIF-022` (reclassification is not a reversal).
- Reinforces `LED-010`, `LIF-003`, `LIF-006`, `LIF-016`, `ARC-012`, `ARC-013`, `RPT-011`, `RPT-012`.
- Applies to `SCN-EXP-001`, `SCN-EXP-002`, and `SCN-REF-001`, where only the expense posting carries a
  category while fees and realized FX results do not.

## Validation notes

- A feature test must prove that a categorized expense posting reports its category spending at the
  functional expense value while realized FX and fee postings in the same transaction remain
  uncategorized (`RPT-012`).
- A feature test must prove that reclassifying a posted posting creates no new postings, leaves every
  balance unchanged, and appends a history entry retaining the previous category.
- A test must prove a category cannot be referenced across books (`LIF-016`).
