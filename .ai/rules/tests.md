---
paths:
  - 'tests/**'
---

# Tests

## Verify database behavior against PostgreSQL via compose, not ad-hoc containers
SQLite :memory: is the default test path. When a change touches database behavior (constraints, decimal precision, engine-specific semantics), also verify against PostgreSQL 17 locally with the committed compose service: `docker compose up --wait -d`, then `composer test:pgsql`, then `docker compose down`. Do not hand-roll `docker run postgres` containers; the compose service mirrors the CI pgsql matrix leg exactly (port 55432, user/password postgres, database testing, tmpfs data).
