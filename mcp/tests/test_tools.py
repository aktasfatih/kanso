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
from kanso_mcp.models import CardSummary
from kanso_mcp.tools import _without_archived_cards, register_tools

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


def test_archived_survives_a_card_summary_round_trip_and_the_filter():
    # `_without_archived_cards` reads `c["archived"]` off a DUMPED CardSummary,
    # so the whole filter hangs on that one key surviving validate -> dump. The
    # model defaults it to False, which means a server-side rename would not
    # raise — every card would silently look active and the filter would become
    # a no-op. Pin both halves: the round-trip, then the filter on a mixed set.
    dumped = [
        CardSummary.model_validate(
            {"id": 1, "title": "Active", "archived": False}
        ).model_dump(),
        CardSummary.model_validate(
            {"id": 2, "title": "Archived", "archived": True}
        ).model_dump(),
    ]
    assert [c["archived"] for c in dumped] == [False, True]

    filtered = _without_archived_cards({"board": {"id": 7}, "cards": dumped})
    assert [c["id"] for c in filtered["cards"]] == [1]
    # Non-card keys are passed through untouched.
    assert filtered["board"] == {"id": 7}


@respx.mock
@pytest.mark.asyncio
async def test_get_board_keeps_the_side_payloads_the_api_ships():
    # BoardController::show returns reviewTypes / cardFields / blocksEdges /
    # acl / subscription alongside the core four. BoardDetail is extra="ignore",
    # so any key it does not declare is dropped before the tool layer ever sees
    # it — this pins that they reach the LLM.
    respx.get(f"{BASE}/boards/9").mock(
        return_value=httpx.Response(
            200,
            json={
                "board": {"id": 9, "title": "Rich"},
                "stacks": [],
                "cards": [],
                "labels": [],
                "reviewTypes": [{"id": 1, "title": "QA"}],
                "cardFields": [{"id": 2, "title": "Story points", "type": "number"}],
                "blocksEdges": [{"fromCardId": 100, "toCardId": 101}],
                "acl": [{"id": 3, "participant": "alice", "type": 0}],
                "subscription": {"subscribed": True, "count": 2},
                "permissions": 31,
                "role": "internal",
                "cursor": 77,
            },
        )
    )
    tools, client = _tools()
    async with client:
        board = await tools["kanso_get_board"](9)

    assert board["reviewTypes"] == [{"id": 1, "title": "QA"}]
    assert board["cardFields"] == [
        {"id": 2, "title": "Story points", "type": "number"}
    ]
    assert board["blocksEdges"] == [{"fromCardId": 100, "toCardId": 101}]
    assert board["acl"] == [{"id": 3, "participant": "alice", "type": 0}]
    assert board["subscription"] == {"subscribed": True, "count": 2}


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


# --------------------------------------------------------------------- search
# A stand-in for the real server's ACL. The authenticated user can READ board 7
# only; board 99 belongs to someone else and holds a card whose text matches the
# same term. `_acl_search` mirrors SearchService: the searchable set is derived
# from the readable boards, and `boardId` may only NARROW it — it can never
# widen it to board 99. Anything the tool returns from board 99 therefore came
# from the tool, not from the server.
READABLE_BOARD = 7
HIDDEN_BOARD = 99

ALL_CARDS = [
    {
        "type": "card",
        "cardId": 100,
        "boardId": READABLE_BOARD,
        "title": "Invoice run",
        "snippet": "send the invoice on the 1st",
        "rank": 3,
    },
    {
        "type": "card",
        "cardId": 101,
        "boardId": READABLE_BOARD,
        "title": "Billing follow-up",
        "snippet": "chase the unpaid invoice",
        "rank": 2,
    },
    {
        "type": "card",
        "cardId": 900,
        "boardId": HIDDEN_BOARD,
        "title": "Invoice — acquisition",
        "snippet": "confidential invoice terms",
        "rank": 3,
    },
]


def _acl_search(request: httpx.Request) -> httpx.Response:
    term = request.url.params.get("q", "")
    board = request.url.params.get("boardId")
    limit = int(request.url.params.get("limit", "25"))

    hits = [c for c in ALL_CARDS if c["boardId"] == READABLE_BOARD]
    if term:
        hits = [
            c
            for c in hits
            if term.lower() in c["title"].lower() or term.lower() in c["snippet"].lower()
        ]
    # boardId narrows the readable set; an unreadable id leaves it empty.
    if board is not None:
        hits = [c for c in hits if str(c["boardId"]) == board]
    return httpx.Response(
        200, json={"query": term, "total": len(hits), "results": hits[:limit]}
    )


