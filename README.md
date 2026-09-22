<div align="center">

# Kanso

**Fast, open-source kanban boards for [Nextcloud](https://nextcloud.com).**

[![CI](https://github.com/aktasfatih/kanso/actions/workflows/ci.yml/badge.svg)](https://github.com/aktasfatih/kanso/actions/workflows/ci.yml)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)
![Nextcloud 32–34](https://img.shields.io/badge/Nextcloud-32–34-0082c9)

Instant drag & drop, payloads sized for large boards, realtime sync: a
from-scratch kanban board that stays out of your way.

<img src="docs/kanso-board.png" alt="A Kanso board with Backlog / In Progress / In Review / Done columns" width="900">

</div>

## Why "Kanso"?

**Kanso** (簡素) is one of the seven principles of Japanese Zen aesthetics. It
means *simplicity*: the deliberate elimination of clutter so that only what
matters remains. That is the whole idea behind this app: a kanban board that is
fast, uncluttered, and free. No lock-in, no bloat, no per-seat pricing. Just
your work, on your own Nextcloud, laid out plainly.

## Features

### ⚡ Fast by design
- **Instant, optimistic drag & drop**: a card move is a single-row update
  (fractional sort keys), never a bulk renumber.
- **Built for large boards**: summary-only payloads, `ETag`/`If-None-Match`
  caching, and virtualized columns that stay smooth past **2,000+ cards**.
- **Realtime**: live updates via `notify_push` when available — a board change
  triggers an `ETag`/`If-None-Match` refetch that returns `304 Not Modified`
  when nothing changed — with a light polling fallback everywhere else.

### 🗂️ Rich cards
- Markdown descriptions (sanitized), **labels**, due dates, **assignees**, and
  priorities.
- **Checklists / sub-tasks**, **parent ↔ child cards**, and **threaded
  comments**.
- **Attachments**: upload files to a card (up to 100 MiB each). They live in
  Kanso's own app storage, not in your personal Files.
- **Custom fields** defined per board — text, number, date or a select list —
  and filled in on the card.
- **Time tracking**: log time against a card, with a note, and see the total per
  card. An automation rule can start and stop the timer as a card enters a
  column, so the common case needs no clicking.
- **Reminders**: nudge yourself about a card, or about a single comment —
  later today, tomorrow, next week, or a time you pick. Private to you, and
  delivered as a Nextcloud notification (so it needs cron).

<img src="docs/kanso-card.png" alt="A Kanso card with a markdown description, a checklist, priority, due date, an assignee and a threaded discussion" width="900">

### 👥 Collaboration
- **Board sharing** with per-user and per-group access control.
- **Public link**: publish a board **read-only** at a link that needs no
  Nextcloud account — optionally with its comments visible. Rotate or switch
  the link off at any time from board settings.
- **Watchers**: subscribe to cards, comment threads, or a **whole board** and
  get notified of new activity.
- **Parent/child cards**: a parent auto-completes when all its children are done.

### ✅ Review workflow
- Request a review, then **Approve** / **Request changes**.
- An optional **Done-gate**: a card can't leave a review column until every
  requested review is approved.
- **Customizable review types**: QA, Code, Legal, or whatever your team needs.
- A cross-board **My Reviews** view so nothing waiting on you slips through.

### 🔁 Automation & workflows
- **Stack roles** and **WIP limits**; moving a card into an "in progress" column
  auto-**starts** it and a "done" column stamps it **done**. You can also set
  the status (Not started / In progress / Done) directly from the card.
- **Recurring cards** on RRULE schedules, and **auto-archive** rules for done
  cards.
- **Card templates**: mark any card as a template, then start new cards from it
  straight out of the column's composer.

### 🔗 Integrations & migration
- **Import from Deck**: one click copies a Deck board (stacks, cards, labels,
  assignees) into a new Kanso board you own. Your Deck boards are left untouched.
- **Import from Trello or a CSV**: hand Kanso a Trello board's JSON export, or a
  CSV of cards plus a mapping of which column is the title, the description, the
  due date and so on. Both sit in the same **Import** menu on the board list.
- **Export, duplicate, back up**: download any board as a zip (its attachments
  included) and import that zip back on any instance; or duplicate a board, with
  or without its cards. Administrators can additionally schedule **periodic
  backups of every board**, keeping the most recent few per board — into Kanso's
  own storage (quiet, listed and downloadable from the admin panel) or into a
  Nextcloud folder you can browse and mount off-site. Off until an admin turns it
  on in Nextcloud's admin settings.
- **Calendar**: every board you can read appears as a **read-only CalDAV
  calendar** of its due-dated cards, so they show up in Nextcloud Calendar and
  Tasks and on your phone — nothing to copy or paste, and you can hide a board
  from your own calendar in board settings. A board can also publish a token
  **`.ics` feed URL** for calendar apps that want one. Both are read-only:
  ticking the task off in your calendar does not close the card.
- **Email intake**: point a board at an IMAP mailbox and mail arriving in a
  chosen folder becomes a card in a chosen column, with an optional sender
  allowlist. Kanso polls the mailbox on cron; nothing is exposed inbound.
- **Code links**: attach pull requests/issues to a card with live
  open/merged/closed badges, and copy a ready-made `kanso-<id>` branch name.
- **GitHub & Forgejo webhooks**: an HMAC-verified webhook (send it
  `pull_request` and `issues` events, content type `application/json`) moves a
  card to your Review column when its PR opens and to Done when it merges.
  Closing an issue linked on a card moves that card to Done; reopening it moves
  the card back to In progress. Labelling an issue on GitHub adds the board
  label of the same name to every card linked to it, and unlabelling takes it
  back off — a name your board doesn't already define is simply ignored, never
  created. Opt-in **issue intake**: pick a column in the
  board's webhook settings and every newly opened issue (optionally filtered to
  one label) becomes a linked card there — title plus issue link only, no body
  copy. No credentials, no OAuth. A board can run both webhooks at once; Gitea
  webhooks work with the Forgejo endpoint. For self-hosted forges Kanso is
  **receive-only** — it never calls your instance, so link badges come from the
  deliveries themselves.
- **MCP server (AI access)**: an optional
  [Model Context Protocol](https://modelcontextprotocol.io) server (under
  [`mcp/`](mcp/README.md)) lets Claude and other MCP clients read and manage
  your boards through Kanso's API — see [MCP server](#mcp-server-ai-access).

### 📊 Views
- **Board, List and Timeline** views: switch per board (remembered per user).
  The list is a dense, scannable table; the **Timeline (Gantt)** plots cards on a
  date axis by **start → due**, with due-only cards as milestones.
- **Swimlanes**: split the board into rows grouped by **assignee**, **label** or
  **priority**. A view over the cards you already have, remembered per board in
  your own browser — not lanes you create and maintain.
- **Saved filters**: name the filter you're looking at and come back to it.
  Private to you, per board.
- **Display sort**: order cards by priority, due date or title. View-only: your
  manual drag order is always preserved.

<img src="docs/kanso-timeline.png" alt="Kanso Timeline (Gantt) view: cards plotted on a date axis by start → due, with a due-only card shown as a milestone" width="900">

### 🧭 Cross-board hub & projects
- **My Work** hub gathers, across every board: **My tasks** (cards assigned to
  you), **Reviews** (waiting on you), and an **Inbox** of mentions and activity
  on cards you watch — filterable to a single board.
- **Projects**: cross-board card collections with markdown descriptions and
  per-project analytics.

### 📈 Analytics
- Per-board (and per-project) stats: **velocity** (cards/points per week with
  trend), **cycle time** (median/average days to done), **throughput** (done
  per day), plus breakdowns by stack, priority, assignee and label, and
  overdue / aging / checklist-progress signals.

<img src="docs/kanso-analytics.png" alt="Kanso board analytics: cards by stack, priority and label, plus a weekly velocity trend" width="900">

### ⌨️ Power-user UX
- **Command palette** (`Ctrl`/`Cmd`+`K`) and full-text **search** across cards
  and comments.
- **Keyboard-first** navigation, **undo toasts** for destructive actions, and a
  **trash** with restore.

### 🌍 Localization
- **Follows your Nextcloud language** automatically. Kanso ships translations for
  <!-- l10n:languages -->German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese, Russian, Turkish and Simplified Chinese<!-- /l10n:languages -->.
  More languages are welcome — see [docs/TRANSLATING.md](docs/TRANSLATING.md)
  (no code required).

## Installation

Kanso targets **Nextcloud 32–34** and **PHP 8.2–8.5**. It's on the
**[Nextcloud App Store](https://apps.nextcloud.com/apps/kanso)**, so the quickest
install is the one-click route: in Nextcloud open **Apps**, find **Kanso** under
*Organization* (or *Office & text*), and click **Download and enable**. From the
command line:

```sh
cd /path/to/nextcloud
sudo -u www-data php occ app:install kanso
```

Open **Kanso** from the Nextcloud app menu and create your first board. Upgrades
arrive through Nextcloud's own app updater.

<details>
<summary><b>Install the release tarball manually</b> (no Node or Composer needed on your server)</summary>

If your server can't reach the App Store, or you'd rather pin an exact build,
install the pre-built tarball from
[GitHub Releases](https://github.com/aktasfatih/kanso/releases):

```sh
# 1. Download the tarball from the latest release
curl -fLO https://github.com/aktasfatih/kanso/releases/latest/download/kanso.tar.gz

# 2. Extract into your Nextcloud "custom apps" directory (unpacks as kanso/)
tar -xzf kanso.tar.gz -C /path/to/nextcloud/custom_apps/
chown -R www-data:www-data /path/to/nextcloud/custom_apps/kanso

# 3. Enable the app
cd /path/to/nextcloud
sudo -u www-data php occ app:enable kanso
```

To upgrade a tarball install, extract the new tarball over the old directory and
run `occ upgrade`.

</details>

<details>
<summary><b>Install from source</b> (needs Node 20+, Composer, shell access)</summary>

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

</details>

- **Background jobs** (recurring cards, auto-archive, reminders, email intake,
  scheduled backups, change-log pruning) run through Nextcloud's cron. Make sure
  [system cron](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/background_jobs_configuration.html)
  is configured.
- **Realtime updates** use the [High Performance Backend (`notify_push`)](https://github.com/nextcloud/notify_push)
  when it's installed; otherwise Kanso falls back to polling automatically.

### Admin settings (`occ`)

A few instance-wide knobs live in Kanso's app config rather than a settings page.
All are **optional** — unset means the behaviour described as the default.

| Key | Default | What it does |
| --- | --- | --- |
| `attachment_storage_limit` | unset — **no limit** | Total bytes Kanso may store in its own app data, across the whole instance. |
| `mail_intake_allow_private_hosts` | `no` | Lets board mailboxes point at private/LAN addresses — see [email intake](docs/email-intake.md). |

Card attachments are kept in the app's own storage, not in the uploader's Files,
so they don't count against anyone's Nextcloud quota. On an instance where every
account is trusted that's usually what you want. If it isn't, set a ceiling in
bytes:

```sh
# Allow Kanso a total of 10 GiB of attachments instance-wide
sudo -u www-data php occ config:app:set kanso attachment_storage_limit --value 10737418240

# Remove the ceiling again
sudo -u www-data php occ config:app:delete kanso attachment_storage_limit
```

Once the total is reached, new uploads (and "attach from Files" copies) are
refused with **413**, and a Deck import skips the attachments that no longer fit
(reporting them as skipped) rather than failing the whole import. Existing
attachments can still be listed, downloaded and deleted, so users can free space
themselves. Restoring a board from a Kanso export is exempt — an archive is
already bounded by its own size limit, and stopping a restore midway would leave
a half-restored board.

### Scheduled board backups

Kanso can write a timestamped `.zip` export of every board on cron, keeping the
most recent few per board. Turn it on in **Administration settings → Kanso**,
where you also set how many backups to keep per board and whether a finished run
announces itself to the administrators (never, only on failure — the default —
or after every run). That message goes to everyone in the administrators group,
and each run replaces the previous one, so a backup that fails every night
leaves a single unread notification rather than one per night.

The admin panel states each of these in a line or two; this section is the long
version of the same facts.

**Where they are written** is yours to choose, and the two options trade off
against each other:

- **Inside Kanso** (the app's own app data, and the default for a new
  installation) keeps the archives in Kanso's storage, alongside card
  attachments. Nothing is written into anyone's Files, so a run adds no Files
  activity entries and the archives are outside every account's quota. In
  exchange they are not browsable, syncable or mountable anywhere: the **Stored
  backups** list at the bottom of the admin panel is the only way to get one
  back, and a backup that ages out of retention is deleted outright rather than
  moved to a trashbin.
- **In a Files folder** writes them into a Nextcloud folder under an account you
  name. This is the one to pick when you want the copies off this server or
  simply want to see them — it is the only destination you can browse, sync, or
  back with an S3 External Storage mount (Kanso writes files through Nextcloud
  and never holds S3 credentials). It costs what any file write costs: the
  activity entries described below, that account's quota (a full quota fails the
  run), and pruned backups landing in its trashbin.

The panel shows the fields for the destination you picked and only those: choose
**Inside Kanso** and the account and folder fields disappear, because nothing
would read them. Whatever you typed is kept, so switching back brings it
straight back.

Downloading a stored backup is an administrator-only action, whichever
destination is in use: an archive is a full export of a board including cards and
attachments that ordinary members may not be able to see, so it is never
shareable by link and never served to a non-administrator.

**Upgrading?** Nothing moves. An installation that already had a target folder
configured stays on that folder — the new default applies to fresh installations
and to instances that never configured backups at all. Switch destinations
whenever you like; the folder and account you typed are remembered either way.

**Keeping the backups out of your own activity feed** (Files folder only).
Nextcloud's Activity app
records a *file created* entry for every backup written and a *file deleted*
entry for every one that ages out of retention, so a run over N boards adds up
to 2N entries to the activity stream of whichever account owns the target folder.
Those entries come from Nextcloud's own Files hooks rather than from Kanso, and
no app API in Nextcloud 32–34 suppresses them for an individual write. Kanso
does not pretend otherwise: it will not silently stop logging your backups, and
the run notification setting above is about Kanso's own message — setting it to
*Never* leaves those Files entries exactly where they were.

What you *can* choose is whose feed they land in. Set **Account that owns the
folder** to a dedicated service account nobody signs in to, and
the entries land in that account's stream instead of yours. The tradeoffs are
real and worth stating up front: the backups then live in that account's Files,
so you no longer see them in your own — and if you share the folder back to
yourself to browse or download them, the activity entries come with it.

The other way out is the app-data destination above, which leaves no entries for
a different reason: nothing is written into a user folder at all, so Nextcloud's
Files hooks have nobody to record the write for. That is a property of where the
bytes land, not of Kanso hiding anything — pick the Files folder and its entries
are recorded exactly as before.

### Offline data on the device

Kanso is an installable PWA: a service worker caches the app shell, and the
boards you have opened are kept in the browser's own storage, so Kanso starts
and shows your last-known board with no network. That copy expires after 24
hours, and it is deleted as soon as a different account is detected.

On a shared device, **logging out is not sufficient on its own**: a device that
is offline at the next start cannot reach the server to learn who is logged in,
so it can still show the previous user's cached boards until it regains contact.
On a shared or kiosk device, don't install Kanso as an app, or clear the
browser's site data after use.

### Try it locally (no Nextcloud required)

The repo ships a throwaway Docker dev stack:

```sh
npm install
npm run build
cd dev && ./setup.sh   # boots Nextcloud + Postgres and enables Kanso
```

Then open <http://localhost:8891> (login `admin` / `admin`).

To try another supported Nextcloud version or database (the same knobs CI's
cross-version matrix uses):

```sh
NC_VERSION=32 KANSO_DB=postgres ./setup.sh   # NC 32 on Postgres
NC_VERSION=32 KANSO_DB=sqlite   ./setup.sh   # NC 32 on SQLite (no db container)
NC_VERSION=34 KANSO_DB=mysql    ./setup.sh   # NC 34 on MariaDB
```

The boot also side-loads two optional Nextcloud apps (Deck and Contacts) that a
couple of the end-to-end tests need. They're downloaded from GitHub; if you're
offline, skip them — only those two tests care:

```sh
KANSO_SKIP_OPTIONAL_APPS=1 ./setup.sh
```

## MCP server (AI access)

Kanso ships an optional **[Model Context Protocol](https://modelcontextprotocol.io)
server** (under [`mcp/`](mcp/README.md)) so AI assistants — Claude Code, Claude
Desktop, and any MCP client — can read and manage your boards: list/create
boards, add columns and cards, move cards, set labels and assignees, and pull
the cards assigned to you. It talks to Kanso's REST API using a Nextcloud **app
password**, so there's nothing extra to install on the server.

It authenticates with a revocable Nextcloud **app password** (not your login
password) kept in the **server's** own `.env` or environment — so **no
credentials ever live in your MCP client config**.

**Quickstart (Claude Code)** — needs Python 3.11+ and [`uv`](https://docs.astral.sh/uv/):

```sh
# 1. In Nextcloud: Settings → Security → Create new app password (revocable; not your login password)
# 2. Give it to the server — credentials stay server-side:
cd mcp && cp .env.sample .env       # then edit .env with your host, user and the app password
# 3. Register the server with your client — note: NO credentials here:
claude mcp add kanso -- uv run --directory /path/to/kanso/mcp kanso-mcp
```

For the HTTP-service setup, Claude Desktop config, and the full tool list, see
**[`mcp/README.md`](mcp/README.md)**. The MCP server is a separate artifact — it
is **not** bundled into the installed Nextcloud app.

## Development

The dev stack mounts your checkout as `custom_apps/kanso`, so a rebuild
(`npm run build`) plus a browser reload picks up frontend changes; PHP changes
apply immediately. In `dev/`:

```sh
docker compose down && ./setup.sh                    # restart: install + data survive
docker compose --profile '*' down -v && ./setup.sh   # reset: wipes everything
```

`--profile '*'` on the reset matters — `-v` only removes the volumes of services
in active profiles, so a bare `docker compose down -v` deletes the webroot but
leaves the database, and the next boot fails trying to install over it. Reset
(not restart) after switching `NC_VERSION` or `KANSO_DB`, and after **adding a
migration**: `setup.sh` only re-enables the app, and Nextcloud re-runs an app's
migrations only when `info.xml`'s `<version>` grows, which feature branches never
do. `./seed.sh` refills a reset stack; `./smoke.sh` reports a schema left behind.

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
npm run test:e2e                           # Playwright e2e (needs the dev stack up)
docker run --rm -v "$PWD":/app -w /app php:8.2-cli-alpine \
  php vendor/bin/phpunit -c phpunit.xml    # PHP unit tests
```

## Tech

PHP (Nextcloud App Framework, own `kanso_*` tables) · Vue 3 + `@nextcloud/vue` +
Vite · TanStack Query · Pragmatic drag-and-drop · TanStack Virtual · Postgres /
MySQL / SQLite. Kanso is independent. It does **not** depend on Deck and stores
its own data.

## Status & contributing

Actively developed and usable day-to-day, with a broad feature set already
shipped (boards, the cross-board My Work hub, projects, analytics, reviews,
recurring cards, realtime, attachments, custom fields, time tracking, calendar
sync, and imports from Deck, Trello and CSV). Bug reports and pull requests are
welcome. See the [issues](https://github.com/aktasfatih/kanso/issues)
and [CONTRIBUTING.md](CONTRIBUTING.md).

**Before a release**, re-check that this README and `appinfo/info.xml`
(`<summary>` / `<description>`) still match the shipped feature set — verify
against the code, and don't claim anything that isn't actually wired up.

## Supporting Kanso

Kanso is built and maintained by one person, and the whole app is free for
everyone under the AGPL. That isn't going to change: there is no paid tier, no
license key, and no feature held back for people who pay.

What sponsorship buys is **priority**. Feature requests go into a queue, and the
queue is longer than the week — sponsoring moves yours up it. Whatever gets built
still ships to everyone in the next release.

- **[GitHub Sponsors](https://github.com/sponsors/aktasfatih)** — monthly or
  one-off. If a specific issue is what you care about, say so and it gets
  scheduled next.
- **[Rate the app](https://apps.nextcloud.com/apps/kanso)** — costs nothing, and
  it does more for Kanso's reach than a small donation does.
- **[Commercial licensing](#commercial-licensing)** — for organizations that
  can't take on AGPL obligations.

### Sponsors

Thank you to [@dbmtrde](https://github.com/dbmtrde) for sponsoring Kanso.

## License

Kanso is free and open source under the [AGPL-3.0-or-later](LICENSE), and always
will be for the community.

### Commercial licensing

The AGPL requires that anyone running a modified version — including over a
network — makes their source available under the AGPL. Some organizations cannot
accept those terms. If you need to use Kanso without AGPL obligations, a separate
commercial license is available. Reach out at **akfatih2@gmail.com**.

Contributions are accepted under a [CLA](CLA.md) that keeps this dual-licensing
possible — see [CONTRIBUTING.md](CONTRIBUTING.md).
