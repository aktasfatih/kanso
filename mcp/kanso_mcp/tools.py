# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""MCP tool definitions for the Kanso kanban API.

Each tool is a thin, well-documented wrapper over a :class:`KansoClient`
method. Read tools are annotated ``readOnlyHint=True`` so clients can surface
them as safe. Tools return plain dicts/lists (pydantic models dumped) so the
payload is JSON-serializable for the MCP transport.
"""

from __future__ import annotations

from typing import Any, List, Optional

from mcp.server.fastmcp import FastMCP

from kanso_mcp.client import KansoClient


def register_tools(mcp: FastMCP, client: KansoClient) -> None:
    """Register every Kanso tool on the given FastMCP instance."""

    # ------------------------------------------------------------------ boards
    @mcp.tool(
        title="List Kanso boards",
        annotations={"readOnlyHint": True},
    )
    async def kanso_list_boards() -> List[dict]:
        """List all Kanso boards the user can access, with per-board stats.

        Returns board summaries (id, title, prefix, archived, card counts). Use
        `kanso_get_board` to read a single board's stacks and cards.
        """
        boards = await client.list_boards()
        return [b.model_dump() for b in boards]

    @mcp.tool(
        title="Get a Kanso board",
        annotations={"readOnlyHint": True},
    )
    async def kanso_get_board(board_id: int) -> dict:
        """Read one board in full: the board record, its stacks (columns), its
        labels and all card SUMMARIES (no descriptions — open a card with
        `kanso_get_card` to read its description).

        Args:
            board_id: The numeric board id.
        """
        return (await client.get_board(board_id)).model_dump()

    @mcp.tool(
        title="List Kanso board members",
        annotations={"readOnlyHint": True},
    )
    async def kanso_list_board_members(
        board_id: int, q: Optional[str] = None
    ) -> List[dict]:
        """List the users who can be assigned to cards on a board.

        Returns each participant's `uid` and `displayName`. Feed a returned
        `uid` into `kanso_assign_user` to assign that person to a card. Results
        are capped server-side (~25); pass `q` to filter by name/uid substring
        when a board has more members than that.

        Args:
            board_id: The numeric board id.
            q: Optional case-insensitive substring filter over uid / display name.
        """
        members = await client.list_board_members(board_id, q)
        return [m.model_dump() for m in members]

    @mcp.tool(title="Create a Kanso board")
    async def kanso_create_board(title: str, color: Optional[str] = None) -> dict:
        """Create a new board.

        Args:
            title: The board title.
            color: Optional board colour (6-hex, no leading '#'), e.g. "0082c9".
        """
        return (await client.create_board(title, color)).model_dump()

    @mcp.tool(title="Update a Kanso board")
    async def kanso_update_board(
        board_id: int,
        title: Optional[str] = None,
        color: Optional[str] = None,
        archived: Optional[bool] = None,
        prefix: Optional[str] = None,
    ) -> dict:
        """Update a board. Only the fields you pass are changed.

        Args:
            board_id: The numeric board id.
            title: New title.
            color: New colour (6-hex, no '#').
            archived: Archive (true) or unarchive (false) the board.
            prefix: New card-reference prefix (e.g. "KAN"), used for KAN-123 ids.
        """
        return (
            await client.update_board(
                board_id, title=title, color=color, archived=archived, prefix=prefix
            )
        ).model_dump()

    @mcp.tool(
        title="Delete a Kanso board",
        annotations={"destructiveHint": True},
    )
    async def kanso_delete_board(board_id: int) -> dict:
        """Delete a board and everything on it. This is destructive.

        Args:
            board_id: The numeric board id.
        """
        result = await client.delete_board(board_id)
        return {"deleted": True, "result": result}

    # ------------------------------------------------------------------ stacks
    @mcp.tool(title="Create a Kanso stack")
    async def kanso_create_stack(board_id: int, title: str) -> dict:
        """Create a stack (column) on a board. It is appended to the right.

        Args:
            board_id: The board to add the stack to.
            title: The stack title, e.g. "To Do".
        """
        return (await client.create_stack(board_id, title)).model_dump()

    @mcp.tool(title="Update a Kanso stack")
    async def kanso_update_stack(
        stack_id: int,
        title: Optional[str] = None,
        archived: Optional[bool] = None,
        wip_limit: Optional[int] = None,
        color: Optional[str] = None,
    ) -> dict:
        """Update a stack. Only the fields you pass are changed.

        Args:
            stack_id: The numeric stack id.
            title: New title.
            archived: Archive (true) or unarchive (false) the stack.
            wip_limit: Work-in-progress card cap (null/omit to leave unchanged).
            color: New colour (6-hex, no '#').
        """
        return (
            await client.update_stack(
                stack_id, title=title, archived=archived, wip_limit=wip_limit, color=color
            )
        ).model_dump()

    @mcp.tool(
        title="Delete a Kanso stack",
        annotations={"destructiveHint": True},
    )
    async def kanso_delete_stack(stack_id: int) -> dict:
        """Delete (soft-delete) a stack and its cards.

        Args:
            stack_id: The numeric stack id.
        """
        result = await client.delete_stack(stack_id)
        return {"deleted": True, "result": result}

    @mcp.tool(title="Move a Kanso stack")
    async def kanso_move_stack(
        stack_id: int, after_stack_id: Optional[int] = None
    ) -> dict:
        """Reorder a stack within its board.

        Args:
            stack_id: The stack to move.
            after_stack_id: Place the stack directly after this stack id; omit
                (null) to move it to the first position.
        """
        result = await client.move_stack(stack_id, after_stack_id)
        return {"moved": True, "result": result}

    # ------------------------------------------------------------------- cards
    @mcp.tool(
        title="Get a Kanso card",
        annotations={"readOnlyHint": True},
    )
    async def kanso_get_card(card_id: int) -> dict:
        """Read one card in full, including its description, checklist,
        assignees, labels and children.

        Args:
            card_id: The numeric card id.
        """
        return (await client.get_card(card_id)).model_dump()

    @mcp.tool(title="Create a Kanso card")
    async def kanso_create_card(
        stack_id: int,
        title: str,
        duedate: Optional[str] = None,
        all_day: Optional[bool] = None,
    ) -> dict:
        """Create a card at the top or bottom of a stack (per board setting).
        The description is set separately with `kanso_update_card`.

        Args:
            stack_id: The stack (column) to create the card in.
            title: The card title.
            duedate: Optional ISO-8601 due date/time, e.g. "2026-09-01T17:00:00+00:00".
            all_day: Whether the due date is an all-day date (no time component).
        """
        return (
            await client.create_card(stack_id, title, duedate=duedate, all_day=all_day)
        ).model_dump()

    @mcp.tool(title="Update a Kanso card")
    async def kanso_update_card(
        card_id: int,
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
    ) -> dict:
        """Update a card. Only the fields you pass are changed.

        Args:
            card_id: The numeric card id.
            title: New title.
            description: New markdown description.
            duedate: New ISO-8601 due date/time (empty string clears it).
            done: Mark the card done (true) or not done (false).
            archived: Archive (true) or unarchive (false) the card.
            priority: Integer priority.
            estimate: Effort estimate token (per the board's estimate scale).
            start_date: ISO-8601 start date/time (empty string clears it).
            status: Workflow status automation — one of "not_started",
                "in_progress" or "done". DISTINCT from the `done` boolean: setting
                this stamps the card's startedAt / doneAt timestamps accordingly.
            all_day: Whether the due date is an all-day date (no time component).
            due_reminder_day_before: Remind the day before the due date (true/false).
            cover_color: Card cover colour as bare 6-hex, NO leading '#',
                e.g. "e63946"; empty string clears it.
            type: Card type — one of "" (none), "bug", "feature", "task" or "chore".
            visibility: Card visibility — one of "public", "internal" or "private".
        """
        return (
            await client.update_card(
                card_id,
                title=title,
                description=description,
                duedate=duedate,
                done=done,
                archived=archived,
                priority=priority,
                estimate=estimate,
                start_date=start_date,
                status=status,
                all_day=all_day,
                due_reminder_day_before=due_reminder_day_before,
                cover_color=cover_color,
                type=type,
                visibility=visibility,
            )
        ).model_dump()

    @mcp.tool(
        title="Delete a Kanso card",
        annotations={"destructiveHint": True},
    )
    async def kanso_delete_card(card_id: int) -> dict:
        """Delete (soft-delete) a card.

        Args:
            card_id: The numeric card id.
        """
        result = await client.delete_card(card_id)
        return {"deleted": True, "result": result}

    @mcp.tool(title="Move a Kanso card")
    async def kanso_move_card(
        card_id: int, target_stack_id: int, after_card_id: Optional[int] = None
    ) -> dict:
        """Move a card into a stack, optionally after a specific card.

        Args:
            card_id: The card to move.
            target_stack_id: The stack to move the card into.
            after_card_id: Place the card directly after this card id; omit
                (null) to place it at the top of the target stack.
        """
        result = await client.move_card(card_id, target_stack_id, after_card_id)
        return {"moved": True, "result": result}

    # -------------------------------------------------- card labels / assignees
    @mcp.tool(title="Assign a label to a Kanso card")
    async def kanso_assign_label(card_id: int, label_id: int) -> dict:
        """Assign an existing board label to a card. Idempotent.

        Args:
            card_id: The card id.
            label_id: The label id (must belong to the card's board).
        """
        await client.assign_label(card_id, label_id)
        return {"assigned": True, "cardId": card_id, "labelId": label_id}

    @mcp.tool(title="Remove a label from a Kanso card")
    async def kanso_unassign_label(card_id: int, label_id: int) -> dict:
        """Remove a label from a card. Idempotent.

        Args:
            card_id: The card id.
            label_id: The label id.
        """
        await client.unassign_label(card_id, label_id)
        return {"unassigned": True, "cardId": card_id, "labelId": label_id}

    @mcp.tool(title="Assign a user to a Kanso card")
    async def kanso_assign_user(card_id: int, user_id: str) -> dict:
        """Assign a Nextcloud user to a card. Idempotent.

        Args:
            card_id: The card id.
            user_id: The Nextcloud user id (uid) to assign.
        """
        await client.assign_user(card_id, user_id)
        return {"assigned": True, "cardId": card_id, "userId": user_id}

    @mcp.tool(title="Unassign a user from a Kanso card")
    async def kanso_unassign_user(card_id: int, user_id: str) -> dict:
        """Remove an assigned user from a card. Idempotent.

        Args:
            card_id: The card id.
            user_id: The Nextcloud user id (uid) to unassign.
        """
        await client.unassign_user(card_id, user_id)
        return {"unassigned": True, "cardId": card_id, "userId": user_id}

    # ------------------------------------------------------------------ labels
    @mcp.tool(title="Create a Kanso label")
    async def kanso_create_label(
        board_id: int, title: str, color: Optional[str] = None
    ) -> dict:
        """Create a label on a board (then attach it with `kanso_assign_label`).

        Args:
            board_id: The board to create the label on.
            title: The label text.
            color: Optional colour (6-hex, no '#'), e.g. "e63946".
        """
        return (await client.create_label(board_id, title, color)).model_dump()

    # ----------------------------------------------------------------- my work
    @mcp.tool(
        title="List my Kanso cards",
        annotations={"readOnlyHint": True},
    )
    async def kanso_list_my_cards() -> List[dict]:
        """List the open cards assigned to the current user across every board
        they can read ("My tasks").
        """
        cards = await client.list_my_cards()
        return [c.model_dump() for c in cards]
