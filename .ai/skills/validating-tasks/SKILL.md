---
name: validating-tasks
description: Independently review project task documents before execution and completed task implementations afterward. Use when asked to validate, audit, approve, or review a task or plan; move a task to ready, changes_requested, or done; inspect an executor's implementation against a task; assess acceptance criteria, execution evidence, diffs, tests, architectural rules, or hidden correctness risks; or act in the Validator role for files under docs/tasks.
---

# Validating Tasks

Act as an independent validator. Produce an evidence-backed verdict, not a summary of what the
planner or executor claims.

## Preserve reviewer independence

- Treat validation as read-only by default. Inspect files, history, diffs, configuration, and run
  non-destructive verification commands.
- Do not implement fixes, refactor code, change tests to pass, stage files, commit, or rewrite Git
  history while acting as validator.
- Do not rewrite a ready task's intention or acceptance criteria to make an implementation comply.
- When the user explicitly asks to record the verdict, edit only the assigned task's status and
  validation record according to `docs/tasks/README.md` and `docs/tasks/TEMPLATE.md`.
- If the validator repairs any finding, stop treating that review as independent and require a
  fresh validator for the repaired result.
- Preserve unrelated tracked, staged, unstaged, and untracked changes.

## Select the review mode

Infer the mode from the task status and contents:

1. **Written-task review:** validate a proposed task before implementation. Use when execution is
   pending or the request concerns whether the task is well designed.
2. **Implementation review:** validate completed work against the task. Use when the task has an
   execution record, an implementation commit, or status `review` or `changes_requested`.

If both are needed, validate the task definition first. An implementation cannot compensate for a
contradictory or materially incomplete task.

## Load authoritative context

Before reaching a verdict:

1. Read `.ai/rules/index.md`, every matching rule file, and search `.ai/rules` for concepts central
   to the task.
2. Read `docs/README.md`, `docs/09-domain-architecture.md`, `docs/tasks/README.md`, and the assigned
   task completely.
3. Read every document listed under `Required reading` and locate every identifier under `Rules
   that must remain true` in its authoritative source.
4. Use the routing table in `docs/README.md` to detect missing task context.
5. Load the complete foundation only when the task changes a core invariant, domain model, module
   boundary, several domains, or a foundational decision, or when the scoped sources conflict.
6. Activate every project skill relevant to the artifacts under review. Skill instructions add
   review criteria; they do not override the task's authoritative domain rules.

Treat current requirements, locked decisions, code, and tests as evidence at different levels.
Old plans and executor notes are context, not authority.

## Review a written task

Trace the task from intention to verifiable completion:

- Confirm one coherent outcome and enough context to understand why it matters.
- Confirm required reading covers the affected areas and every cited rule or scenario exists.
- Compare the task's interpretation of each rule with the authoritative text; do not trust the
  identifier alone.
- Detect unresolved open decisions, contradictions, or assumptions that would force the executor
  to invent product or accounting behavior.
- Confirm scope and out-of-scope boundaries prevent adjacent work from leaking into the task.
- Confirm hidden risks cover the relevant integrity concerns, including authorization, data loss,
  atomicity, idempotency, concurrency, precision, immutability, reversals, event ordering,
  untrusted external data, and auditability.
- Apply every relevant probe in **Target high-risk seams** below. Require an observable acceptance
  criterion for each applicable cross-flow, configuration, user-outcome, external-data,
  identifier-scope, documentation, or state-machine risk.
- Require observable acceptance criteria. Each important behavior and failure path must be
  independently testable; implementation activity such as "create a class" is not sufficient
  proof of an outcome.
- Reject criteria that claim external, production, provider, device, or deployment proof using
  only mocks or local tests.
- Keep routine implementation choices with the executor. Flag needless prescription when it
  reduces valid design options without protecting a documented rule.
- Check that the task is small enough to review and large enough to deliver a coherent capability.

Use `Ready` only when an executor can implement the task without guessing material behavior.

## Review an implementation

### Establish the actual change surface

- Inspect `git status --short`, the target commit or range, tracked and staged diffs, unstaged
  changes, and relevant untracked files. Do not assume the execution commit or `HEAD` contains the
  whole implementation.
- Read the implementation itself and relevant callers, models, migrations, tests, factories,
  configuration, and documentation. Do not validate from a diff summary alone.
- Distinguish pre-existing defects from regressions introduced or exposed by the task. Report both
  when they block acceptance, but label the distinction.

### Test the requirements, not the checklist

- Map every acceptance criterion and required invariant to concrete implementation and evidence.
- Build an internal pass/fail/unverified matrix for every acceptance criterion before choosing the
  verdict. Do not omit a criterion merely because several others passed.
- Inspect failure paths and boundary conditions, not only the happy path.
- Look for data-loss paths, integrity gaps, unsafe defaults, missing authorization, invalid state
  transitions, race conditions, non-idempotent retries, precision loss, query explosions, and
  dependency-direction violations when relevant.
- Confirm tests would fail if the protected behavior broke. A passing test suite is not proof when
  assertions miss the invariant.
- Apply every relevant probe in **Target high-risk seams** below. Reproduce the behavior and inspect
  the resulting persisted or emitted state.
- Inspect Composer scripts, package scripts, CI configuration, and project instructions to discover
  every required gate, including tests, static analysis, type checks, builds, migrations, and
  formatting.
