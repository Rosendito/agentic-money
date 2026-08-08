# Agentic Money

## Project status

**MVP development**

This status controls the database migration workflow. Change it to **Production** as soon as the
application has customers or the owner begins using it with data that must be preserved.

The ledger knowledge base and implementation guidance live in [`docs/README.md`](docs/README.md).

## Development setup

Run `composer setup` once to install dependencies, generate an application key, migrate the local
SQLite database, and build frontend assets.

`composer test` runs the full suite in parallel and, on developer machines, uses Pest 5's Test
Impact Analysis (TIA) to replay only the tests affected by your changes. TIA requires a coverage
driver to record its dependency graph — install one with `pecl install pcov` (or enable Xdebug).
Without a coverage driver the command still runs the full suite; it just cannot replay from cache.
CI always runs the full suite, since its PHP setup installs no coverage driver, which makes TIA a
no-op there without any extra flag.
