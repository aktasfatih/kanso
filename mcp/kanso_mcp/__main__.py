# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Entrypoint: build the FastMCP server, register the Kanso tools, run it.

Transports:
  - default: stdio (so an MCP client such as Claude Code can spawn it)
  - --http:  streamable-http (host/port from MCP_HOST / MCP_PORT, default
             127.0.0.1:7654)
"""

from __future__ import annotations

import argparse
import os
import sys

from mcp.server.fastmcp import FastMCP

from kanso_mcp import __version__
from kanso_mcp.client import KansoClient
from kanso_mcp.config import ConfigError, KansoConfig
from kanso_mcp.tools import register_tools


def build_server() -> FastMCP:
    """Construct a FastMCP server with the Kanso client and tools wired in.

    Reads connection settings from the environment; raises ConfigError if any
    of NEXTCLOUD_HOST / NEXTCLOUD_USERNAME / NEXTCLOUD_PASSWORD are missing.
    """
    config = KansoConfig.from_env()
    host = os.environ.get("MCP_HOST", "127.0.0.1")
    port = int(os.environ.get("MCP_PORT", "7654"))
    mcp = FastMCP("kanso", host=host, port=port)
    client = KansoClient(config)
    register_tools(mcp, client)
    return mcp


def main() -> None:
    parser = argparse.ArgumentParser(
        prog="kanso-mcp",
        description="MCP server for the Kanso Nextcloud kanban app.",
    )
    parser.add_argument(
        "--http",
        action="store_true",
        help="Run the streamable-http transport instead of stdio "
        "(host/port from MCP_HOST / MCP_PORT, default 127.0.0.1:7654).",
    )
    parser.add_argument(
        "--version", action="version", version=f"kanso-mcp {__version__}"
    )
    args = parser.parse_args()

    try:
        mcp = build_server()
    except ConfigError as exc:
        print(f"Configuration error: {exc}", file=sys.stderr)
        raise SystemExit(2)

    mcp.run(transport="streamable-http" if args.http else "stdio")


if __name__ == "__main__":
    main()
