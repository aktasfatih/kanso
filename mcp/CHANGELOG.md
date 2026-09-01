<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

All notable changes to the Kanso MCP server are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `kanso_search_cards` — find cards by text (title, description or a comment
  body) across every board you can read, or within one board via `board_id`.
  Wraps the app's existing search endpoint, so results are ACL-filtered
  server-side to your readable boards; `board_id` only narrows that set. Until
  now the only way to find a card by text was to pull a whole board with
  `kanso_get_board` and match substrings client-side, which could not search
  across boards at all.

### Changed

- `kanso_get_board` no longer returns archived cards by default. The board
  endpoint ships archived and live cards in one list, so every caller used to
  get a mixed set with no way to separate them; `cards` is now the live board.
  Pass the new `include_archived=true` argument to get archived cards back —
  every card keeps its `archived` flag either way, and only `cards` is filtered
  (stacks, labels and the rest of the payload are untouched).
- `kanso_list_my_cards` documents what it has always returned: open work only —
  never archived or done cards, capped at 200.

## [0.1.0] - 2026-08-14

### Added

- Initial Kanso MCP server (Python, FastMCP + httpx + pydantic).
- stdio transport (default) plus an optional `--http` streamable-http mode
  (host/port from `MCP_HOST` / `MCP_PORT`, default `127.0.0.1:7654`).
- HTTP Basic auth with a Nextcloud app password; every request sends the
  `OCS-APIRequest: true` header so Nextcloud treats it as an API call (CSRF-skip).
- Board tools: `kanso_list_boards`, `kanso_get_board`, `kanso_create_board`,
  `kanso_update_board`, `kanso_delete_board`.
- Stack tools: `kanso_create_stack`, `kanso_update_stack`, `kanso_delete_stack`,
  `kanso_move_stack`.
- Card tools: `kanso_get_card`, `kanso_create_card`, `kanso_update_card`,
  `kanso_delete_card`, `kanso_move_card`.
- Card label/assignee tools: `kanso_assign_label`, `kanso_unassign_label`,
  `kanso_assign_user`, `kanso_unassign_user`, plus `kanso_create_label`.
- Cross-board "My tasks" tool: `kanso_list_my_cards`.
