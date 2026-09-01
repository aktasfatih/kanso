# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Pydantic models mirroring the JSON the Kanso API returns.

The models are intentionally permissive (``extra='ignore'`` + ``Optional``
fields): the API payloads are rich and evolve, and the MCP server only needs a
stable, typed core to hand back to the LLM. Unknown fields are dropped rather
than raising.
"""

from __future__ import annotations

from typing import Any, List, Optional

from pydantic import BaseModel, ConfigDict, Field


class _Base(BaseModel):
    model_config = ConfigDict(extra="ignore", populate_by_name=True)


class BoardStats(_Base):
    cardCount: int = 0
    doneCount: int = 0
    progress: float = 0
    needsReview: int = 0
    overdue: int = 0


class BoardSummary(_Base):
    """A board row from ``GET /boards`` (list view, carries stats)."""

    id: int
    title: str
    owner: Optional[str] = None
    color: Optional[str] = None
    background: Optional[str] = None
    archived: bool = False
    lastModified: int = 0
    estimateScale: Optional[str] = None
    newCardsOnTop: bool = False
    prefix: Optional[str] = None
    chatUrl: Optional[str] = None
    groupId: Optional[int] = None
    pinned: bool = False
    permissions: Optional[int] = None
    role: Optional[str] = None
    stats: Optional[BoardStats] = None


class Board(_Base):
    """A board's own record (as embedded under ``board`` in ``GET /boards/{id}``)."""

    id: int
    title: str
    owner: Optional[str] = None
    color: Optional[str] = None
    background: Optional[str] = None
    archived: bool = False
    lastModified: int = 0
    estimateScale: Optional[str] = None
    newCardsOnTop: bool = False
    prefix: Optional[str] = None
    chatUrl: Optional[str] = None


class Stack(_Base):
    id: int
    boardId: Optional[int] = None
    title: str
    sortKey: Optional[str] = None
    archived: bool = False
    role: Optional[int] = None
    wipLimit: Optional[int] = None
    color: Optional[str] = None


class Label(_Base):
    id: int
    boardId: Optional[int] = None
    title: Optional[str] = None
    color: Optional[str] = None


class BoardMember(_Base):
    """A board participant from ``GET /boards/{id}/participants``: an assignable
    Nextcloud user (uid + display name). The uid is what ``kanso_assign_user``
    expects.
    """

    uid: str
    displayName: Optional[str] = None


class Comment(_Base):
    """A card comment from ``GET /api/cards/{id}/comments`` (or the single
    comment returned by a create). The body is raw markdown; ``author`` is the
    uid and ``authorDisplayName`` its resolved display name. ``createdAt`` /
    ``editedAt`` are unix timestamps. Reactions are passed through untyped.
    """

    id: int
    cardId: Optional[int] = None
    parentCommentId: Optional[int] = None
    author: Optional[str] = None
    authorDisplayName: Optional[str] = None
    body: Optional[str] = None
    createdAt: int = 0
    editedAt: int = 0
    reactions: List[Any] = Field(default_factory=list)


class ChecklistItem(_Base):
    """A checklist item from ``GET/POST /api/cards/{id}/checklist`` or a
    ``PATCH /api/checklist/{itemId}``. ``done`` is the source of truth; the
    payload also carries assignee/due-date step fields (#3745) which are passed
    through untyped rather than modelled.
    """

    id: int
    cardId: Optional[int] = None
    title: Optional[str] = None
    done: bool = False
    sortKey: Optional[str] = None


class CardSummary(_Base):
    """A card summary (board payload / delta upsert): no description."""

    id: int
    boardId: Optional[int] = None
    stackId: Optional[int] = None
    title: str
    sortKey: Optional[str] = None
    duedate: Optional[str] = None
    startDate: Optional[str] = None
    doneAt: int = 0
    archived: bool = False
    allDay: bool = False
    owner: Optional[str] = None
    createdAt: int = 0
    lastModified: int = 0
    parentCardId: Optional[int] = None
    priority: int = 0
    estimate: Optional[str] = None
    boardSeq: Optional[int] = None
    coverColor: Optional[str] = None
    type: Optional[str] = None
    isTemplate: bool = False
    visibility: Optional[str] = None
    labelIds: List[int] = Field(default_factory=list)
    assigneeIds: List[str] = Field(default_factory=list)


