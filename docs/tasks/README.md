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

## Roles

- **Planner:** defines the intention, relevant design boundaries, hidden risks, and acceptance
  criteria. It leaves routine code decisions to the executor.
- **Executor:** implements the task and adds a short execution summary with verification evidence.
- **Validator:** independently reviews the diff and evidence, then records a verdict and findings.

Keep tasks small enough to review but large enough to produce a coherent capability. After a task
is `ready`, do not rewrite its intent merely to make an implementation appear compliant; record a
scope change or request clarification instead.
