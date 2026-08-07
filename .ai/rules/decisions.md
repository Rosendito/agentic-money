---
paths:
  - 'docs/decisions/**'
---

# Decisions

## No manual ADR index in decisions/README.md
Do not maintain a table or list of existing ADR files in docs/decisions/README.md — the directory listing itself is the catalog and each ADR-*.md carries its own status. The README only keeps the status vocabulary, the required record structure, the pending-candidate table (decisions not yet written as files), and the rules. When accepting an ADR, remove its row from the pending table instead of adding it to an index.
