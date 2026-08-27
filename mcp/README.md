<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Kanso MCP server

A [Model Context Protocol](https://modelcontextprotocol.io) server that exposes
the [Kanso](https://github.com/aktasfatih/kanso) Nextcloud kanban app to MCP
clients (Claude Code, Claude Desktop, etc.). It talks to Kanso's own REST API
(`{host}/index.php/apps/kanso/api`).

This server ships **inside** the Kanso repo (under `mcp/`) but is a **separate
artifact**: it is excluded from the Nextcloud app tarball and has its own
version and changelog.

## How auth works (read this first)

The server authenticates to Nextcloud with an **app password** — a scoped,
revocable token you generate in Nextcloud, **not** your login password. You can
revoke it at any time without touching your account, and it only grants what a
normal Nextcloud API session grants for your user.

Credentials live **with the server** (in a `.env` file or the server's
environment). **Your MCP client config never contains a password** — it only
says how to reach the server.

## Requirements

- Python 3.11+ and [`uv`](https://docs.astral.sh/uv/) (or `pip`)
- A Nextcloud instance with the Kanso app installed

## 1. Create an app password

In Nextcloud: **Settings → Security → Devices & sessions → Create new app
password**. Copy the generated token.

## 2. Give the credentials to the server (not the client)

Copy the template and fill it in — `.env` is gitignored and read by the server
at startup:

```sh
cd mcp
cp .env.sample .env
# edit .env: NEXTCLOUD_HOST, NEXTCLOUD_USERNAME, NEXTCLOUD_PASSWORD (the app password)
```

| Variable             | Required | Description                                           |
| -------------------- | -------- | ----------------------------------------------------- |
| `NEXTCLOUD_HOST`     | yes      | Base URL, e.g. `https://cloud.example.com`            |
| `NEXTCLOUD_USERNAME` | yes      | Your Nextcloud user id                                |
| `NEXTCLOUD_PASSWORD` | yes      | The **app password** from step 1                      |
| `VERIFY_SSL`         | no       | `false`/`0` to skip TLS verification (default `true`) |
| `MCP_HOST`           | no       | HTTP-mode bind host (default `127.0.0.1`)             |
| `MCP_PORT`           | no       | HTTP-mode bind port (default `7654`)                  |

(Real environment variables also work and take precedence over `.env` — handy
for systemd / container secrets.)

## 3. Run it

### stdio (simplest — the client launches it on demand)

```sh
uv run kanso-mcp        # run from the mcp/ directory so it finds ./.env
```

### HTTP service

```sh
uv run kanso-mcp --http   # serves MCP at http://127.0.0.1:7654/mcp
```

Bound to `127.0.0.1`, so it's reachable only from the same machine. Run it under
systemd, Docker (below), or `tmux` to keep it alive.

### Docker

Build and run the HTTP service in a container — it reads the same `.env`:

```sh
docker build -t kanso-mcp ./mcp
docker run --rm -p 127.0.0.1:7654:7654 --env-file mcp/.env kanso-mcp
```

or with Compose, from the `mcp/` directory:

```sh
docker compose up -d      # builds, reads ./.env, serves http://127.0.0.1:7654/mcp
```

The port is published to `127.0.0.1` only. Then add the **HTTP** client entry below.

## 4. Point your MCP client at it — no credentials in the client config

Because the server already has the credentials (step 2), the client config only
needs to know how to reach it.

**stdio** (the client spawns the server; point `--directory` at this folder):

```json
{
  "mcpServers": {
    "kanso": {
      "command": "uv",
      "args": ["run", "--directory", "/absolute/path/to/kanso/mcp", "kanso-mcp"]
    }
  }
}
```

or from the Claude Code CLI:

```sh
claude mcp add kanso -- uv run --directory /absolute/path/to/kanso/mcp kanso-mcp
```

**HTTP** (server already running from step 3 — just a URL):

```json
{ "mcpServers": { "kanso": { "type": "http", "url": "http://127.0.0.1:7654/mcp" } } }
```

or:

```sh
claude mcp add --transport http kanso http://127.0.0.1:7654/mcp
```

## Tools

**Boards**

- `kanso_list_boards` — list all boards (with per-board stats)
- `kanso_get_board` — read a board: stacks, labels, card summaries (archived
  cards are excluded unless you pass `include_archived=true`)
- `kanso_list_board_members` — list assignable users (uid + name) for a board
- `kanso_create_board` / `kanso_update_board` / `kanso_delete_board`

**Stacks (columns)**

- `kanso_create_stack` / `kanso_update_stack` / `kanso_delete_stack`
- `kanso_move_stack` — reorder a stack

**Cards**

- `kanso_get_card` — full card incl. description
- `kanso_create_card` / `kanso_update_card` / `kanso_delete_card`
- `kanso_move_card` — move a card into a stack (optionally after a card)

**Relations & subtasks**

- `kanso_list_relations` — a card's relations grouped by kind (blocks / blockedBy / duplicates / relates)
- `kanso_add_relation` — link two same-board cards (blocks / blocked_by / duplicates / relates)
- `kanso_remove_relation` — remove a relation by its id
- `kanso_set_card_parent` — set or clear a card's parent (one-level subtasks)

**Labels & assignees**

- `kanso_create_label` — create a board label
- `kanso_assign_label` / `kanso_unassign_label`
- `kanso_assign_user` / `kanso_unassign_user`

**Reviews** (hand work back to a human without it getting lost)

- `kanso_request_review` — ask a user to review a card; lands in their "My Reviews" feed and notifies them (idempotent)
- `kanso_withdraw_review` — cancel a pending request by its **review** id
- `kanso_list_my_reviews` — every review requested from you, across all boards

There is deliberately **no** tool for recording a verdict. The server only lets
the reviewer set their own review state, and this server authenticates as that
same user — exposing it would let an agent approve the very review it just
requested. Approving/rejecting stays a human action in the UI.

**Comments**

- `kanso_list_comments` — read a card's comments (author + body + timestamps)
- `kanso_add_comment` — post a comment (optionally reply to a comment)

**Checklist**

- `kanso_list_checklist` — read a card's checklist items (id + title + done)
- `kanso_add_checklist_item` — add a checklist item to a card
- `kanso_toggle_checklist_item` — mark an item done / not done

**Recurrence (repeating cards)**

Recurrence is board automation, not a card field: a rule is anchored on a
*template* card and spawns into a target stack on an RFC 5545 `RRULE`. To make a
card repeat, create the card first, then create a rule with its id as the
template. Mutations need MANAGE on the board.

The schedule's `DTSTART` anchor is derived by the server, never passed to
`kanso_create_recur_rule`: it is the template card's **start date**, else its
**due date**, else the **rule's creation time**. Because `DTSTART` is part of
the recurrence set, a template card dated in the *future* first fires on that
date to the minute — regardless of any `BY*` part in the rule, which only
shapes the occurrences after it. So date the template card (via
`kanso_update_card`) *before* creating the rule if you care where the series
starts.

- `kanso_list_recur_rules` — a board's repeat schedules (+ next occurrence)
- `kanso_create_recur_rule` — repeat a template card (rrule, CLONE/RESET mode, due-date policy, timezone)
- `kanso_update_recur_rule` — edit a rule; `enabled=false` pauses it
- `kanso_delete_recur_rule` — drop the schedule (template + spawned cards stay)
- `kanso_recur_rule_create_now` — fire once now without touching the schedule

**Search**

- `kanso_search_cards` — find cards by text across every board you can read
  (or one board with `board_id`). Plain case-insensitive substring matching over
  card titles, card descriptions and comment bodies — no query language, no
  AND/OR, no `field:value`. Each hit is a locator (`type`, `cardId`, `boardId`,
  `title`, `snippet`, `rank`); feed `cardId` to `kanso_get_card` for the full
  card. The server ACL-filters the results to your readable boards, and
  `board_id` only narrows that set.

**My work**

- `kanso_list_my_cards` — open cards assigned to you across all boards (never
  archived or done cards)

## Development

```sh
# Unit tests (respx-mocked, no live server needed). Same command CI runs in the
# `unit-mcp` job: --extra dev pulls pytest, --frozen pins uv.lock exactly.
uv run --frozen --extra dev pytest
```

## License

AGPL-3.0-or-later.
