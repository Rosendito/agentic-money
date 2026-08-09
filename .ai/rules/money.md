---
paths:
  - 'app/Domain/Money/**'
---

# Money

## Boundary validation must define its grammar and prove engine parity
Lesson from TASK-003 (three fix rounds). When validating input at an application boundary: (1) the accepted grammar must be defined explicitly and validated in full by the value object — never delegate syntax to the database, engines disagree (exponents, whitespace, missing integer part, trailing newlines without PCRE /D). (2) Prove engine parity with a dataset-driven differential test: every probe input either raises the same exception on SQLite and PostgreSQL or reads back byte-identical from both — run it via the compose service (composer test:pgsql). (3) When a fix introduces new parsing/normalization code, adversarially test the new code's edges (signs, leading zeros, -0, unicode, empty, whitespace variants), not just the reported failing case.
