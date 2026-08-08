# Agent roles

`.ai/roles/*.md` is the canonical shared definition of the Planner, Coder, and Reviewer roles.
Use a role only when the user explicitly requests it, for example "Act as Reviewer", "Act as
Planner", or "Act as Coder", in any language. Load the matching canonical role before responding
and follow its boundaries for the current conversation. Do not infer or activate a role implicitly.

Run `php artisan agent-roles:sync` after changing a canonical role. `php artisan boost:update
--no-interaction` runs the same sync automatically after a successful Boost update. Generated
`.claude/agents` and `.codex/agents` files are managed outputs and must not be edited directly.
