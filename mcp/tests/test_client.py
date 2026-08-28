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
async def test_update_card_serializes_all_fields():
    route = respx.patch(f"{BASE}/cards/100").mock(
        return_value=httpx.Response(200, json={"id": 100, "title": "T", "stackId": 11})
    )
    async with _client() as c:
        card = await c.update_card(
            100,
            title="T",
            description="d",
            duedate="2026-09-01T17:00:00+00:00",
            done=True,
            archived=False,
            priority=3,
            estimate="M",
            start_date="2026-08-20T09:00:00+00:00",
            status="in_progress",
            all_day=False,
            due_reminder_day_before=True,
            cover_color="e63946",
            type="bug",
            visibility="private",
        )
    import json as _json

    # camelCase key names are what CardController::update expects.
    assert _json.loads(route.calls.last.request.content) == {
        "title": "T",
        "description": "d",
        "duedate": "2026-09-01T17:00:00+00:00",
        "done": True,
        "archived": False,
        "priority": 3,
        "estimate": "M",
        "startDate": "2026-08-20T09:00:00+00:00",
        "status": "in_progress",
        "allDay": False,
        "dueReminderDayBefore": True,
        "coverColor": "e63946",
        "type": "bug",
        "visibility": "private",
    }
    assert card.id == 100


@respx.mock
@pytest.mark.asyncio
async def test_update_card_drops_none():
    route = respx.patch(f"{BASE}/cards/100").mock(
        return_value=httpx.Response(200, json={"id": 100, "title": "T", "stackId": 11})
    )
    async with _client() as c:
        await c.update_card(100, status="done")
    import json as _json

    # Every unset field (incl. the new ones) must be dropped; only status is sent.
    assert _json.loads(route.calls.last.request.content) == {"status": "done"}


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
async def test_list_comments_parses():
    route = respx.get(f"{BASE}/cards/100/comments").mock(
        return_value=httpx.Response(
            200,
            json=[
                {
                    "id": 1,
                    "cardId": 100,
                    "parentCommentId": None,
                    "author": "alice",
                    "authorDisplayName": "Alice",
                    "body": "first",
                    "createdAt": 1700000000,
                    "editedAt": 0,
                    "reactions": [{"emoji": "👍", "count": 1}],
                }
            ],
        )
    )
    async with _client() as c:
        comments = await c.list_comments(100)
    assert route.called
    req = route.calls.last.request
    _assert_api_headers(req)
    assert req.url.path == "/index.php/apps/kanso/api/cards/100/comments"
    assert comments[0].id == 1
    assert comments[0].author == "alice"
    assert comments[0].authorDisplayName == "Alice"
    assert comments[0].body == "first"
    assert comments[0].createdAt == 1700000000


@respx.mock
@pytest.mark.asyncio
async def test_add_comment_body_uses_body_field():
    route = respx.post(f"{BASE}/cards/100/comments").mock(
        return_value=httpx.Response(
            200,
            json={"id": 2, "cardId": 100, "author": "bob", "body": "hi"},
        )
    )
    async with _client() as c:
        comment = await c.add_comment(100, "hi")
    import json as _json

    req = route.calls.last.request
    _assert_api_headers(req)
    # The controller field is `body`, NOT `text`; parentCommentId is dropped
    # when None (a top-level comment).
    assert _json.loads(req.content) == {"body": "hi"}
    assert comment.id == 2
    assert comment.body == "hi"


@respx.mock
@pytest.mark.asyncio
async def test_add_comment_forwards_parent_comment_id():
    route = respx.post(f"{BASE}/cards/100/comments").mock(
        return_value=httpx.Response(
            200,
            json={"id": 3, "cardId": 100, "parentCommentId": 2, "body": "re"},
        )
    )
    async with _client() as c:
        await c.add_comment(100, "re", parent_comment_id=2)
    import json as _json

    assert _json.loads(route.calls.last.request.content) == {
        "body": "re",
        "parentCommentId": 2,
    }


