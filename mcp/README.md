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
- `kanso_get_board` — read a board: stacks, labels, card summaries
- `kanso_list_board_members` — list assignable users (uid + name) for a board
- `kanso_create_board` / `kanso_update_board` / `kanso_delete_board`

**Stacks (columns)**

- `kanso_create_stack` / `kanso_update_stack` / `kanso_delete_stack`
- `kanso_move_stack` — reorder a stack

**Cards**

- `kanso_get_card` — full card incl. description
- `kanso_create_card` / `kanso_update_card` / `kanso_delete_card`
- `kanso_move_card` — move a card into a stack (optionally after a card)

**Labels & assignees**

- `kanso_create_label` — create a board label
- `kanso_assign_label` / `kanso_unassign_label`
- `kanso_assign_user` / `kanso_unassign_user`

**My work**

- `kanso_list_my_cards` — cards assigned to you across all boards

## Development

```sh
uv run pytest      # unit tests (respx-mocked, no live server needed)
```

## License

AGPL-3.0-or-later.
