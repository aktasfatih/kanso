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
    BoardSummary,
    Card,
    CardSummary,
    Label,
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

    # ------------------------------------------------------------------ labels
    async def create_label(
        self, board_id: int, title: str, color: Optional[str] = None
    ) -> Label:
        data = await self._request(
            "POST", "/labels", json={"boardId": board_id, "title": title, "color": color}
        )
        return Label.model_validate(data)

    # ----------------------------------------------------------------- my work
    async def list_my_cards(self) -> List[CardSummary]:
        data = await self._request("GET", "/my-cards")
        return [CardSummary.model_validate(c) for c in (data or [])]
