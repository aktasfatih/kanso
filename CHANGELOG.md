<!--
  - SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

All notable changes to Kanso are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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

First public release, published to the Nextcloud App Store. Targets
Nextcloud 30–32 and PHP 8.2+.

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

[Unreleased]: https://github.com/aktasfatih/kanso/compare/v0.9.1...HEAD
[0.9.1]: https://github.com/aktasfatih/kanso/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/aktasfatih/kanso/releases/tag/v0.9.0
