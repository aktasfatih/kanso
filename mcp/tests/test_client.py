# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Unit tests for KansoClient: assert each method hits the right
method + path + headers (OCS-APIRequest present) + body, and parses responses.
"""

from __future__ import annotations

import httpx
import pytest
import respx

from kanso_mcp.client import KansoApiError, KansoClient
from kanso_mcp.config import KansoConfig

BASE = "http://nc.test/index.php/apps/kanso/api"


def _config() -> KansoConfig:
    return KansoConfig(host="http://nc.test", username="admin", password="pw")


def _client() -> KansoClient:
    return KansoClient(_config())


def _assert_api_headers(request: httpx.Request) -> None:
    assert request.headers.get("OCS-APIRequest") == "true"
    # Basic auth header must be present.
    assert request.headers.get("Authorization", "").startswith("Basic ")


@respx.mock
@pytest.mark.asyncio
async def test_list_boards():
    route = respx.get(f"{BASE}/boards").mock(
        return_value=httpx.Response(200, json=[{"id": 1, "title": "B"}])
    )
    async with _client() as c:
        boards = await c.list_boards()
    assert route.called
    _assert_api_headers(route.calls.last.request)
    assert boards[0].id == 1
    assert boards[0].title == "B"


@respx.mock
@pytest.mark.asyncio
async def test_get_board_parses_detail():
    payload = {
        "board": {"id": 7, "title": "Board"},
        "stacks": [{"id": 10, "title": "To Do", "boardId": 7}],
        "cards": [{"id": 100, "title": "Card", "stackId": 10}],
        "labels": [{"id": 5, "title": "bug", "color": "e11"}],
        "cursor": 42,
    }
    route = respx.get(f"{BASE}/boards/7").mock(
        return_value=httpx.Response(200, json=payload)
    )
    async with _client() as c:
        detail = await c.get_board(7)
    assert route.called
    assert detail.board.id == 7
    assert detail.stacks[0].title == "To Do"
    assert detail.cards[0].id == 100
    assert detail.labels[0].title == "bug"
    assert detail.cursor == 42


@respx.mock
@pytest.mark.asyncio
async def test_create_board_body():
    route = respx.post(f"{BASE}/boards").mock(
        return_value=httpx.Response(200, json={"id": 3, "title": "New"})
    )
    async with _client() as c:
        board = await c.create_board("New", color="0082c9")
    req = route.calls.last.request
    _assert_api_headers(req)
    import json as _json

    assert _json.loads(req.content) == {"title": "New", "color": "0082c9"}
    assert board.id == 3


@respx.mock
@pytest.mark.asyncio
async def test_update_board_drops_none():
    route = respx.patch(f"{BASE}/boards/3").mock(
        return_value=httpx.Response(200, json={"id": 3, "title": "Renamed"})
    )
    async with _client() as c:
        await c.update_board(3, title="Renamed")
    import json as _json

    # None fields (color/archived/prefix) must NOT be sent.
    assert _json.loads(route.calls.last.request.content) == {"title": "Renamed"}


@respx.mock
@pytest.mark.asyncio
async def test_create_stack_body_uses_boardId():
    route = respx.post(f"{BASE}/stacks").mock(
        return_value=httpx.Response(200, json={"id": 11, "title": "Doing", "boardId": 7})
    )
    async with _client() as c:
        await c.create_stack(7, "Doing")
    import json as _json

    assert _json.loads(route.calls.last.request.content) == {
        "boardId": 7,
        "title": "Doing",
    }


@respx.mock
@pytest.mark.asyncio
async def test_move_stack_path_and_body():
    route = respx.post(f"{BASE}/stacks/11/move").mock(
        return_value=httpx.Response(200, json={})
    )
    async with _client() as c:
        await c.move_stack(11, after_stack_id=9)
    import json as _json

    assert _json.loads(route.calls.last.request.content) == {"afterStackId": 9}


@respx.mock
@pytest.mark.asyncio
async def test_create_card_body_uses_stackId():
    route = respx.post(f"{BASE}/cards").mock(
        return_value=httpx.Response(200, json={"id": 100, "title": "T", "stackId": 11})
    )
    async with _client() as c:
        card = await c.create_card(11, "T", duedate="2026-09-01T17:00:00+00:00")
    import json as _json

    assert _json.loads(route.calls.last.request.content) == {
        "stackId": 11,
        "title": "T",
        "duedate": "2026-09-01T17:00:00+00:00",
    }
    assert card.id == 100


@respx.mock
@pytest.mark.asyncio
async def test_move_card_body_uses_targetStackId():
    route = respx.post(f"{BASE}/cards/100/move").mock(
        return_value=httpx.Response(200, json={})
    )
    async with _client() as c:
        await c.move_card(100, target_stack_id=12, after_card_id=None)
    import json as _json

    # after_card_id=None is dropped; only targetStackId is sent.
    assert _json.loads(route.calls.last.request.content) == {"targetStackId": 12}


@respx.mock
@pytest.mark.asyncio
async def test_assign_label_put_path():
    route = respx.put(f"{BASE}/cards/100/labels/5").mock(
        return_value=httpx.Response(200, json=[])
    )
    async with _client() as c:
        await c.assign_label(100, 5)
    assert route.called
    assert route.calls.last.request.method == "PUT"


@respx.mock
@pytest.mark.asyncio
async def test_assign_user_put_path():
    route = respx.put(f"{BASE}/cards/100/assignees/bob").mock(
        return_value=httpx.Response(200, json=[])
    )
    async with _client() as c:
        await c.assign_user(100, "bob")
    assert route.called


@respx.mock
@pytest.mark.asyncio
async def test_list_my_cards():
    route = respx.get(f"{BASE}/my-cards").mock(
        return_value=httpx.Response(200, json=[{"id": 100, "title": "Mine", "stackId": 1}])
    )
    async with _client() as c:
        cards = await c.list_my_cards()
    assert route.called
    assert cards[0].title == "Mine"


@respx.mock
@pytest.mark.asyncio
async def test_list_board_members():
    route = respx.get(f"{BASE}/boards/7/participants").mock(
        return_value=httpx.Response(
            200,
            json=[
                {"uid": "alice", "displayName": "Alice"},
                {"uid": "bob", "displayName": "Bob"},
            ],
        )
    )
    async with _client() as c:
        members = await c.list_board_members(7)
    assert route.called
    req = route.calls.last.request
    _assert_api_headers(req)
    assert req.url.path == "/index.php/apps/kanso/api/boards/7/participants"
    # No filter => no ?q= param is sent.
    assert "q" not in req.url.params
    assert members[0].uid == "alice"
    assert members[0].displayName == "Alice"
    assert members[1].uid == "bob"


@respx.mock
@pytest.mark.asyncio
async def test_list_board_members_forwards_q():
    route = respx.get(f"{BASE}/boards/7/participants").mock(
        return_value=httpx.Response(200, json=[{"uid": "bob", "displayName": "Bob"}])
    )
    async with _client() as c:
        members = await c.list_board_members(7, q="bo")
    assert route.called
    assert route.calls.last.request.url.params.get("q") == "bo"
    assert members[0].uid == "bob"


@respx.mock
@pytest.mark.asyncio
async def test_error_raises_kanso_api_error():
    respx.post(f"{BASE}/boards").mock(
        return_value=httpx.Response(412, text="CSRF check failed")
    )
    async with _client() as c:
        with pytest.raises(KansoApiError) as excinfo:
            await c.create_board("X")
    assert excinfo.value.status_code == 412
    assert "CSRF" in excinfo.value.body
