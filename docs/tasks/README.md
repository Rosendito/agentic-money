# Task history

Tasks live here as committed project history. Create each one from [TEMPLATE.md](TEMPLATE.md) and
use a sequential filename:

```text
001-design-ledger-schema.md
002-post-balanced-transaction.md
```

Keep every task in this directory after completion. Use its status instead of moving it between
folders:

```text
draft -> ready -> in_progress -> review -> done
```

Use `blocked` or `changes_requested` when necessary.

## Planned sequence

The intended order of ledger slices. A row becomes a task document only when it is ready to be
planned; the blocking decisions listed must be resolved first.

| Slice                                    | Depends on                          | Blocking decisions                       |
| ---------------------------------------- | ----------------------------------- | ---------------------------------------- |
| Schema, models, factories                | —                                   | ADR-001, ADR-002 (resolved)              |
| CI against a real decimal database       | Schema                              | —                                        |
| Decimal scale at the app boundary        | CI                                  | ADR-001 amendment (resolved)             |
| Total precision at the app boundary      | Decimal scale slice                 | —                                        |
| Categories and the posting engine        | Schema                              | ADR-003 (resolved)                       |
| Effective-time availability (`ACC-010`)  | Posting engine, kernel hardening    | ADR-004 amendment (resolved)             |
| Reversal and correction                  | Posting engine, effective-time availability | —                                 |
| Quotes and valuation policies            | Schema                              | ADR-005 resolved; Binance aggregation deferred |
| Cross-instrument exchanges and FX result | Posting engine, quotes              | ADR-004 (resolved)                       |
| Credit-card purchases and payments       | Posting engine                      | —                                        |
| Obligations and settlement               | Posting engine, quotes              | Obligation account shape, indexing scope |
| Module dependency architecture tests     | Ledger, Money, Obligations modules  | —                                        |
| Read models and reports                  | Posting engine, quotes, obligations | —                                        |
| External evidence import                 | Posting engine                      | —                                        |
| Reconciliation and gap detection         | External evidence import            | —                                        |

Book bootstrap — creating a book with its functional instrument, system accounts, and initial
instruments — is expected to belong to the posting-engine slice rather than a task of its own.

Deliberately outside the ledger scope: HTTP and Inertia delivery, provider integrations under
`app/Infrastructure`, and the future agent interface. Explicit revaluation and unrealized FX
presentation are deferred ([ADR-004](../decisions/ADR-004-cost-basis-and-backdating.md)).

**External evidence import** records provider payloads and bank movements as external evidence before
mapping them to journal events (`LIF-019`, `LIF-020`), and additionally records observed external
balances at a point in time. The observed balance is a distinct fact from the movement list and is
what makes unrecorded spending detectable.

**Reconciliation and gap detection** compares ledger-derived balances against observed external
balances, links provider records to journal transactions, and reports differences without rewriting
either side (`LIF-021`). This is the slice the future AI agent interface depends on; the agent itself
calls the same application use cases as any other client and is not a ledger capability.

## Review rigor

Every task declares `rigor: strict` or `rigor: agile` in its frontmatter. The planner sets it when
the task is planned, using one criterion — **how hard the damage is to repair**:

A task is **strict** when a defect could corrupt or misstate posted financial history, change how
money is represented or measured, weaken a ledger invariant, alter migrations of ledger-owned
tables, or otherwise require a data migration or reversal campaign to repair. Rewriting the book's
history is the failure the project cannot afford; anything that writes toward it gets the full
treatment. Typical strict territory: the posting kernel and intent actions, reversal and
correction, money value objects and precision, quotes and valuation, obligations and settlement,
idempotency, book bootstrap, and ledger-table migrations.

A task is **agile** when a defect is repairable by a later code change without touching recorded
data. Typical agile territory: HTTP and Inertia delivery, dashboard and read-model presentation,
CI and developer tooling, reference-data seeders, documentation surfaces.

The two levels change what the validator does with findings:

- **Strict:** the current flow. Findings block; the task iterates executor–validator until `done`.
- **Agile:** ship first, repair later. One validation pass. Only findings that threaten data
  integrity, security, or silent data loss block — such a finding also reveals the task was
  misclassified, so it escalates to strict handling. Every other finding is recorded as a
  follow-up task document by the validator, and the task moves to `done` with the follow-ups
  linked. Repair is tracked, not skipped.

When an agile task turns out to touch strict territory mid-execution, the executor stops and
reports, exactly like any scope conflict. Tasks created before this policy carry no `rigor` field
and are treated as strict.

## Roles

- **Planner:** defines the intention, relevant design boundaries, hidden risks, and acceptance
  criteria. It leaves routine code decisions to the executor.
- **Executor:** implements the task and adds a short execution summary with verification evidence.
- **Validator:** independently reviews the diff and evidence, then records a verdict and findings.

Keep tasks small enough to review but large enough to produce a coherent capability. After a task
is `ready`, do not rewrite its intent merely to make an implementation appear compliant; record a
scope change or request clarification instead.