@respx.mock
@pytest.mark.asyncio
async def test_list_checklist_parses():
    route = respx.get(f"{BASE}/cards/100/checklist").mock(
        return_value=httpx.Response(
            200,
            json=[
                {
                    "id": 5,
                    "cardId": 100,
                    "title": "step one",
                    "done": False,
                    "sortKey": "aaa",
                    "assignedUser": "bob",
                    "dueDate": "2026-09-01T17:00:00+00:00",
                }
            ],
        )
    )
    async with _client() as c:
        items = await c.list_checklist(100)
    assert route.called
    req = route.calls.last.request
    _assert_api_headers(req)
    assert req.url.path == "/index.php/apps/kanso/api/cards/100/checklist"
    assert items[0].id == 5
    assert items[0].title == "step one"
    assert items[0].done is False


@respx.mock
@pytest.mark.asyncio
async def test_add_checklist_item_body_uses_title():
    route = respx.post(f"{BASE}/cards/100/checklist").mock(
        return_value=httpx.Response(
            200, json={"id": 6, "cardId": 100, "title": "new step", "done": False}
        )
    )
    async with _client() as c:
        item = await c.add_checklist_item(100, "new step")
    import json as _json

    req = route.calls.last.request
    _assert_api_headers(req)
    assert req.url.path == "/index.php/apps/kanso/api/cards/100/checklist"
    assert _json.loads(req.content) == {"title": "new step"}
    assert item.id == 6
    assert item.title == "new step"


@respx.mock
@pytest.mark.asyncio
async def test_update_checklist_item_toggles_done():
    route = respx.patch(f"{BASE}/checklist/6").mock(
        return_value=httpx.Response(
            200, json={"id": 6, "cardId": 100, "title": "new step", "done": True}
        )
    )
    async with _client() as c:
        item = await c.update_checklist_item(6, done=True)
    import json as _json

    req = route.calls.last.request
    _assert_api_headers(req)
    # PATCH targets the item id directly, NOT nested under the card.
    assert req.url.path == "/index.php/apps/kanso/api/checklist/6"
    # title is None => dropped; only done is sent.
    assert _json.loads(req.content) == {"done": True}
    assert item.done is True


@respx.mock
@pytest.mark.asyncio
async def test_update_checklist_item_renames():
    route = respx.patch(f"{BASE}/checklist/6").mock(
        return_value=httpx.Response(
            200, json={"id": 6, "cardId": 100, "title": "renamed", "done": False}
        )
    )
    async with _client() as c:
        await c.update_checklist_item(6, title="renamed")
    import json as _json

    # done is None => dropped; only title is sent.
    assert _json.loads(route.calls.last.request.content) == {"title": "renamed"}


@respx.mock
@pytest.mark.asyncio
async def test_list_relations_parses_grouped():
    payload = {
        "blocks": [{"id": 1, "cardId": 200, "title": "B", "done": False, "hidden": False}],
        "blockedBy": [],
        "duplicates": [],
        "relates": [{"id": 2, "cardId": 201, "title": "R", "done": True, "hidden": False}],
    }
    route = respx.get(f"{BASE}/cards/100/relations").mock(
        return_value=httpx.Response(200, json=payload)
    )
    async with _client() as c:
        relations = await c.list_relations(100)
    assert route.called
    req = route.calls.last.request
    _assert_api_headers(req)
    assert req.url.path == "/index.php/apps/kanso/api/cards/100/relations"
    assert relations["blocks"][0]["cardId"] == 200
    assert relations["relates"][0]["id"] == 2


@respx.mock
@pytest.mark.asyncio
async def test_add_relation_body_uses_other_card_id_and_kind():
    route = respx.post(f"{BASE}/cards/100/relations").mock(
        return_value=httpx.Response(200, json={"blocks": [], "blockedBy": []})
    )
    async with _client() as c:
        await c.add_relation(100, 200, "blocks")
    import json as _json

    req = route.calls.last.request
    _assert_api_headers(req)
    assert req.url.path == "/index.php/apps/kanso/api/cards/100/relations"
    assert _json.loads(req.content) == {"otherCardId": 200, "kind": "blocks"}


