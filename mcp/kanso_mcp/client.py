# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Async HTTP client for the Kanso Nextcloud app REST API.

Every request carries ``OCS-APIRequest: true`` + HTTP Basic auth, which is what
makes Nextcloud treat the call as an API request and skip CSRF. Paths here are
relative to the app-framework API base (``{host}/index.php/apps/kanso/api``);
note this is NOT the OCS endpoint.
"""

from __future__ import annotations

from typing import Any, Dict, List, Optional

import httpx

from kanso_mcp.config import KansoConfig
from kanso_mcp.models import (
    Board,
    BoardDetail,
    BoardMember,
    BoardSummary,
    Card,
    CardSummary,
    ChecklistItem,
    Comment,
    Label,
    RecurRule,
    Stack,
)


class KansoApiError(RuntimeError):
    """A non-2xx response from the Kanso API (carries status + body snippet)."""

    def __init__(self, status_code: int, method: str, path: str, body: str):
        self.status_code = status_code
        self.method = method
        self.path = path
        self.body = body
        snippet = body if len(body) <= 500 else body[:500] + "…"
        super().__init__(
            f"Kanso API {method} {path} failed with HTTP {status_code}: {snippet}"
        )


class KansoClient:
    """Typed async client over the Kanso REST API."""

    def __init__(self, config: KansoConfig, client: Optional[httpx.AsyncClient] = None):
        self._config = config
        self._owns_client = client is None
        self._client = client or httpx.AsyncClient(
            base_url=config.api_base_url,
            auth=(config.username, config.password),
            headers={
                "OCS-APIRequest": "true",
                "Content-Type": "application/json",
                "Accept": "application/json",
            },
            verify=config.verify_ssl,
            timeout=30.0,
        )

    async def aclose(self) -> None:
        if self._owns_client:
            await self._client.aclose()

    async def __aenter__(self) -> "KansoClient":
        return self

    async def __aexit__(self, *exc: Any) -> None:
        await self.aclose()

    async def _request(
        self,
        method: str,
        path: str,
        *,
        json: Optional[Dict[str, Any]] = None,
        params: Optional[Dict[str, Any]] = None,
    ) -> Any:
        # Drop None values so we only send fields the caller actually set
        # (the controllers treat an absent param as "leave unchanged").
        payload = None if json is None else {k: v for k, v in json.items() if v is not None}
        response = await self._client.request(method, path, json=payload, params=params)
        if response.status_code < 200 or response.status_code >= 300:
            raise KansoApiError(response.status_code, method, path, response.text)
        if response.status_code == 204 or not response.content:
            return None
        try:
            return response.json()
        except ValueError:
            return response.text

    # ------------------------------------------------------------------ boards
    async def list_boards(self) -> List[BoardSummary]:
        data = await self._request("GET", "/boards")
        return [BoardSummary.model_validate(b) for b in (data or [])]

    async def get_board(self, board_id: int) -> BoardDetail:
        data = await self._request("GET", f"/boards/{board_id}")
        return BoardDetail.model_validate(data)

    async def list_board_members(
        self, board_id: int, q: Optional[str] = None
    ) -> List[BoardMember]:
        # ?q= is only sent when set (an absent filter returns all participants).
        params = {"q": q} if q is not None else None
        data = await self._request(
            "GET", f"/boards/{board_id}/participants", params=params
        )
        return [BoardMember.model_validate(m) for m in (data or [])]

    async def create_board(self, title: str, color: Optional[str] = None) -> Board:
        data = await self._request(
            "POST", "/boards", json={"title": title, "color": color}
        )
        return Board.model_validate(data)

    async def update_board(
        self,
        board_id: int,
        *,
        title: Optional[str] = None,
        color: Optional[str] = None,
        archived: Optional[bool] = None,
        prefix: Optional[str] = None,
    ) -> Board:
        data = await self._request(
            "PATCH",
            f"/boards/{board_id}",
            json={
                "title": title,
                "color": color,
                "archived": archived,
                "prefix": prefix,
            },
        )
        return Board.model_validate(data)

    async def delete_board(self, board_id: int) -> Any:
        return await self._request("DELETE", f"/boards/{board_id}")

    # ------------------------------------------------------------------ stacks
    async def create_stack(self, board_id: int, title: str) -> Stack:
        data = await self._request(
            "POST", "/stacks", json={"boardId": board_id, "title": title}
        )
        return Stack.model_validate(data)

    async def update_stack(
        self,
        stack_id: int,
        *,
        title: Optional[str] = None,
        archived: Optional[bool] = None,
        wip_limit: Optional[int] = None,
        color: Optional[str] = None,
    ) -> Stack:
        data = await self._request(
            "PATCH",
            f"/stacks/{stack_id}",
            json={
                "title": title,
                "archived": archived,
                "wipLimit": wip_limit,
                "color": color,
            },
        )
        return Stack.model_validate(data)

    async def delete_stack(self, stack_id: int) -> Any:
        return await self._request("DELETE", f"/stacks/{stack_id}")

    async def move_stack(self, stack_id: int, after_stack_id: Optional[int] = None) -> Any:
        # after_stack_id=None means "move to the first position".
        return await self._request(
            "POST", f"/stacks/{stack_id}/move", json={"afterStackId": after_stack_id}
        )

    # ------------------------------------------------------------------- cards
    async def get_card(self, card_id: int) -> Card:
        data = await self._request("GET", f"/cards/{card_id}")
        return Card.model_validate(data)

    async def create_card(
        self,
        stack_id: int,
        title: str,
        *,
        duedate: Optional[str] = None,
        all_day: Optional[bool] = None,
    ) -> Card:
        data = await self._request(
            "POST",
            "/cards",
            json={
                "stackId": stack_id,
                "title": title,
                "duedate": duedate,
                "allDay": all_day,
            },
        )
        return Card.model_validate(data)

    async def update_card(
        self,
        card_id: int,
        *,
        title: Optional[str] = None,
        description: Optional[str] = None,
        duedate: Optional[str] = None,
        done: Optional[bool] = None,
        archived: Optional[bool] = None,
        priority: Optional[int] = None,
        estimate: Optional[str] = None,
        start_date: Optional[str] = None,
        status: Optional[str] = None,
        all_day: Optional[bool] = None,
        due_reminder_day_before: Optional[bool] = None,
        cover_color: Optional[str] = None,
        type: Optional[str] = None,
        visibility: Optional[str] = None,
    ) -> Card:
        data = await self._request(
            "PATCH",
            f"/cards/{card_id}",
            json={
                "title": title,
                "description": description,
                "duedate": duedate,
                "done": done,
                "archived": archived,
                "priority": priority,
                "estimate": estimate,
                "startDate": start_date,
                "status": status,
                "allDay": all_day,
                "dueReminderDayBefore": due_reminder_day_before,
                "coverColor": cover_color,
                "type": type,
                "visibility": visibility,
            },
        )
        return Card.model_validate(data)

    async def delete_card(self, card_id: int) -> Any:
        return await self._request("DELETE", f"/cards/{card_id}")

    async def move_card(
        self, card_id: int, target_stack_id: int, after_card_id: Optional[int] = None
    ) -> Any:
        # after_card_id=None means "top of the target stack".
        return await self._request(
            "POST",
            f"/cards/{card_id}/move",
            json={"targetStackId": target_stack_id, "afterCardId": after_card_id},
        )

    # -------------------------------------------------- card labels / assignees
    async def assign_label(self, card_id: int, label_id: int) -> Any:
        return await self._request("PUT", f"/cards/{card_id}/labels/{label_id}")

    async def unassign_label(self, card_id: int, label_id: int) -> Any:
        return await self._request("DELETE", f"/cards/{card_id}/labels/{label_id}")

    async def assign_user(self, card_id: int, user_id: str) -> Any:
        return await self._request("PUT", f"/cards/{card_id}/assignees/{user_id}")

    async def unassign_user(self, card_id: int, user_id: str) -> Any:
        return await self._request("DELETE", f"/cards/{card_id}/assignees/{user_id}")

    # ---------------------------------------------------------------- comments
    async def list_comments(self, card_id: int) -> List[Comment]:
        data = await self._request("GET", f"/cards/{card_id}/comments")
        return [Comment.model_validate(c) for c in (data or [])]

    async def add_comment(
        self, card_id: int, body: str, parent_comment_id: Optional[int] = None
    ) -> Comment:
        # The controller field is `body` (NOT `text`); parentCommentId is
        # dropped by _request when None (a top-level comment).
        data = await self._request(
            "POST",
            f"/cards/{card_id}/comments",
            json={"body": body, "parentCommentId": parent_comment_id},
        )
        return Comment.model_validate(data)

    # --------------------------------------------------------------- checklist
    async def list_checklist(self, card_id: int) -> List[ChecklistItem]:
        data = await self._request("GET", f"/cards/{card_id}/checklist")
        return [ChecklistItem.model_validate(i) for i in (data or [])]

    async def add_checklist_item(self, card_id: int, title: str) -> ChecklistItem:
        data = await self._request(
            "POST", f"/cards/{card_id}/checklist", json={"title": title}
        )
        return ChecklistItem.model_validate(data)

    async def update_checklist_item(
        self,
        item_id: int,
        *,
        title: Optional[str] = None,
        done: Optional[bool] = None,
    ) -> ChecklistItem:
        # PATCH targets the item id directly (/checklist/{itemId}), NOT nested
        # under the card. None fields are dropped by _request.
        data = await self._request(
            "PATCH", f"/checklist/{item_id}", json={"title": title, "done": done}
        )
        return ChecklistItem.model_validate(data)

    # ------------------------------------------------------------------ labels
    async def create_label(
        self, board_id: int, title: str, color: Optional[str] = None
    ) -> Label:
        data = await self._request(
            "POST", "/labels", json={"boardId": board_id, "title": title, "color": color}
        )
        return Label.model_validate(data)

    # --------------------------------------------------------------- relations
    async def list_relations(self, card_id: int) -> Dict[str, Any]:
        # Returns a grouped dict {blocks, blockedBy, duplicates, relates}, each
        # a list of {id, cardId, title, done, hidden}.
        data = await self._request("GET", f"/cards/{card_id}/relations")
        return data or {}

    async def add_relation(
        self, card_id: int, other_card_id: int, kind: str
    ) -> Dict[str, Any]:
        # kind is one of "blocks" | "blocked_by" | "duplicates" | "relates";
        # the server swaps "blocked_by" into a stored blocks row, so pass the
        # string through unchanged (no client-side swapping). Same-board only;
        # self-relations and blocks-cycles are rejected server-side.
        data = await self._request(
            "POST",
            f"/cards/{card_id}/relations",
            json={"otherCardId": other_card_id, "kind": kind},
        )
        return data or {}

    async def remove_relation(self, card_id: int, relation_id: int) -> Any:
        return await self._request(
            "DELETE", f"/cards/{card_id}/relations/{relation_id}"
        )

    async def set_parent(
        self, card_id: int, parent_card_id: Optional[int]
    ) -> Card:
        # NB: _request drops None values, but here None is meaningful — it is
        # how the caller CLEARS the parent (the controller treats a null
        # parentCardId as "unset parent"). Send the request directly so the
        # explicit null survives the None-filter instead of being dropped.
        response = await self._client.request(
            "PUT",
            f"/cards/{card_id}/parent",
            json={"parentCardId": parent_card_id},
        )
        if response.status_code < 200 or response.status_code >= 300:
            raise KansoApiError(
                response.status_code, "PUT", f"/cards/{card_id}/parent", response.text
            )
        return Card.model_validate(response.json())

    # ------------------------------------------------------------- recurrence
    async def list_recur_rules(self, board_id: int) -> List[RecurRule]:
        data = await self._request("GET", f"/boards/{board_id}/recur-rules")
        return [RecurRule.model_validate(r) for r in (data or [])]

    async def create_recur_rule(
        self,
        board_id: int,
        template_card_id: int,
        target_stack_id: int,
        rrule: str,
        *,
        mode: int = 0,
        duedate_policy: int = 0,
        duedate_offset_seconds: int = 0,
        skip_while_open: bool = False,
        timezone: Optional[str] = None,
    ) -> RecurRule:
        # The board id rides in the PATH; the rest is the body. mode/policy/
        # offset/skip are sent explicitly (not dropped when 0/False) so the rule
        # is created with exactly what the caller asked for rather than with
        # whatever the controller defaults happen to be.
        data = await self._request(
            "POST",
            f"/boards/{board_id}/recur-rules",
            json={
                "templateCardId": template_card_id,
                "targetStackId": target_stack_id,
                "mode": mode,
                "rrule": rrule,
                "duedatePolicy": duedate_policy,
                "duedateOffsetSeconds": duedate_offset_seconds,
                "skipWhileOpen": skip_while_open,
                "timezone": timezone,
            },
        )
        return RecurRule.model_validate(data)

    async def update_recur_rule(
        self,
        rule_id: int,
        *,
        template_card_id: Optional[int] = None,
        target_stack_id: Optional[int] = None,
        mode: Optional[int] = None,
        rrule: Optional[str] = None,
        duedate_policy: Optional[int] = None,
        duedate_offset_seconds: Optional[int] = None,
        skip_while_open: Optional[bool] = None,
        enabled: Optional[bool] = None,
        timezone: Optional[str] = None,
    ) -> RecurRule:
        # PATCH targets the rule id directly (/recur-rules/{id}), NOT nested
        # under the board. None fields are dropped => left unchanged.
        data = await self._request(
            "PATCH",
            f"/recur-rules/{rule_id}",
            json={
                "templateCardId": template_card_id,
                "targetStackId": target_stack_id,
                "mode": mode,
                "rrule": rrule,
                "duedatePolicy": duedate_policy,
                "duedateOffsetSeconds": duedate_offset_seconds,
                "skipWhileOpen": skip_while_open,
                "enabled": enabled,
                "timezone": timezone,
            },
        )
        return RecurRule.model_validate(data)

    async def delete_recur_rule(self, rule_id: int) -> Any:
        return await self._request("DELETE", f"/recur-rules/{rule_id}")

    async def recur_rule_create_now(self, rule_id: int) -> Optional[Card]:
        # Returns {"card": {...}}: an explicit create-now always spawns (it
        # ignores skipWhileOpen), but the payload is typed nullable server-side,
        # so tolerate a null card rather than blowing up on validation.
        data = await self._request("POST", f"/recur-rules/{rule_id}/create-now")
        card = (data or {}).get("card")
        return Card.model_validate(card) if card else None

    # ----------------------------------------------------------------- my work
    async def list_my_cards(self) -> List[CardSummary]:
        data = await self._request("GET", "/my-cards")
        return [CardSummary.model_validate(c) for c in (data or [])]
