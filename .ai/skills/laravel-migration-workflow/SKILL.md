---
name: laravel-migration-workflow
description: "Apply this skill whenever creating, editing, reviewing, or planning Laravel database migrations in this project. It defines when existing migrations may be rewritten, when forward-only migrations are required, when migrate:fresh is appropriate, and when related tables may share one migration."
---

# Laravel Migration Workflow

Manage migrations according to the lifecycle status declared in the repository root `README.md`.

## Determine the lifecycle first

Before planning or changing any migration, read `README.md` at the repository root.

- `MVP development` means the schema is still disposable and migration history may be rewritten.
- `Production` means persisted data and executed migration history must be preserved.
- If the status is missing, unclear, or uses another value, do not assume the database is disposable.
  Ask the user before rewriting an existing migration or running `migrate:fresh`.

The project must switch the root README status to `Production` as soon as it has customers or the
owner starts using it with data that must be preserved.

## MVP development mode

While the root README says `MVP development`:

- Edit an existing migration when changing a table introduced by that migration. For example, add
  a newly required column to the original `create_*_table` migration instead of creating a second
  `add_*_to_*_table` migration.
- Remove obsolete schema choices from the original migration instead of preserving compatibility
  layers for schema that has not reached production.
- After rewriting migrations, rebuild the disposable development schema with
  `php artisan migrate:fresh --no-interaction` and run the relevant tests.
- Before running `migrate:fresh`, verify that the active environment is not production and that the
  selected database contains no data that must be preserved. The command is destructive.
- Do not use this freedom to rewrite unrelated migrations or hide changes outside the current task.

## Production mode

While the root README says `Production`:

- Treat every migration that may have been executed as immutable.
- Create a new forward-only migration for every schema change.
- Preserve existing data and add an explicit backfill or transition when the new schema requires
  it.
- Never use `migrate:fresh` against a database containing production or otherwise valuable data.
  It remains acceptable only for isolated test databases and explicitly disposable local databases.

## Grouping related tables

A task may contain multiple migrations. It may also create two or three closely related tables in
one migration when all of the following are true:

- the tables belong to the same domain capability or kind;
- they form one coherent schema slice and share a natural creation order;
- the migration name can describe the whole group clearly;
- the `down()` method can reverse the group safely in dependency order; and
- keeping them together is easier to understand than splitting them.

Split the tables into separate migrations when they are unrelated, cross domain boundaries, have
independent deployment or rollback concerns, or require a vague migration name. A migration name
that cannot clearly express the grouped change is evidence that the batch is too broad.

## Verification

After migration work:

1. Confirm the migration set represents a fresh installation correctly.
2. In `MVP development`, run `php artisan migrate:fresh --no-interaction` on the verified disposable
   database.
3. Run the narrowest relevant migration or feature tests, followed by the project-required suite.
4. Review the migration names and grouping once more for clarity.