@respx.mock
@pytest.mark.asyncio
async def test_add_relation_forwards_blocked_by_kind_unchanged():
    # The client must NOT swap blocked_by; the server does the swap.
    route = respx.post(f"{BASE}/cards/100/relations").mock(
        return_value=httpx.Response(200, json={})
    )
    async with _client() as c:
        await c.add_relation(100, 200, "blocked_by")
    import json as _json

    assert _json.loads(route.calls.last.request.content) == {
        "otherCardId": 200,
        "kind": "blocked_by",
    }


@respx.mock
@pytest.mark.asyncio
async def test_remove_relation_delete_path():
    route = respx.delete(f"{BASE}/cards/100/relations/9").mock(
        return_value=httpx.Response(200, json=[])
    )
    async with _client() as c:
        await c.remove_relation(100, 9)
    assert route.called
    req = route.calls.last.request
    _assert_api_headers(req)
    assert req.method == "DELETE"
    assert req.url.path == "/index.php/apps/kanso/api/cards/100/relations/9"


@respx.mock
@pytest.mark.asyncio
async def test_set_parent_put_path_and_body():
    route = respx.put(f"{BASE}/cards/100/parent").mock(
        return_value=httpx.Response(
            200, json={"id": 100, "title": "T", "stackId": 11, "parentCardId": 50}
        )
    )
    async with _client() as c:
        card = await c.set_parent(100, 50)
    import json as _json

    req = route.calls.last.request
    _assert_api_headers(req)
    assert req.method == "PUT"
    assert req.url.path == "/index.php/apps/kanso/api/cards/100/parent"
    assert _json.loads(req.content) == {"parentCardId": 50}
    assert card.parentCardId == 50


@respx.mock
@pytest.mark.asyncio
async def test_set_parent_clear_sends_explicit_null():
    # Clearing the parent MUST send {"parentCardId": null} — not drop the key,
    # which would make the clear a silent no-op server-side.
    route = respx.put(f"{BASE}/cards/100/parent").mock(
        return_value=httpx.Response(
            200, json={"id": 100, "title": "T", "stackId": 11, "parentCardId": None}
        )
    )
    async with _client() as c:
        card = await c.set_parent(100, None)
    import json as _json

    body = _json.loads(route.calls.last.request.content)
    assert body == {"parentCardId": None}
    assert "parentCardId" in body  # key present, value explicitly null
    assert card.parentCardId is None


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
async def test_list_recur_rules_parses():
    route = respx.get(f"{BASE}/boards/7/recur-rules").mock(
        return_value=httpx.Response(
            200,
            json=[
                {
                    "id": 3,
                    "boardId": 7,
                    "templateCardId": 100,
                    "targetStackId": 11,
                    "mode": 0,
                    "rrule": "FREQ=WEEKLY;BYDAY=MO",
                    "duedatePolicy": 1,
                    "duedateOffsetSeconds": 86400,
                    "skipWhileOpen": True,
                    "enabled": True,
                    "owner": "alice",
                    "lastSpawnedAt": 0,
                    "nextOccurrenceAt": 1800000000,
                    "occurrencesSpawned": 0,
                    "createdAt": 1799000000,
                    "timezone": "Europe/Istanbul",
                }
            ],
        )
    )
    async with _client() as c:
        rules = await c.list_recur_rules(7)
    req = route.calls.last.request
    _assert_api_headers(req)
    assert req.url.path == "/index.php/apps/kanso/api/boards/7/recur-rules"
    assert rules[0].id == 3
    assert rules[0].templateCardId == 100
    assert rules[0].rrule == "FREQ=WEEKLY;BYDAY=MO"
    assert rules[0].duedatePolicy == 1
    assert rules[0].skipWhileOpen is True
    assert rules[0].timezone == "Europe/Istanbul"


@respx.mock
@pytest.mark.asyncio
async def test_create_recur_rule_body():
    route = respx.post(f"{BASE}/boards/7/recur-rules").mock(
        return_value=httpx.Response(200, json={"id": 3, "boardId": 7, "rrule": "FREQ=DAILY"})
    )
    async with _client() as c:
        rule = await c.create_recur_rule(
            7,
            100,
            11,
            "FREQ=DAILY",
            mode=1,
            duedate_policy=1,
            duedate_offset_seconds=3600,
            skip_while_open=True,
            timezone="Europe/Istanbul",
        )
    import json as _json

    req = route.calls.last.request
    _assert_api_headers(req)
    # boardId rides in the PATH, everything else is camelCase in the body.
    assert _json.loads(req.content) == {
        "templateCardId": 100,
        "targetStackId": 11,
        "mode": 1,
        "rrule": "FREQ=DAILY",
        "duedatePolicy": 1,
        "duedateOffsetSeconds": 3600,
        "skipWhileOpen": True,
        "timezone": "Europe/Istanbul",
    }
    assert rule.id == 3


