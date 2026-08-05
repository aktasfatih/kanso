# Kanso

Kanso is an open-source kanban app for Nextcloud (app id `kanso`) — a from-scratch,
performance-first kanban board. AGPL, targets Nextcloud 30–34.

## Stack & conventions
- Backend: PHP 8.2+, Nextcloud app framework (`OCA\Kanso`), own tables prefixed `kanso_`.
- Frontend: Vue 3 + @nextcloud/vue 9 + Vite (same toolchain as ../deck_recurrence), TanStack Query (Vue) for server state, Pragmatic drag-and-drop, TanStack Virtual.
- Core performance bets (do not regress these):
  1. Fractional sort keys (lexorank-style strings) — a card move is a single-row UPDATE, never a bulk renumber.
  2. Board endpoints return card summaries only; descriptions load on card open.
  3. `kanso_changes` per-board change log powers delta sync (`?since=<changeId>`) and realtime.
  4. ETag / If-None-Match on board reads.
- Realtime: notify_push custom events when available; cheap delta-polling fallback otherwise.
- State pattern: database-first — server is source of truth, realtime deltas patch the client cache, local mutations are optimistic with rollback.
- Tests: PHPUnit for API/services (happy path + permission denial), Playwright smoke tests for board interactions, psalm + php-cs-fixer clean.
- Branch strategy: **`main` is protected — all changes land via pull request.** Work on a short-lived branch → open a PR → merge only when every required CI check is green. No direct pushes to `main` (admins can bypass for a genuine emergency hotfix only). Conventional-ish commit messages.
- Git: published at https://github.com/aktasfatih/kanso (`origin`). **Required checks — all must pass to merge:** `cs-check`, `psalm`, `unit-php (8.2)`, `unit-php (8.3)`, `build-frontend`, `e2e` (the full suite, ~1.5h). `.claude/` — local PM/agent tooling — is gitignored and kept out of the repo.

## Dev workflow
- NEVER test against prod (sv1). Use the local docker dev stack in `dev/` (copied from ../deck_recurrence/dev). Prod only gets tagged releases via the scoped deploy playbook.
- The PM charter lives in `.claude/pm-charter.md` — goal, stage, non-goals, ship bar. Respect its over-engineering guard: this is an MVP; no speculative abstractions.

## Nextcloud Deck (for /work and /create-tasks)
- Board ID: 14 (Kanso)
- Working column: Dev List (stack id 42) — /work drains this top-to-bottom by order
- Escalation column: Dev Priority (stack id 43) — cards needing a human decision
- Other stacks: Triage (41), Backlog (44, parked/deferred), Done (45)
- Label IDs: improvement=78, bug=79, security=80, scalability=81, testing=82, deferred=83, sprint-2026-07-22=84
