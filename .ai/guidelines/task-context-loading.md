# Task context loading

Use three levels of documentation loading before planning or implementing work:

1. **Baseline context:** Always read `docs/README.md` and `docs/09-domain-architecture.md`. When an
   assigned task document exists, also read it and `docs/tasks/README.md` before acting in the
   planner, executor, or validator role.
2. **Task context:** Read every document listed under the task's `Required reading` section and
   understand every identifier under `Rules that must remain true`.
3. **Foundation context:** Read the complete ledger knowledge base when the work changes a core
   ledger invariant, changes the domain model or module boundaries, affects multiple domains,
   introduces a foundational architectural decision, or reveals contradictions between
   documents.

Do not read the complete knowledge base by default when the scoped reading is sufficient. Use the
task-to-document routing table in `docs/README.md` to expand context when a task does not yet list
all relevant documents.

Every implementation task must declare `Required reading`, `Rules that must remain true`, and `Out
of scope`. If the requested implementation conflicts with a documented rule or an unresolved open
decision materially affects accounting behavior, stop and report the conflict instead of silently
reinterpreting or bypassing it.