class Card(CardSummary):
    """A full card (``GET /cards/{id}``): summary + description + detail arrays."""

    description: Optional[str] = None
    contacts: List[Any] = Field(default_factory=list)
    reviews: List[Any] = Field(default_factory=list)
    checklistItems: List[Any] = Field(default_factory=list)
    children: List[Any] = Field(default_factory=list)
    parent: Optional[Any] = None
    commentCount: int = 0
    attachmentCount: int = 0
    timeSpent: int = 0
    projectIds: List[int] = Field(default_factory=list)
    fieldValues: List[Any] = Field(default_factory=list)
    relations: Optional[Any] = None


class SearchHit(_Base):
    """One hit from ``GET /api/search``.

    ``type`` is ``"card"`` (the term matched the card's title or description) or
    ``"comment"`` (it matched a comment body — ``title`` is then the commented
    card's title and ``commentId`` the matching comment). Either way ``cardId`` +
    ``boardId`` locate the card, and ``snippet`` is a trimmed excerpt of whatever
    text was searched. ``rank`` is the server's ordering weight (3 = title,
    2 = description, 1 = comment) — pass-through, not something to re-sort on.
    """

    type: Optional[str] = None
    cardId: Optional[int] = None
    boardId: Optional[int] = None
    commentId: Optional[int] = None
    title: Optional[str] = None
    snippet: Optional[str] = None
    rank: int = 0


class SearchResults(_Base):
    """The ``GET /api/search`` payload: the echoed ``query``, the ``total``
    number of hits the server found (before the limit/offset page was cut) and
    the page of ``results`` itself.

    Every hit is already ACL-filtered server-side to the boards the
    authenticated user can read — the payload never carries a card from a board
    they cannot see, so nothing here needs (or should get) a client-side filter.
    """

    query: str = ""
    total: int = 0
    results: List[SearchHit] = Field(default_factory=list)


class RecurRule(_Base):
    """A recurring-card rule from ``/api/boards/{id}/recur-rules``.

    Board automation, NOT a card field: the rule is anchored on a *template*
    card (``templateCardId``) and spawns into ``targetStackId`` on an RFC 5545
    ``rrule`` schedule, expanded as floating wall-clock time in ``timezone``.
    ``mode`` is 0 (CLONE — a fresh card per occurrence) or 1 (RESET — move the
    template back and clear its done state). ``duedatePolicy`` is 0 (due at the
    occurrence), 1 (occurrence + ``duedateOffsetSeconds``) or 2 (no due date).
    The bookkeeping fields (``lastSpawnedAt``, ``nextOccurrenceAt``,
    ``occurrencesSpawned``) are server-maintained unix timestamps/counters.
    """

    id: int
    boardId: Optional[int] = None
    templateCardId: Optional[int] = None
    targetStackId: Optional[int] = None
    mode: int = 0
    rrule: Optional[str] = None
    duedatePolicy: int = 0
    duedateOffsetSeconds: int = 0
    skipWhileOpen: bool = False
    enabled: bool = False
    owner: Optional[str] = None
    lastSpawnedAt: int = 0
    nextOccurrenceAt: int = 0
    occurrencesSpawned: int = 0
    createdAt: int = 0
    timezone: Optional[str] = None


class BoardDetail(_Base):
    """The full ``GET /boards/{id}`` payload: board + stacks + card summaries +
    labels, plus the board-scoped side payloads the endpoint ships alongside
    them.

    ``reviewTypes``, ``cardFields``, ``blocksEdges``, ``acl`` and
    ``subscription`` are declared so ``extra='ignore'`` stops dropping them on
    the floor — the server has always returned them
    (``BoardController::show``), the model just never named them. They are
    typed loosely on purpose: they exist to be handed to the LLM as context
    (review workflow, custom-field definitions, dependency edges, sharing,
    watch state), not to be validated here.
    """

    board: Board
    stacks: List[Stack] = Field(default_factory=list)
    cards: List[CardSummary] = Field(default_factory=list)
    labels: List[Label] = Field(default_factory=list)
    reviewTypes: List[Any] = Field(default_factory=list)
    cardFields: List[Any] = Field(default_factory=list)
    blocksEdges: List[Any] = Field(default_factory=list)
    acl: List[Any] = Field(default_factory=list)
    subscription: Optional[Any] = None
    permissions: Optional[int] = None
    role: Optional[str] = None
    cursor: Optional[int] = None