def _mock_search() -> None:
    respx.get(f"{BASE}/search").mock(side_effect=_acl_search)


@respx.mock
@pytest.mark.asyncio
async def test_search_cards_finds_title_and_description_matches():
    _mock_search()
    tools, client = _tools()
    async with client:
        found = await tools["kanso_search_cards"]("invoice")

    # Card 100 matches on its TITLE (rank 3), card 101 only in its
    # description/snippet (rank 2) — both come back, each with the locators
    # needed to open it.
    assert [h["cardId"] for h in found["results"]] == [100, 101]
    assert [h["rank"] for h in found["results"]] == [3, 2]
    assert all(h["boardId"] == READABLE_BOARD for h in found["results"])
    assert all(h["type"] == "card" for h in found["results"])
    assert found["results"][0]["title"] == "Invoice run"
    assert found["results"][0]["snippet"] == "send the invoice on the 1st"
    assert found["query"] == "invoice"
    assert found["total"] == 2


@respx.mock
@pytest.mark.asyncio
async def test_search_cards_scopes_to_one_board_when_asked():
    _mock_search()
    tools, client = _tools()
    async with client:
        found = await tools["kanso_search_cards"]("invoice", board_id=READABLE_BOARD)

    assert respx.calls.last.request.url.params.get("boardId") == str(READABLE_BOARD)
    assert [h["cardId"] for h in found["results"]] == [100, 101]


@respx.mock
@pytest.mark.asyncio
async def test_search_cards_searches_every_readable_board_by_default():
    _mock_search()
    tools, client = _tools()
    async with client:
        found = await tools["kanso_search_cards"]("invoice")

    # Cross-board is the DEFAULT: no board filter is sent, and it is one call to
    # the search endpoint — not a per-board fan-out.
    assert "boardId" not in respx.calls.last.request.url.params
    assert len(respx.calls) == 1
    assert respx.calls.last.request.url.path.endswith("/search")
    assert found["total"] == 2


@respx.mock
@pytest.mark.asyncio
async def test_search_cards_forwards_the_limit():
    _mock_search()
    tools, client = _tools()
    async with client:
        found = await tools["kanso_search_cards"]("invoice", limit=1)

    assert respx.calls.last.request.url.params.get("limit") == "1"
    assert [h["cardId"] for h in found["results"]] == [100]


@respx.mock
@pytest.mark.asyncio
async def test_search_cards_never_returns_a_card_from_an_unreadable_board():
    # THE boundary test. Board 99 holds a card matching "invoice" just as well as
    # the readable ones, but the user cannot read that board, so the server never
    # ships it. The tool must not widen that ceiling: not by fanning out over
    # boards, not by treating board_id as a lookup key, not by assembling results
    # from anywhere other than this one ACL-filtered response.
    _mock_search()
    tools, client = _tools()
    async with client:
        cross_board = await tools["kanso_search_cards"]("invoice")
        scoped_to_hidden = await tools["kanso_search_cards"](
            "invoice", board_id=HIDDEN_BOARD
        )

    # The hidden card is absent from an unscoped search...
    hidden_ids = [h["cardId"] for h in cross_board["results"] if h["cardId"] == 900]
    assert hidden_ids == []
    assert all(h["boardId"] == READABLE_BOARD for h in cross_board["results"])

    # ...and asking for that board by id yields nothing rather than either the
    # hidden card or, worse, the readable boards' cards under a board scope that
    # was silently dropped.
    assert scoped_to_hidden["results"] == []
    assert scoped_to_hidden["total"] == 0
    assert respx.calls.last.request.url.params.get("boardId") == str(HIDDEN_BOARD)

    # Exactly one request per search, all of them to the ACL-filtered endpoint:
    # no board fan-out, no second source of cards.
    assert len(respx.calls) == 2
    assert all(c.request.url.path.endswith("/search") for c in respx.calls)


@pytest.mark.asyncio
async def test_search_cards_schema_only_requires_the_query():
    # An agent must be able to search with just a term; the board scope and the
    # limit have to stay optional or the simple call becomes an argument error.
    from mcp.server.fastmcp import FastMCP

    client = KansoClient(
        KansoConfig(host="http://nc.test", username="admin", password="pw")
    )
    server = FastMCP("kanso-test")
    register_tools(server, client)
    async with client:
        tools = {t.name: t for t in await server.list_tools()}

    assert "kanso_search_cards" in tools
    schema = tools["kanso_search_cards"].inputSchema
    assert schema["required"] == ["query"]
    assert schema["properties"]["limit"]["default"] == 25
    assert "board_id" not in schema.get("required", [])
