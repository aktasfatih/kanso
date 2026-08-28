# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Unit tests for the MCP tool layer.

The tools wrap KansoClient, so the HTTP layer is stubbed with respx exactly as
in test_client.py — no live Nextcloud is needed. `register_tools` only ever
calls `mcp.tool(...)`, so a tiny stub registry is enough to capture the tool
functions and call them directly.
"""

from __future__ import annotations

from typing import Any, Callable, Dict, Tuple

import httpx
import pytest
import respx

from kanso_mcp.client import KansoClient
from kanso_mcp.config import KansoConfig
from kanso_mcp.tools import register_tools

BASE = "http://nc.test/index.php/apps/kanso/api"


class _Registry:
    """Stand-in for FastMCP: records each registered tool function by name."""

    def __init__(self) -> None:
        self.tools: Dict[str, Callable[..., Any]] = {}

    def tool(self, *args: Any, **kwargs: Any) -> Callable[..., Any]:
        def decorator(fn: Callable[..., Any]) -> Callable[..., Any]:
            self.tools[fn.__name__] = fn
            return fn

        return decorator


def _tools() -> Tuple[Dict[str, Callable[..., Any]], KansoClient]:
    client = KansoClient(
        KansoConfig(host="http://nc.test", username="admin", password="pw")
    )
    registry = _Registry()
    register_tools(registry, client)  # type: ignore[arg-type]
    return registry.tools, client


# A board mixing archived and active cards, plus an archived STACK whose own
# card is not archived (that card must still be returned).
BOARD_PAYLOAD = {
    "board": {"id": 7, "title": "Board"},
    "stacks": [
        {"id": 10, "title": "To Do", "boardId": 7},
        {"id": 11, "title": "Old column", "boardId": 7, "archived": True},
    ],
    "cards": [
        {"id": 100, "title": "Active", "stackId": 10, "archived": False},
        {"id": 101, "title": "Archived", "stackId": 10, "archived": True},
        {"id": 102, "title": "In an archived stack", "stackId": 11, "archived": False},
    ],
    "labels": [{"id": 5, "title": "bug", "color": "e11"}],
    "cursor": 42,
}


def _mock_board() -> None:
    respx.get(f"{BASE}/boards/7").mock(
        return_value=httpx.Response(200, json=BOARD_PAYLOAD)
    )


@respx.mock
@pytest.mark.asyncio
async def test_get_board_excludes_archived_cards_by_default():
    _mock_board()
    tools, client = _tools()
    async with client:
        board = await tools["kanso_get_board"](7)

    assert [c["id"] for c in board["cards"]] == [100, 102]
    # The flag must survive the filter, not be stripped.
    assert all("archived" in c for c in board["cards"])
    assert all(c["archived"] is False for c in board["cards"])


@respx.mock
@pytest.mark.asyncio
async def test_get_board_include_archived_returns_everything():
    _mock_board()
    tools, client = _tools()
    async with client:
        board = await tools["kanso_get_board"](7, include_archived=True)

    assert [c["id"] for c in board["cards"]] == [100, 101, 102]
    # Archived and active are still distinguishable by the flag.
    assert {c["id"]: c["archived"] for c in board["cards"]} == {
        100: False,
        101: True,
        102: False,
    }


@respx.mock
@pytest.mark.asyncio
async def test_get_board_filter_touches_only_cards():
    _mock_board()
    tools, client = _tools()
    async with client:
        filtered = await tools["kanso_get_board"](7)
        unfiltered = await tools["kanso_get_board"](7, include_archived=True)

    # Every other key of the payload is identical between the two calls —
    # including the archived STACK, which is never dropped.
    assert filtered.keys() == unfiltered.keys()
    for key in filtered:
        if key != "cards":
            assert filtered[key] == unfiltered[key], key
    assert [s["id"] for s in filtered["stacks"]] == [10, 11]
    assert [lb["id"] for lb in filtered["labels"]] == [5]
    assert filtered["cursor"] == 42


@pytest.mark.asyncio
async def test_get_board_schema_exposes_optional_include_archived():
    # The stub registry above can call the tool functions but never builds the
    # JSON schema MCP clients actually see, so this one test registers on a real
    # FastMCP instance: include_archived must be optional and default to false,
    # or callers get the old mixed list (or a hard argument error).
    from mcp.server.fastmcp import FastMCP

    client = KansoClient(
        KansoConfig(host="http://nc.test", username="admin", password="pw")
    )
    server = FastMCP("kanso-test")
    register_tools(server, client)
    async with client:
        tool = next(t for t in await server.list_tools() if t.name == "kanso_get_board")

    schema = tool.inputSchema
    assert schema["properties"]["include_archived"]["default"] is False
    assert "board_id" in schema["required"]
    assert "include_archived" not in schema.get("required", [])


@respx.mock
@pytest.mark.asyncio
async def test_create_recur_rule_tool_sends_its_defaults():
    route = respx.post(f"{BASE}/boards/7/recur-rules").mock(
        return_value=httpx.Response(200, json={"id": 3, "boardId": 7, "rrule": "FREQ=DAILY"})
    )
    tools, client = _tools()
    async with client:
        rule = await tools["kanso_create_recur_rule"](7, 100, 10, "FREQ=DAILY")
    import json as _json

    # The tool's own defaults (CLONE, due-at-occurrence, no offset, no skip) must
    # reach the API rather than being dropped as "unset".
    assert _json.loads(route.calls.last.request.content) == {
        "templateCardId": 100,
        "targetStackId": 10,
        "mode": 0,
        "rrule": "FREQ=DAILY",
        "duedatePolicy": 0,
        "duedateOffsetSeconds": 0,
        "skipWhileOpen": False,
    }
    assert rule["id"] == 3


@respx.mock
@pytest.mark.asyncio
async def test_update_recur_rule_tool_sends_only_what_changed():
    route = respx.patch(f"{BASE}/recur-rules/3").mock(
        return_value=httpx.Response(200, json={"id": 3, "enabled": False})
    )
    tools, client = _tools()
    async with client:
        rule = await tools["kanso_update_recur_rule"](3, enabled=False)
    import json as _json

    assert _json.loads(route.calls.last.request.content) == {"enabled": False}
    assert rule["enabled"] is False


@respx.mock
@pytest.mark.asyncio
async def test_recur_rule_create_now_tool_unwraps_the_card():
    respx.post(f"{BASE}/recur-rules/3/create-now").mock(
        return_value=httpx.Response(
            200, json={"card": {"id": 500, "title": "Water the plants", "stackId": 10}}
        )
    )
    tools, client = _tools()
    async with client:
        result = await tools["kanso_recur_rule_create_now"](3)

    # The tool flattens the {"card": ...} envelope into a spawned flag + card.
    assert result["spawned"] is True
    assert result["card"]["id"] == 500


@pytest.mark.asyncio
async def test_create_recur_rule_schema_only_requires_the_essentials():
    # An agent must be able to set up a repeat with just the anchor + schedule;
    # mode / policy / offset / skip / timezone all have to stay optional or the
    # simple call becomes an argument error.
    from mcp.server.fastmcp import FastMCP

    client = KansoClient(
        KansoConfig(host="http://nc.test", username="admin", password="pw")
    )
    server = FastMCP("kanso-test")
    register_tools(server, client)
    async with client:
        tools = {t.name: t for t in await server.list_tools()}

    schema = tools["kanso_create_recur_rule"].inputSchema
    assert set(schema["required"]) == {
        "board_id",
        "template_card_id",
        "target_stack_id",
        "rrule",
    }
    assert schema["properties"]["mode"]["default"] == 0
    assert schema["properties"]["duedate_policy"]["default"] == 0
    assert schema["properties"]["skip_while_open"]["default"] is False
    # The whole recurrence surface must be registered, not just create.
    assert {
        "kanso_list_recur_rules",
        "kanso_update_recur_rule",
        "kanso_delete_recur_rule",
        "kanso_recur_rule_create_now",
    } <= set(tools)


@respx.mock
@pytest.mark.asyncio
async def test_get_board_with_no_archived_cards_is_unchanged():
    respx.get(f"{BASE}/boards/8").mock(
        return_value=httpx.Response(
            200,
            json={
                "board": {"id": 8, "title": "Clean"},
                "stacks": [],
                "cards": [{"id": 200, "title": "Only card", "stackId": 20}],
                "labels": [],
            },
        )
    )
    tools, client = _tools()
    async with client:
        board = await tools["kanso_get_board"](8)

    assert [c["id"] for c in board["cards"]] == [200]
    assert board["cards"][0]["archived"] is False
