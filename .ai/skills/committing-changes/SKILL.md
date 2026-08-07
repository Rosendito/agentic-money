---
name: committing-changes
description: "Prepare and create focused Git commits with the project's required Conventional Commit message format. Use whenever the user asks to stage changes, prepare or propose a commit message, create a commit, split work into commits, amend a commit, or otherwise save current changes to Git history."
---

# Committing Changes

Create intentional, reviewable commits without absorbing unrelated workspace changes.

## Workflow

1. Inspect `git status`, the staged diff, and the unstaged diff before staging anything.
2. Identify the exact files that belong to the requested change. Preserve unrelated user changes
   and never stage them merely because they are present.
3. Run the relevant project checks before committing. Report any check that cannot be run or does
   not pass.
4. Stage only the intended paths and review the staged diff.
5. Compose the message using the format below and verify the subject length before committing.
6. Create the commit only when the user has authorized a commit. Preparing or proposing a message
   does not itself authorize changing Git history.
7. Inspect the resulting commit and report its hash and subject. Do not push unless the user also
   requested a push.

Do not amend, squash, rebase, or otherwise rewrite existing history unless the user explicitly
requests that operation.

## Authorship and attribution

- Commits must retain the repository user's configured human author and committer identity.
- Never add Claude, Anthropic, or any other AI agent or model as an author, committer, or co-author.
- Never add a `Co-Authored-By` trailer for Claude or another AI tool, even when that tool generated
  or reviewed the change.
- Before creating or amending a commit, inspect the complete message and remove any AI attribution
  trailers. Afterward, verify the stored commit message with `git show --format=fuller --no-patch`.
- If the user explicitly requests removal of existing AI attribution, rewrite only the affected
  commits and verify the full reachable history afterward.

## Commit message

Use this structure:

```text
type(scope): title

Description explaining what changed and why.
```

The complete subject line, including `type`, `scope`, punctuation, and title, must contain no more
than 66 characters. Use a concise, imperative title without a trailing period.

Always include a non-empty, lowercase scope and choose the narrowest meaningful one. Use an
established Conventional Commit type such as `feat`, `fix`, `refactor`, `test`, `docs`, `chore`,
`build`, `ci`, `perf`, `style`, or `revert`.

Always include a body separated from the subject by a blank line. Explain the relevant change and
its reason clearly without turning the commit into a complete task report. Prefer one to three
short paragraphs or a small list; include verification details only when they add useful context.

Do not append AI attribution or co-authorship trailers to the body.

Before committing, measure the final subject rather than estimating it. Shorten the title or scope
if it exceeds 66 characters; never rely on a hosting interface to truncate it.

## Example

```text
docs(architecture): define task context loading

Document the three context levels agents must follow before implementation.
Keep task-specific reading selective while requiring full review for changes to
ledger invariants or domain boundaries.
```
