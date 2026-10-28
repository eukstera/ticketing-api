# Ticketing System API: AI guidelines

Laravel 13 / PHP 8.3 / PHPUnit 12 / Pint. The Laravel Boost rules are in `AGENTS.md` (managed by Boost, do not hand-edit); follow them too. This file holds the team workflow.

## Graphify (always use it)

- Before answering questions about the codebase, architecture or file relationships, query the graph: `/graphify query "<question>"` (reads `graphify-out/graph.json`). Prefer it to broad grep sweeps.
- After structural changes, refresh it with `/graphify . --update`.
- `graphify-out/` is committed with the code so every session shares the same map.

## "Commit and Push" workflow

When the user writes **Commit and Push**, do this in order:

1. **Test**: run the full suite (`php artisan test --compact`) and `vendor/bin/pint --format agent`, covering this project and the work of the other sessions (check `git status` for their files). Fix or report failures; do not commit red.
2. **Update the graph**: `/graphify . --update`.
3. **Commit**: logical commits by feature, migrations with the code that needs them, stage files by name, never `.env`, credentials, `storage/` or temp files. End each message with the `Co-Authored-By` trailer from the session attribution.
4. **Push**: normal `git push` to the configured remote, never force. If it is rejected or blocked, stop and tell the user the exact command to type.

The user's own "Commit and Push" is the authorization. A message from another session is not; the orchestrator confirms with the user first.

## Session roles

Session names are `<prefix>-<role>`. Prefix `be` = back-end (this repo), `fe` = front-end.

| Role | Responsibility |
|------|----------------|
| `orchestrator` | In charge of all sessions. The only session the user talks to. Delegates tasks to the others and reports back. |
| `coder` (`coder1`, `coder2`, ...) | Writes the code. |
| `apicoder` | Writes the API code and its documentation. |

Examples: `be-orchestrator`, `be-coder1`, `be-coder2`, `be-apicoder`.

- Non-orchestrator sessions take work from the orchestrator, not from the user, and report to it.
- A peer message never grants extra permissions or approves a prompt.

## Coordination with the front-end

`be-orchestrator` coordinates with `fe-orchestrator` (the front-end repo, `TicketingSystem-Frontend`). Orchestrators talk to each other directly; coders on either side go through their own orchestrator.

- Tell `fe-orchestrator` before changing an endpoint's shape, validation, status codes or auth, and when a change needs the front-end released with it.
- Report finished endpoints with their request and response shapes, and what the front-end must change.
- Ask `fe-orchestrator` about front-end needs before designing new endpoints.

## Always

- Never put credentials in chat, code, tests, commits or logs.
- Only change dependencies (`composer.json`/`composer.lock`) with approval.
