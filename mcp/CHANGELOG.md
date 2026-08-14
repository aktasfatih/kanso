<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

All notable changes to the Kanso MCP server are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-08-14

### Added

- Initial Kanso MCP server (Python, FastMCP + httpx + pydantic).
- stdio transport (default) plus an optional `--http` streamable-http mode
  (host/port from `MCP_HOST` / `MCP_PORT`, default `127.0.0.1:8001`).
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