- Run the narrowest relevant checks and all non-destructive project-required gates yourself. Record
  exact command outcomes; do not copy the executor's result as proof.
- Never run an auto-fixing formatter or another command that may change the worktree. Use a
  project-approved read-only check mode when one exists; otherwise mark that gate unverified and
  explain why it could not be reproduced without violating reviewer independence.
- Use version-specific documentation or installed dependency sources when correctness depends on
  framework or package behavior.
- Separate local proof from external acceptance. Mocks, fakes, local databases, and SDK types do
  not prove real providers, staging, production, browsers, or devices.

### Target high-risk seams

Use these probes when the reviewed scope contains the corresponding seam. Do not replace concrete
reproduction with reasoning when the behavior can be exercised safely. Reproduce through the
project's isolated test harness or another authorized disposable environment; never write to a
shared or external system merely to validate a hypothesis.

- **Cross-wire sibling flows.** When features share storage, queues, caches, callback endpoints, or
  another infrastructure seam, feed each flow the other's opaque handle in both directions. Verify
  every assumed binding, including provider, registration, principal, tenant, and type. Exercise
  denial and retry paths, and confirm a mismatched call does not consume or destroy the legitimate
  handle unless an authoritative security rule requires it.
- **Trace configuration to every consumer.** Choose the closest established sibling setting and
  find every place it is wired: configuration, environment examples, local containers, staging and
  production workflows, infrastructure manifests, and setup documentation. Treat the new setting
  as incomplete when a required consumer is missing.
- **Trace every promised user outcome to its branch.** Map each distinct user-facing promise to the
  classification logic, response, and test that produces it. A generic error surface or raw
  provider message does not prove a specifically promised explanation or availability state.
- **Break typed error boundaries.** For each external I/O result, exercise transport failure,
  unparseable success bodies, valid bodies with the wrong shape, and missing or empty success
  fields. Inspect parsing, indexing, and fresh-data attribute access to confirm all failures map to
  the declared error contract instead of leaking incidental parser or lookup exceptions.
- **Challenge identifier scope.** Determine what every persisted or compared identifier actually
  distinguishes. Verify that lookup, uniqueness, and upsert keys cover the entity's full scope;
  tenant, user, provider, registration, and reassignment boundaries must not silently merge or
  cross-authorize principals.
- **Verify exact cited documentation.** Open the cited version and inspect the exact paragraph,
  table cell, row, and column header that supports the claim. When sources appear to disagree,
  first confirm they describe the same operation, account type, permission level, version, and
  environment. Do not accept or reject a claim from citation proximity or recollection.
- **Probe state-machine failure windows.** At every step of a multi-step or one-shot flow, inspect
  failure after the previous commit and concurrent duplicate execution before the next step.
  Verify retry behavior, compare-and-set or idempotency semantics, and whether resource consumption
  order matches the documented security and recovery intent.

### Reconcile evidence

Challenge inconsistencies between the task, execution summary, commit, worktree, tests, and runtime
results. If a claim cannot be reproduced, mark it unverified. Never fill an evidence gap with a
plausible inference.

## Classify findings

Report only actionable findings and order them by severity:

- **P0:** immediate catastrophic risk, such as exploitable security compromise or broad
  irreversible data loss.
- **P1:** violated domain invariant, incorrect behavior, security or data-integrity defect,
  unmet acceptance criterion, destructive migration, or failed required quality gate.
- **P2:** meaningful design, maintainability, performance, test-quality, or convention defect that
  should be corrected before acceptance.
- **P3:** low-risk improvement that does not block the task. Keep these sparse.

For every finding, state:

1. a specific title;
2. the exact file and tight line range or command evidence;
3. expected behavior from the task or authoritative rule;
4. observed behavior;
5. concrete impact or failure scenario;
6. the outcome required to resolve it, without prescribing unnecessary implementation details.

Do not inflate severity, list speculative risks as facts, or hide important findings inside a long
summary.

## Choose the verdict

- **Ready:** the written task is coherent, authoritative, bounded, observable, and has no material
  ambiguity.
- **Approved / Done:** the implementation satisfies the task and required checks with no blocking
  findings.
- **Changes requested:** one or more actionable findings prevent readiness or acceptance.
- **Blocked:** validation cannot complete because required authority, evidence, environment, or an
  unresolved product decision is unavailable. Do not use `Blocked` merely because work remains.

Do not approve with a P0, P1, or unresolved P2 finding. Non-blocking follow-ups must be explicit and
must not contradict the verdict.

Do not re-report a gate merely because the task already marks it unverified by design. Report it
when the implementation or execution record claims proof that cannot be reproduced, or when the
unverified gate is itself required for acceptance.

When recording the result in a project task, use the repository's exact status vocabulary. A
successful pre-execution review moves a draft to `ready`; a successful implementation review moves
`review` to `done`; failed reviews use `changes_requested` unless genuinely blocked.

## Present the review

Lead with the verdict and findings. Keep the output compact and auditable:

```markdown
Verdict: Changes requested

Findings

1. [P1] Specific defect title — `path/to/file.php:42`
   Expected ... Observed ... This can ... Resolve by ensuring ...

Evidence

- `command` — passed or failed with the relevant count/error.

Unverified gates

- Real provider callback remains unverified; local mocks do not cover it.
```

If there are no findings, say so directly, list the checks actually run, and identify every gate
that remains unverified. Do not manufacture findings to make the review look thorough.