@respx.mock
@pytest.mark.asyncio
async def test_create_recur_rule_sends_zero_and_false_defaults():
    route = respx.post(f"{BASE}/boards/7/recur-rules").mock(
        return_value=httpx.Response(200, json={"id": 4, "boardId": 7})
    )
    async with _client() as c:
        await c.create_recur_rule(7, 100, 11, "FREQ=DAILY")
    import json as _json

    # 0 / False are meaningful values and must survive the None-filter; only the
    # unset timezone is dropped (=> the owner's personal timezone server-side).
    assert _json.loads(route.calls.last.request.content) == {
        "templateCardId": 100,
        "targetStackId": 11,
        "mode": 0,
        "rrule": "FREQ=DAILY",
        "duedatePolicy": 0,
        "duedateOffsetSeconds": 0,
        "skipWhileOpen": False,
    }


@respx.mock
@pytest.mark.asyncio
async def test_update_recur_rule_drops_none():
    route = respx.patch(f"{BASE}/recur-rules/3").mock(
        return_value=httpx.Response(200, json={"id": 3, "boardId": 7, "enabled": False})
    )
    async with _client() as c:
        rule = await c.update_recur_rule(3, enabled=False)
    import json as _json

    req = route.calls.last.request
    _assert_api_headers(req)
    # PATCH targets the rule id directly, NOT nested under the board.
    assert req.url.path == "/index.php/apps/kanso/api/recur-rules/3"
    # enabled=False must survive; every unset field is dropped.
    assert _json.loads(req.content) == {"enabled": False}
    assert rule.enabled is False


@respx.mock
@pytest.mark.asyncio
async def test_update_recur_rule_forwards_timezone_and_rrule():
    route = respx.patch(f"{BASE}/recur-rules/3").mock(
        return_value=httpx.Response(200, json={"id": 3, "timezone": "Asia/Tokyo"})
    )
    async with _client() as c:
        await c.update_recur_rule(3, rrule="FREQ=MONTHLY", timezone="Asia/Tokyo")
    import json as _json

    assert _json.loads(route.calls.last.request.content) == {
        "rrule": "FREQ=MONTHLY",
        "timezone": "Asia/Tokyo",
    }


@respx.mock
@pytest.mark.asyncio
async def test_delete_recur_rule_path():
    route = respx.delete(f"{BASE}/recur-rules/3").mock(
        return_value=httpx.Response(200, json={"id": 3})
    )
    async with _client() as c:
        await c.delete_recur_rule(3)
    req = route.calls.last.request
    _assert_api_headers(req)
    assert req.method == "DELETE"
    assert req.url.path == "/index.php/apps/kanso/api/recur-rules/3"


@respx.mock
@pytest.mark.asyncio
async def test_recur_rule_create_now_unwraps_card():
    route = respx.post(f"{BASE}/recur-rules/3/create-now").mock(
        return_value=httpx.Response(
            200, json={"card": {"id": 500, "title": "Water the plants", "stackId": 11}}
        )
    )
    async with _client() as c:
        card = await c.recur_rule_create_now(3)
    req = route.calls.last.request
    _assert_api_headers(req)
    assert req.url.path == "/index.php/apps/kanso/api/recur-rules/3/create-now"
    assert card is not None
    assert card.id == 500
    assert card.title == "Water the plants"


@respx.mock
@pytest.mark.asyncio
async def test_recur_rule_create_now_null_card_is_none():
    respx.post(f"{BASE}/recur-rules/3/create-now").mock(
        return_value=httpx.Response(200, json={"card": None})
    )
    async with _client() as c:
        card = await c.recur_rule_create_now(3)
    # A null card must come back as None, not blow up model validation.
    assert card is None


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
