<!--
  - SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

All notable changes to Kanso are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **My Work pages stay current without a manual reload.** My Tasks, My Reviews
  and Inbox now refresh when you change something (e.g. assign yourself a card),
  when you navigate to them, and on a live poll while the page is open —
  previously they could keep showing data from earlier in the session until a
  browser refresh.
- **Open cards update in realtime across tabs and sessions.** Edits to a card's
  title, description, comments or checklist now appear in an already-open card
  in another tab as the change arrives (near-instant when notify_push is
  available, on the delta poll otherwise), without overwriting an edit you have
  in progress.

## [0.9.35] - 2026-08-09

Consolidates the 2026-08-09 sprint. Versions 0.9.32–0.9.34 were internal
per-feature version bumps on the release branch and were never published; their
changes all land here.

### Added

- **Card visibility: public / internal / private.** Every card now carries a
  visibility level. *Public* (the default, and the behavior of all existing
  cards) is visible to every board member; *internal* is visible only to the
  creator's side of the board (see member roles below) — symmetric, so a
  client-side internal card is equally hidden from the provider side, with no
  owner or manager backdoor; *private* is visible to its creator alone. The
  rule is enforced in SQL on every read path — board payload, delta sync,
  search, My Work dashboards, stats and tile counts, trash, export, duplicate,
  the public share snapshot and the calendar feed — and a hidden card behaves
  exactly like a missing one on card-addressed endpoints (404, never a 403
  existence oracle). Background emissions honour it too: due reminders,
  comment/mention fan-outs, watcher notifications, activity and webhook egress
  never reach (or name the card to) a user outside its visibility, stale bell
  entries stop rendering if a card narrows after they were queued, and
  deferred (stage-gated) review-request notifications re-check visibility at
  fire time.
- **Board member roles: internal vs external.** A board member is now shared
  in as either *internal* (your own team) or *external* (the client/partner
  side). The role feeds the internal card-visibility fence and freezes onto
  what each member creates, and can be changed later by a board manager.
- **Rich checklist steps.** A checklist item can now carry an assignee (with
  their board side frozen at assignment time), a due date with overdue
  styling, and a done-at stamp — plus a cross-board **My steps** feed
  (`/api/my-steps`) of every open step assigned to you, visibility-scoped like
  every other feed.
- **Derived "waiting on client" status.** A card with at least one open step
  parked on the external side shows a "waiting since …" chip on its tile and
  can be filtered on — computed live from step state, never stored, so it can
  not drift.
- **GitHub issue intake (opt-in).** The board's GitHub webhook can now react
  to issue events and — when explicitly enabled — auto-create a linked card
  when an issue opens, using the existing HMAC-verified endpoint. The webhook
  response body never names anything beyond public cards.
- **Board tile menu.** The boards grid tile gains a context menu: duplicate,
  export, archive and delete a board without opening it.
- **Per-board project chat link.** A board can carry a chat URL (Talk, Slack,
  …) surfaced as a toolbar deep-link button.
- **Admin setup check.** Nextcloud's admin overview now warns when background
  jobs run on AJAX cron or `overwrite.cli.url` is unset — both degrade
  reminders, recurrence and webhook delivery.

### Fixed

- **Public share snapshot queries are public-only end to end.** The anonymous
  board snapshot now restricts its label-association query to public cards in
  SQL (as the checklist counts already were) instead of fetching every card's
  labels and discarding the hidden ones in PHP.

## [0.9.31] - 2026-08-08

