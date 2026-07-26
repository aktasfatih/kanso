<div align="center">

# Kanso

**Fast, open-source kanban boards for [Nextcloud](https://nextcloud.com).**

[![CI](https://github.com/aktasfatih/kanso/actions/workflows/ci.yml/badge.svg)](https://github.com/aktasfatih/kanso/actions/workflows/ci.yml)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)
![Nextcloud 30–32](https://img.shields.io/badge/Nextcloud-30–32-0082c9)

Instant drag & drop, payloads sized for large boards, realtime sync — a
from-scratch alternative to Deck that stays out of your way.

<img src="docs/kanso-board.png" alt="A Kanso board with Backlog / In Progress / In Review / Done columns" width="900">

</div>

## Why "Kanso"?

**Kanso** (簡素) is one of the seven principles of Japanese Zen aesthetics. It
means *simplicity* — the deliberate elimination of clutter so that only what
matters remains. That is the whole idea behind this app: a kanban board that is
fast, uncluttered, and free. No lock-in, no bloat, no per-seat pricing — just
your work, on your own Nextcloud, laid out plainly.

## Features

### ⚡ Fast by design
- **Instant, optimistic drag & drop** — a card move is a single-row update
  (fractional sort keys), never a bulk renumber.
- **Built for large boards** — summary-only payloads, `ETag`/`If-None-Match`
  caching, and virtualized columns that stay smooth past **2,000+ cards**.
- **Realtime** — live updates via `notify_push` when available, with a light
  polling fallback everywhere else. Delta sync keeps clients in step.

### 🗂️ Rich cards
- Markdown descriptions (sanitized), **labels**, due dates, **assignees**, and
  priorities.
- **Checklists / sub-tasks**, **parent ↔ child cards**, and **threaded
  comments**.

### 👥 Collaboration
- **Board sharing** with per-user and per-group access control.
- **Watchers** — subscribe to cards, comment threads, or a **whole board** and
  get notified of new activity.
- **Parent/child cards** — a parent auto-completes when all its children are done.

### ✅ Review workflow
- Request a review, then **Approve** / **Request changes**.
- An optional **Done-gate**: a card can't leave a review column until every
  requested review is approved.
- **Customizable review types** — QA, Code, Legal, or whatever your team needs.
- A cross-board **My Reviews** page so nothing waiting on you slips through.

### 🔁 Automation & workflows
- **Stack roles** and **WIP limits**; moving a card into a "done" column stamps
  it done automatically.
- **Recurring cards** on RRULE schedules, and **auto-archive** rules for done
  cards.

### 🔗 Integrations & migration
- **Import from Deck** — one click copies a Deck board (stacks, cards, labels,
  assignees) into a new Kanso board you own. Your Deck boards are left untouched.
- **GitHub links** — attach PRs/issues to a card with live open/merged/closed
  badges, and copy a ready-made `kanso-<id>` branch name.
- **GitHub webhook** — an HMAC-verified webhook moves a card to your Review
  column when its PR opens and to Done when it merges. No credentials, no OAuth.

### 📊 Views
- **Board, List and Timeline** views — switch per board (remembered per user).
  The list is a dense, scannable table; the **Timeline (Gantt)** plots cards on a
  date axis by **start → due**, with due-only cards as milestones.
- **Display sort** — order cards by priority, due date or title. View-only: your
  manual drag order is always preserved.

### ⌨️ Power-user UX
- **Command palette** (`Ctrl`/`Cmd`+`K`) and full-text **search** across cards
  and comments.
- **Keyboard-first** navigation, **undo toasts** for destructive actions, and a
  **trash** with restore.

## Installation

Kanso targets **Nextcloud 30–32** and **PHP 8.2+**. It isn't on the Nextcloud
App Store yet, so install it from source (you'll need **Node 20+**,
**Composer**, and shell access to your server):

```sh
# 1. Clone into your Nextcloud "custom apps" directory
cd /path/to/nextcloud/custom_apps
git clone https://github.com/aktasfatih/kanso.git
cd kanso

# 2. Build the frontend and install PHP dependencies
npm ci && npm run build
composer install --no-dev

# 3. Enable the app
cd /path/to/nextcloud
sudo -u www-data php occ app:enable kanso
```

Open **Kanso** from the Nextcloud app menu and create your first board.

- **Background jobs** (recurring cards, auto-archive, change-log pruning) run
  through Nextcloud's cron — make sure [system cron](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/background_jobs_configuration.html)
  is configured.
- **Realtime updates** use the [High Performance Backend (`notify_push`)](https://github.com/nextcloud/notify_push)
  when it's installed; otherwise Kanso falls back to polling automatically.

### Try it locally — no Nextcloud required

The repo ships a throwaway Docker dev stack:

```sh
npm install
npm run build
cd dev && ./setup.sh   # boots Nextcloud + Postgres and enables Kanso
```

Then open <http://localhost:8891> (login `admin` / `admin`).

## Development

The dev stack mounts your checkout as `custom_apps/kanso`, so a rebuild
(`npm run build`) plus a browser reload picks up frontend changes; PHP changes
apply immediately. `docker compose down` in `dev/` resets the instance.

PHP tooling runs via Docker (no host PHP needed):

```sh
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
  -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app \
  php:8.2-cli-alpine php vendor/bin/php-cs-fixer fix --dry-run
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app \
  php:8.2-cli-alpine php vendor/bin/psalm
```

Tests: PHPUnit for the API/services, Playwright for board interactions.

```sh
npm test                                   # Playwright e2e (needs the dev stack up)
docker run --rm -v "$PWD":/app -w /app php:8.2-cli-alpine \
  php vendor/bin/phpunit -c phpunit.xml    # PHP unit tests
```

## Tech

PHP (Nextcloud App Framework, own `kanso_*` tables) · Vue 3 + `@nextcloud/vue` +
Vite · TanStack Query · Pragmatic drag-and-drop · TanStack Virtual · Postgres /
MySQL / SQLite. Kanso is independent — it does **not** depend on Deck and stores
its own data.

## Status & contributing

Early development, but already usable day-to-day. Bug reports and pull requests
are welcome — see the [issues](https://github.com/aktasfatih/kanso/issues).

## License

[AGPL-3.0-or-later](LICENSE).
