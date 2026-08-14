<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Kanso MCP server

A [Model Context Protocol](https://modelcontextprotocol.io) server that exposes
the [Kanso](https://github.com/aktasfatih/kanso) Nextcloud kanban app to MCP
clients (Claude Code, Claude Desktop, etc.). It talks to Kanso's own REST API
(`{host}/index.php/apps/kanso/api`) over HTTP Basic auth.

This server ships **inside** the Kanso repo (under `mcp/`) but is a **separate
artifact**: it is excluded from the Nextcloud app tarball and has its own
version and changelog.

## Requirements

- Python 3.11+
- A Nextcloud instance with the Kanso app installed
- A Nextcloud **app password** (not your login password)

## 1. Create an app password

In Nextcloud: **Settings → Security → Devices & sessions → Create new app
password**. Copy the generated password — this is `NEXTCLOUD_PASSWORD`.

## 2. Configure the environment

| Variable             | Required | Description                                            |
| -------------------- | -------- | ------------------------------------------------------ |
| `NEXTCLOUD_HOST`     | yes      | Base URL, e.g. `https://cloud.example.com`             |
| `NEXTCLOUD_USERNAME` | yes      | Your Nextcloud user id                                 |
| `NEXTCLOUD_PASSWORD` | yes      | The app password from step 1                           |
| `VERIFY_SSL`         | no       | `false`/`0` to skip TLS verification (default `true`)  |
| `MCP_HOST`           | no       | HTTP-mode bind host (default `127.0.0.1`)              |
| `MCP_PORT`           | no       | HTTP-mode bind port (default `8001`)                  |

```sh
export NEXTCLOUD_HOST="https://cloud.example.com"
export NEXTCLOUD_USERNAME="alice"
export NEXTCLOUD_PASSWORD="xxxxx-xxxxx-xxxxx-xxxxx-xxxxx"
```

## 3. Run it

### stdio (default — for spawning by a client)

```sh
# from the mcp/ directory
uv run kanso-mcp
```

or, installed into a venv:

```sh
pip install -e .
kanso-mcp
```

### HTTP (streamable-http)

```sh
kanso-mcp --http           # binds 127.0.0.1:8001 by default
```

## 4. Add to an MCP client

Add to your client's `mcpServers` config (e.g. Claude Desktop
`claude_desktop_config.json`, or `.mcp.json` for Claude Code):

```json
{
  "mcpServers": {
    "kanso": {
      "command": "uv",
      "args": ["run", "--directory", "/absolute/path/to/kanso/mcp", "kanso-mcp"],
      "env": {
        "NEXTCLOUD_HOST": "https://cloud.example.com",
        "NEXTCLOUD_USERNAME": "alice",
        "NEXTCLOUD_PASSWORD": "xxxxx-xxxxx-xxxxx-xxxxx-xxxxx"
      }
    }
  }
}
```

For Claude Code you can also register it from the CLI:

```sh
claude mcp add kanso -- uv run --directory /absolute/path/to/kanso/mcp kanso-mcp
```

## Tools

**Boards**

- `kanso_list_boards` — list all boards (with per-board stats)
- `kanso_get_board` — read a board: stacks, labels, card summaries
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
