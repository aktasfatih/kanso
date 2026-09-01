# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""MCP tool definitions for the Kanso kanban API.

Each tool is a thin, well-documented wrapper over a :class:`KansoClient`
method. Read tools are annotated ``readOnlyHint=True`` so clients can surface
them as safe. Tools return plain dicts/lists (pydantic models dumped) so the
payload is JSON-serializable for the MCP transport.
"""

from __future__ import annotations

from typing import List, Optional

from mcp.server.fastmcp import FastMCP

from kanso_mcp.client import KansoClient


def _without_archived_cards(board: dict) -> dict:
    """Drop archived cards from a dumped board payload.

    The board endpoint (`GET /boards/{id}`) has no `archived` query parameter —
    it deliberately ships archived cards alongside live ones, because the web
    client renders both views (the board and the Archived page) from that one
    payload, and its shape is pinned by the ETag / delta-sync contract. So the
    filter is a presentation choice made HERE, in the tool layer, leaving
    `KansoClient.get_board` a faithful mirror of the endpoint.

    Only `cards` is touched — every other key is passed through untouched — and
    the `archived` flag is left on each card that survives, so a caller that
    asked for both kinds can still tell them apart. The argument is a dumped
    :class:`BoardDetail`, so `cards` and each card's `archived` are always
    present (both are modelled with defaults).
    """
    return {
        **board,
        "cards": [c for c in board["cards"] if not c["archived"]],
    }


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
    async def kanso_get_board(board_id: int, include_archived: bool = False) -> dict:
        """Read one board in full: the board record, its stacks (columns), its
        labels and all card SUMMARIES (no descriptions — open a card with
        `kanso_get_card` to read its description).

        Archived cards are EXCLUDED by default: `cards` then holds every
        non-archived card of the board — including cards that sit in an archived
        (hidden) column, since archiving a column does not archive its cards.
        Pass `include_archived=True` to get archived cards too; either way every
        card keeps its `archived` flag, so you can tell the two apart. Only
        `cards` is filtered — `stacks` (archived columns included) and `labels`
        always come back in full. The filter is a view, not an access rule:
        `kanso_get_card` still reads an archived card by id.

        Args:
            board_id: The numeric board id.
            include_archived: Include archived cards in `cards` (default false).
        """
        board = (await client.get_board(board_id)).model_dump()
        return board if include_archived else _without_archived_cards(board)

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

    # ---------------------------------------------------------------- comments
    @mcp.tool(
        title="List a Kanso card's comments",
        annotations={"readOnlyHint": True},
    )
    async def kanso_list_comments(card_id: int) -> List[dict]:
        """List the comments on a card, oldest first.

        Each comment carries its `author` (uid), `authorDisplayName`, markdown
        `body`, `createdAt`/`editedAt` timestamps and, for replies, the
        `parentCommentId` of the top-level comment it answers.

        Args:
            card_id: The numeric card id.
        """
        comments = await client.list_comments(card_id)
        return [c.model_dump() for c in comments]

    @mcp.tool(title="Add a comment to a Kanso card")
    async def kanso_add_comment(
        card_id: int, body: str, parent_comment_id: Optional[int] = None
    ) -> dict:
        """Post a comment on a card. Pass `parent_comment_id` to reply to an
        existing top-level comment (replies are one level deep).

        Args:
            card_id: The numeric card id.
            body: The comment text (markdown).
            parent_comment_id: Optional id of the top-level comment to reply to;
                omit (null) for a new top-level comment.
        """
        return (
            await client.add_comment(card_id, body, parent_comment_id)
        ).model_dump()

    # --------------------------------------------------------------- checklist
    @mcp.tool(
        title="List a Kanso card's checklist",
        annotations={"readOnlyHint": True},
    )
    async def kanso_list_checklist(card_id: int) -> List[dict]:
        """List a card's checklist items, in order.

        Each item carries its `id`, `title` and `done` state. Use the item `id`
        with `kanso_toggle_checklist_item` to tick it off.

        Args:
            card_id: The numeric card id.
        """
        items = await client.list_checklist(card_id)
        return [i.model_dump() for i in items]

    @mcp.tool(title="Add a checklist item to a Kanso card")
    async def kanso_add_checklist_item(card_id: int, title: str) -> dict:
        """Add a checklist item (a flat todo line) to a card.

        Returns the created item, including its `id` — pass that to
        `kanso_toggle_checklist_item` to mark it done later.

        Args:
            card_id: The numeric card id.
            title: The checklist item text.
        """
        return (await client.add_checklist_item(card_id, title)).model_dump()

    @mcp.tool(title="Toggle a Kanso checklist item")
    async def kanso_toggle_checklist_item(item_id: int, done: bool) -> dict:
        """Mark a checklist item done (true) or not done (false).

        Note `item_id` is the checklist item's own id (from
        `kanso_list_checklist` or `kanso_get_card`), NOT the card id.

        Args:
            item_id: The numeric checklist item id.
            done: Whether the item is completed.
        """
        return (
            await client.update_checklist_item(item_id, done=done)
        ).model_dump()

    # --------------------------------------------------------------- relations
    @mcp.tool(
        title="List a Kanso card's relations",
        annotations={"readOnlyHint": True},
    )
    async def kanso_list_relations(card_id: int) -> dict:
        """List a card's relations to other cards, grouped by kind.

        Returns an object with four lists — `blocks` (cards this card blocks),
        `blockedBy` (cards blocking this one), `duplicates` and `relates`. Each
        entry is `{id, cardId, title, done, hidden}` where `id` is the RELATION
        id (pass it to `kanso_remove_relation`) and `cardId` is the other card.

        Args:
            card_id: The numeric card id.
        """
        return await client.list_relations(card_id)

    @mcp.tool(title="Add a relation between two Kanso cards")
    async def kanso_add_relation(card_id: int, other_card_id: int, kind: str) -> dict:
        """Link a card to another card on the SAME board.

        `kind` must be one of:
          - "blocks"      — this card blocks `other_card_id`
          - "blocked_by"  — this card is blocked by `other_card_id`
          - "duplicates"  — this card duplicates `other_card_id`
          - "relates"     — this card is related to `other_card_id`

        Both cards must be on the same board. Self-relations and cycles of
        blocking relations are rejected by the server (surfaced as an API error).

        Args:
            card_id: The card the relation is added from.
            other_card_id: The other card to link to (same board).
            kind: One of "blocks", "blocked_by", "duplicates", "relates".
        """
        return await client.add_relation(card_id, other_card_id, kind)

    @mcp.tool(title="Remove a relation from a Kanso card")
    async def kanso_remove_relation(card_id: int, relation_id: int) -> dict:
        """Remove a card-to-card relation.

        Note `relation_id` is the relation's own id (the `id` field from
        `kanso_list_relations`), NOT the id of the other card.

        Args:
            card_id: The card the relation belongs to.
            relation_id: The numeric relation id to remove.
        """
        await client.remove_relation(card_id, relation_id)
        return {"removed": True, "cardId": card_id, "relationId": relation_id}

    @mcp.tool(title="Set or clear a Kanso card's parent")
    async def kanso_set_card_parent(
        card_id: int, parent_card_id: Optional[int] = None
    ) -> dict:
        """Set (or clear) a card's parent, building a one-level subtask
        hierarchy. Parent and child must be on the SAME board.

        Pass `parent_card_id` to make `card_id` a subtask of it; pass null/omit
        to CLEAR the parent (detach the card from its parent). Invalid requests
        (cross-board, deeper nesting) are rejected by the server.

        Args:
            card_id: The card to (re)parent.
            parent_card_id: The parent card id, or null to clear the parent.
        """
        return (await client.set_parent(card_id, parent_card_id)).model_dump()

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

    # ------------------------------------------------------------- recurrence
    @mcp.tool(
        title="List a Kanso board's recurring-card rules",
        annotations={"readOnlyHint": True},
    )
    async def kanso_list_recur_rules(board_id: int) -> List[dict]:
        """List a board's recurring-card rules (its repeat schedules).

        Recurrence in Kanso is BOARD AUTOMATION, not a card field: a rule is
        anchored on a *template* card and spawns work into a target stack on a
        schedule. Rules whose template card is in the trash are hidden.

        Each rule carries `templateCardId`, `targetStackId`, `rrule`, `mode`,
        `duedatePolicy`, `timezone`, `enabled` and the server-maintained
        `nextOccurrenceAt` / `lastSpawnedAt` / `occurrencesSpawned` counters
        (unix timestamps).

        Args:
            board_id: The numeric board id.
        """
        rules = await client.list_recur_rules(board_id)
        return [r.model_dump() for r in rules]

    @mcp.tool(title="Create a Kanso recurring-card rule")
    async def kanso_create_recur_rule(
        board_id: int,
        template_card_id: int,
        target_stack_id: int,
        rrule: str,
        mode: int = 0,
        duedate_policy: int = 0,
        duedate_offset_seconds: int = 0,
        skip_while_open: bool = False,
        timezone: Optional[str] = None,
    ) -> dict:
        """Make a card recur on a schedule. Requires MANAGE on the board.

        There is no "recurrence" field on a card — to make a card repeat, create
        it first (`kanso_create_card`) and then pass its id here as the
        `template_card_id`. The template card and the target stack must both
        belong to `board_id`.

        `rrule` is an RFC 5545 recurrence rule WITHOUT the "RRULE:" prefix, e.g.
        "FREQ=DAILY", "FREQ=WEEKLY;BYDAY=MO,WE,FR", "FREQ=MONTHLY;BYMONTHDAY=1",
        "FREQ=DAILY;COUNT=10", "FREQ=WEEKLY;UNTIL=20261231T000000Z".
        Unparseable rules are rejected with an API error.

        ANCHOR (the RFC 5545 DTSTART the schedule is expanded from) — the server
        picks it, you cannot pass it here. It is the template card's **start
        date**, else the template card's **due date**, else the **rule's
        creation time** as a last resort. So a daily rule on an undated card
        created at 09:00 fires at 09:00.

        DTSTART is itself part of the recurrence set, so if the template card is
        dated in the FUTURE the first fire (`nextOccurrenceAt`) is that date to
        the minute, REGARDLESS of any BY* part you pass — a
        "FREQ=MONTHLY;BYMONTHDAY=15" rule on a card starting 4 March first fires
        4 March, then 15 March, then 15 April. The BY* parts shape every
        occurrence after the anchor, not the anchor itself. (If the anchor is in
        the past, the first fire is the next occurrence after now and the BY*
        parts do apply to it.)

        To control the anchor, DATE THE CARD FIRST: call `kanso_update_card`
        with `start_date` (or `due_date`) on the template card BEFORE creating
        the rule. Creating the rule first and dating the card afterwards
        re-points the schedule too (but only while the rule is enabled), and the
        rule is armed on the old anchor in the meantime.

        Args:
            board_id: The board the rule lives on.
            template_card_id: The card to repeat (the template).
            target_stack_id: The stack each occurrence lands in.
            rrule: The RFC 5545 schedule, e.g. "FREQ=WEEKLY;BYDAY=MO".
            mode: 0 = CLONE (default) — each occurrence creates a FRESH card in
                the target stack, copying the template's title, description,
                labels and assignees. 1 = RESET — move the template card ITSELF
                back to the target stack and clear its done state (chore style;
                there is only ever one card).
            duedate_policy: 0 = due at the occurrence time (default),
                1 = due at the occurrence + `duedate_offset_seconds`,
                2 = spawned cards get no due date.
            duedate_offset_seconds: Offset for policy 1, in seconds (0 to ten
                years); ignored by the other policies.
            skip_while_open: CLONE mode only — skip a scheduled spawn while the
                previously spawned card is still open (no pile-up of undone
                copies). Manual `kanso_recur_rule_create_now` ignores this.
            timezone: IANA timezone id the schedule is expanded in, e.g.
                "Europe/Istanbul". Occurrences are floating wall-clock times, so
                "daily at 09:00" stays 09:00 local across DST. Omit to use the
                calling user's personal Nextcloud timezone.
        """
        return (
            await client.create_recur_rule(
                board_id,
                template_card_id,
                target_stack_id,
                rrule,
                mode=mode,
                duedate_policy=duedate_policy,
                duedate_offset_seconds=duedate_offset_seconds,
                skip_while_open=skip_while_open,
                timezone=timezone,
            )
        ).model_dump()

    @mcp.tool(title="Update a Kanso recurring-card rule")
    async def kanso_update_recur_rule(
        rule_id: int,
        template_card_id: Optional[int] = None,
        target_stack_id: Optional[int] = None,
        mode: Optional[int] = None,
        rrule: Optional[str] = None,
        duedate_policy: Optional[int] = None,
        duedate_offset_seconds: Optional[int] = None,
        skip_while_open: Optional[bool] = None,
        enabled: Optional[bool] = None,
        timezone: Optional[str] = None,
    ) -> dict:
        """Change a recurring-card rule. Only the fields you pass are changed.
        Requires MANAGE on the rule's board.

        Note `rule_id` is the RULE's own id (from `kanso_list_recur_rules`), NOT
        a card id. Pause a schedule with `enabled=false` and resume it with
        `enabled=true` (resuming re-arms it to the next future occurrence).

        The schedule's anchor is not a field here either — see
        `kanso_create_recur_rule` for how the server derives it from the
        template card's dates, and change it by re-dating that card with
        `kanso_update_card`. Note that re-pointing `template_card_id` on its own
        does NOT recompute the pending `nextOccurrenceAt`: it stays on the old
        card's anchor until the schedule is edited (`rrule`/`timezone`), the
        rule is re-enabled, or the new template card is re-dated.

        Args:
            rule_id: The numeric rule id.
            template_card_id: Re-anchor the rule on a different template card
                (same board).
            target_stack_id: Spawn into a different stack (same board).
            mode: 0 = CLONE, 1 = RESET (see `kanso_create_recur_rule`).
            rrule: A new RFC 5545 schedule, e.g. "FREQ=WEEKLY;BYDAY=MO".
            duedate_policy: 0 = at occurrence, 1 = occurrence + offset, 2 = none.
            duedate_offset_seconds: Offset in seconds for policy 1.
            skip_while_open: Skip a spawn while the last card is still open.
            enabled: false pauses the rule, true resumes it.
            timezone: New IANA timezone id for the schedule; omit to leave the
                rule's zone unchanged (it cannot be cleared).
        """
        return (
            await client.update_recur_rule(
                rule_id,
                template_card_id=template_card_id,
                target_stack_id=target_stack_id,
                mode=mode,
                rrule=rrule,
                duedate_policy=duedate_policy,
                duedate_offset_seconds=duedate_offset_seconds,
                skip_while_open=skip_while_open,
                enabled=enabled,
                timezone=timezone,
            )
        ).model_dump()

    @mcp.tool(
        title="Delete a Kanso recurring-card rule",
        annotations={"destructiveHint": True},
    )
    async def kanso_delete_recur_rule(rule_id: int) -> dict:
        """Delete a recurring-card rule. The template card and any cards it
        already spawned are left alone — only the schedule goes away. To stop a
        schedule reversibly, use `kanso_update_recur_rule(enabled=false)`.

        Args:
            rule_id: The numeric rule id (NOT a card id).
        """
        result = await client.delete_recur_rule(rule_id)
        return {"deleted": True, "result": result}

    @mcp.tool(title="Spawn a Kanso recurring-card rule now")
    async def kanso_recur_rule_create_now(rule_id: int) -> dict:
        """Fire a recurring-card rule once immediately, ignoring its schedule
        (and ignoring `skipWhileOpen` — an explicit run always spawns).

        This does NOT bring the schedule forward: the next scheduled occurrence
        still fires as planned, so this just stamps one extra card now. Returns
        the spawned card (CLONE mode) or the reset template card (RESET mode).

        Args:
            rule_id: The numeric rule id (NOT a card id).
        """
        card = await client.recur_rule_create_now(rule_id)
        return {"spawned": card is not None, "card": card.model_dump() if card else None}

    # ------------------------------------------------------------------ search
    @mcp.tool(
        title="Search Kanso cards",
        annotations={"readOnlyHint": True},
    )
    async def kanso_search_cards(
        query: str, board_id: Optional[int] = None, limit: int = 25
    ) -> dict:
        """Find cards by text across every board you can read — the fast way to
        locate a card when you know roughly what it says but not where it is.

        Matching is a plain case-insensitive SUBSTRING of one term over card
        titles, card descriptions and comment bodies. There is deliberately no
        query language: no AND/OR, no quotes, no field:value, no wildcards — a
        query of `due invoice` looks for that literal string, not two words.
        Terms shorter than 2 characters return nothing.

        Each hit carries `type` ("card" when the term matched the title or
        description, "comment" when it matched a comment on that card),
        `cardId`, `boardId`, the card `title`, a `snippet` of the matched text
        and the server's `rank` (highest first: title, then description, then
        comment). Hits are card LOCATORS, not card records — feed `cardId` to
        `kanso_get_card` for the full card. `total` is how many hits the server
        found in all; `results` is the first `limit` of them.

        Results are ACL-filtered by the server to the boards you can read, so a
        card on a board you have no access to can never appear here — and
        `board_id` is only a narrowing FILTER on that set, never a way to reach
        past it.

        Args:
            query: The text to look for (min. 2 characters).
            board_id: Restrict the search to one board; omit to search every
                board you can read.
            limit: Maximum hits to return (server caps this at 50).
        """
        return (await client.search(query, board_id, limit)).model_dump()

    # ----------------------------------------------------------------- my work
    @mcp.tool(
        title="List my Kanso cards",
        annotations={"readOnlyHint": True},
    )
    async def kanso_list_my_cards() -> List[dict]:
        """List the open cards assigned to the current user across every board
        they can read ("My tasks").

        Open means exactly that: the server returns only cards that are neither
        archived nor done, and there is no way to ask for the rest — to see a
        board's archived cards, call `kanso_get_board` with
        `include_archived=True`. The result is capped server-side at 200 cards.
        """
        cards = await client.list_my_cards()
        return [c.model_dump() for c in cards]