First tagged beta, published as a pre-built tarball on
[GitHub Releases](https://github.com/aktasfatih/kanso/releases/tag/v0.9.31).

### Added

- **Import cards from a CSV / spreadsheet.** The board-list Import menu now has a
  working "CSV file" entry (#3678): upload or paste a CSV, map its columns
  (title required; optional description, due date, comma-separated labels and
  assignees) with sensible header auto-detection, then add the rows as cards to a
  board and column you choose. Labels are matched-or-created on the target board;
  assignees are matched-or-dropped, filtered by READ so an import never
  references someone who cannot see the board. The whole import is a single
  all-or-nothing transaction (byte-capped before parsing, row-capped, long titles
  truncated), EDIT on the target board is required, and the cards append to the
  chosen stack with a single realtime push. This is the "add my spreadsheet of
  tasks to an existing board" case only — creating a whole new board from a CSV
  is out of scope (the Deck/Trello importers cover whole-board creation).
- **iCal / ICS calendar feed of card due dates.** A board manager can now expose
  a read-only calendar feed of the board's card due dates (Board settings →
  Automation → Calendar feed), subscribable in any calendar client (Nextcloud
  Calendar, Thunderbird, phone). It is OFF by default; enabling mints a long,
  unguessable token, and the feed URL is revocable (disable) and rotatable
  (rotate mints a fresh one, invalidating the old URL immediately). The feed is
  deliberately minimal: one event per card that has a due date, carrying only the
  card title, the due date (honouring the all-day flag) and a link back to the
  card — never descriptions, assignees or any other data, and never cards from
  another board. It is read-only (no write-back); full two-way CalDAV sync is a
  separate feature. The public feed endpoint is brute-force throttled so tokens
  can't be enumerated.

- **Public / read-only board share links.** A board manager can now mint a
  public, unauthenticated, read-only link to a whole board (Board settings →
  Automation → Public link). It is OFF by default; enabling mints a long,
  unguessable token, and the link is revocable (disable clears it) and rotatable
  (rotate mints a fresh one, invalidating the old link immediately). The public
  view is deliberately stripped: it shows only the board title, columns and
  per-card title, description, labels, due date, checklist progress, priority,
  status and human id — never assignees, comments, activity, members, owners or
  any other people/internal data. The public endpoints are brute-force throttled
  so tokens can't be enumerated.

- **File attachments on cards.** A card now has an Attachments section: upload a
  file (picker), see it listed with its name and size, download it, and delete it
  (EDIT-gated). Files are stored in Kanso's own app-data — not in anyone's
  personal Files — and served through a board-permission-gated endpoint (READ to
  view/download, EDIT to upload/delete). The on-disk name is server-generated
  (the client filename is kept only as a display label, so path traversal is
  impossible), uploads are size-capped, and downloads are always forced as an
  attachment with `nosniff` so an uploaded HTML/SVG can never render inline. The
  card detail payload carries an attachment count, and add/delete emit a
  change-log row so delta-sync and ETags stay correct.

- **Project discussion log (owner-only comments on a project).** The project page
  now carries a comment thread — post, edit, delete, with one-level replies and
  the same markdown toolbar + rendering as card comments. Because projects are
  owner-only (no sharing), it is a private per-owner log: every operation is
  owner-gated and there is no @mention/notify. New table `kanso_project_comments`
  and endpoints under `/api/projects/{id}/comments`.

- **Board export / import (full data portability).** Every board can be exported
  to a single Kanso JSON document (board settings → Export board) carrying its
  whole graph — stacks, cards, labels, review types, checklist items, comments
  (with threading), card↔label / assignee links, archive rules and recur rules.
  Uploading that file from the board list (Import → Kanso export) recreates the
  whole board with fresh ids under the importer, remapping every internal
  reference and preserving sort keys. The import is all-or-nothing, size-capped,
  and rejects unknown/future export versions. This is Kanso's own round-trippable
  format, distinct from the one-click Deck importer.

### Changed

- **Nextcloud 34 and PHP 8.3 support.** The supported range is now Nextcloud
  30–34 on PHP 8.2–8.3 (previously 30–32). Verified against `nextcloud:34`:
  clean install/upgrade, all migrations apply, full PHPUnit suite and the e2e
  smoke set pass on PHP 8.3, and realtime push (notify_push) works. Existing
  NC 30–33 installs are unaffected.

- **Cross-version install/migration CI matrix.** CI now boots a throwaway
  Nextcloud on every supported major (30, 31, 32, 33, 34) across SQLite and
  PostgreSQL — plus MariaDB on NC 34 — enables Kanso, runs `occ upgrade`, and
  asserts every migration applied and the schema was physically created (the
  check that guards against the class of migration bug where an over-long,
  default-named index silently creates zero tables). PHPUnit continues to run in
  the dedicated `unit-php` job across PHP 8.2 and 8.3; the full Playwright e2e
  suite continues to run on NC 34 + PostgreSQL only. The dev stack
  (`dev/setup.sh`) is now version/DB-parametrized via `NC_VERSION` and
  `KANSO_DB`.

### Fixed

- **Install on Nextcloud 30–32 (over-long primary-key names).** Several tables
  relied on the database's default-generated PRIMARY KEY name, which on NC 30–32
  can exceed Nextcloud's 23-character index-name limit and abort app install
  before any table is created (NC 33/34 relaxed the check, so this only affected
  older versions). Each affected table now declares an explicit short PK name.
  Surfaced by the new cross-version CI matrix. Existing installs are unaffected
  (the table-creation migrations are `hasTable`-guarded).

## [0.9.2] - 2026-08-01

### Changed

- **Faster "My tasks" and label deletion on large instances.** Added two
  hot-path database indexes: `kanso_card_assignees(participant, type)` so the
  cross-board assigned-cards dashboard query is a range seek instead of a full
  scan of the assignee table per user, and `kanso_card_labels(label_id)` so
  deleting a label targets its rows instead of full-scanning and lock-holding
  the label join table. Additive schema migration, no data changes.

## [0.9.1] - 2026-08-01

### Fixed

- **Recurring cards catch up on missed occurrences.** A delayed or downed cron
  now spawns one card per missed occurrence (e.g. a server off for three days
  backfills the three cards it owed), instead of a single card per run. Catch-up
  is bounded per run so a long-dormant rule can't flood a board; the remainder
  continue on the next run.
- **Recurring schedules are timezone-stable across DST.** A rule now carries an
  IANA timezone (defaulting to the owner's Nextcloud personal timezone, server
  default as fallback) and is expanded as floating wall-clock time (RFC 5545 /
  CalDAV): "daily at 09:00" fires 09:00 local on both sides of a daylight-saving
  transition. Existing rules with no timezone fall back to the server timezone.

## [0.9.0] - 2026-07-30

First public release. Targets Nextcloud 30–32 and PHP 8.2+.

### Added

- **Boards, stacks and cards** with instant, optimistic drag & drop. A card move
  is a single-row update backed by fractional sort keys, never a bulk renumber.
- **Large-board performance**: summary-only board payloads, `ETag` /
  `If-None-Match` caching, and virtualized columns that stay smooth past
  2,000+ cards.
- **Rich cards**: sanitized markdown descriptions, labels, due dates, assignees,
  priorities, checklists / sub-tasks, and parent ↔ child cards (a parent
  auto-completes when all its children are done).
- **Comments** with threaded replies, **@mentions**, and **watchers** on cards,
  comment threads, or a whole board.
- **Board sharing** with per-user and per-group access control.
- **Review workflow**: request a review, then approve or request changes;
  customizable review types (QA, Code, Legal, …); and an optional done-gate that
  blocks a card from leaving a review column until every review is approved.
- **My Work hub**: a cross-board view of My tasks (cards assigned to you),
  Reviews (waiting on you), and an Inbox of mentions and watched-card activity,
  filterable to a single board.
- **Projects**: cross-board card collections with markdown descriptions and
  per-project analytics.
- **Analytics** (per-board and per-project): velocity (cards/points per week with
  trend), cycle time (median/average days to done), throughput, plus breakdowns
  by stack, priority, assignee and label, and overdue / aging /
  checklist-progress signals.
- **Stack roles and WIP limits**: moving a card into an "in progress" column
  auto-starts it and a "done" column stamps it done; status can also be set
  directly on the card.
- **Recurring cards** on RRULE schedules and **auto-archive** rules for done
  cards.
- **Board, List and Timeline (Gantt) views**, remembered per user, plus a
  view-only display sort (by priority, due date or title) that preserves the
  manual drag order.
- **Command palette** (`Ctrl` / `Cmd` + `K`) and full-text search across cards
  and comments.
- **Trash with restore** and undo toasts for destructive actions.
- **GitHub links**: attach PRs/issues with live open/merged/closed badges and a
  ready-made `kanso-<id>` branch name, plus an HMAC-verified **GitHub webhook**
  that moves a card to your Review column when its PR opens and to Done when it
  merges.
- **Import from Deck**: one click copies a Deck board (stacks, cards, labels,
  assignees) into a new Kanso board you own, leaving your Deck boards untouched.
- **Realtime updates** via `notify_push` (High Performance Backend) when
  available, with an automatic light polling fallback everywhere else.

[Unreleased]: https://github.com/aktasfatih/kanso/compare/v0.9.35...HEAD
[0.9.35]: https://github.com/aktasfatih/kanso/compare/v0.9.31...v0.9.35
[0.9.31]: https://github.com/aktasfatih/kanso/compare/v0.9.2...v0.9.31
[0.9.2]: https://github.com/aktasfatih/kanso/compare/v0.9.1...v0.9.2
[0.9.1]: https://github.com/aktasfatih/kanso/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/aktasfatih/kanso/releases/tag/v0.9.0
