<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A card (table `kanso_cards`).
 *
 * Cards are ordered inside a stack by their fractional `sortKey` string (see
 * \OCA\Kanso\Service\SortKeyService); a move is a single-row UPDATE.
 *
 * Note: entities hydrated by the summary queries in {@see CardMapper} do not
 * carry the description (it stays null) - only CardMapper::find() loads it.
 *
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int getStackId()
 * @method void setStackId(int $stackId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getSortKey()
 * @method void setSortKey(string $sortKey)
 * @method \DateTime|null getDuedate()
 * @method void setDuedate(?\DateTime $duedate)
 * @method \DateTime|null getStartDate()
 * @method void setStartDate(?\DateTime $startDate)
 * @method int getDoneAt()
 * @method void setDoneAt(int $doneAt)
 * @method int getStartedAt()
 * @method void setStartedAt(int $startedAt)
 * @method bool getArchived()
 * @method void setArchived(bool $archived)
 * @method bool|null getAllDay()
 * @method void setAllDay(?bool $allDay)
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getLastModified()
 * @method void setLastModified(int $lastModified)
 * @method int getDeletedAt()
 * @method void setDeletedAt(int $deletedAt)
 * @method int|null getParentCardId()
 * @method void setParentCardId(?int $parentCardId)
 * @method int getPriority()
 * @method void setPriority(int $priority)
 * @method string|null getEstimate()
 * @method void setEstimate(?string $estimate)
 * @method int|null getBoardSeq()
 * @method void setBoardSeq(?int $boardSeq)
 * @method int getDueReminderSent()
 * @method void setDueReminderSent(int $dueReminderSent)
 * @method int getDayBeforeReminderSent()
 * @method void setDayBeforeReminderSent(int $dayBeforeReminderSent)
 * @method bool|null getDueReminderDayBefore()
 * @method void setDueReminderDayBefore(?bool $dueReminderDayBefore)
 * @method string|null getCoverColor()
 * @method void setCoverColor(?string $coverColor)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method bool getIsTemplate()
 * @method void setIsTemplate(bool $isTemplate)
 * @method string|null getVisibility()
 * @method void setVisibility(string $visibility)
 * @method string|null getCreatorRole()
 * @method void setCreatorRole(string $creatorRole)
 * @method int|null getDescriptionRevision()
 * @method void setDescriptionRevision(int $descriptionRevision)
 */
class Card extends Entity implements \JsonSerializable {
	public const PRIORITY_NONE = 0;
	public const PRIORITY_URGENT = 4;

	// Card type (#3402): a fixed, built-in "issue type" - exactly one per card,
	// icon-first, lighter than a label. '' is the implicit "none"; the built-in
	// set is fixed (no custom-type editor). Validated in CardService::update().
	public const TYPE_NONE = '';
	public const TYPES = ['bug', 'feature', 'task', 'chore'];

	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $boardId = null;
	protected ?int $stackId = null;
	protected ?string $title = null;
	protected ?string $description = null;
	protected ?string $sortKey = null;
	protected ?\DateTime $duedate = null;
	protected ?\DateTime $startDate = null;
	protected ?int $doneAt = null;
	protected ?int $startedAt = null;
	protected ?bool $archived = null;
	protected ?bool $allDay = null;
	protected ?string $owner = null;
	protected ?int $createdAt = null;
	protected ?int $lastModified = null;
	protected ?int $deletedAt = null;
	protected ?int $parentCardId = null;
	protected ?int $priority = null;
	protected ?string $estimate = null;
	// The board-scoped sequence number - the numeric half of the card's
	// human-readable id (prefix + '-' + board_seq). Assigned once on create and
	// immutable thereafter; a DISPLAY/reference id only (ordering stays sortKey).
	protected ?int $boardSeq = null;
	// Due-date reminder markers (#3545): the unix ts each fixed reminder fired,
	// 0 = not yet. Reset to 0 when the due date changes so a moved date re-arms.
	protected ?int $dueReminderSent = null;
	protected ?int $dayBeforeReminderSent = null;
	// Fixed, card-level opt-in for the "1 day before" reminder (the at-due one
	// always fires). Not a board setting, not a per-user preference.
	protected ?bool $dueReminderDayBefore = null;
	// Card cover colour (#3549): a bare 6-hex string (no leading '#', same
	// convention as label/stack/board colours) rendered as a band on the tile,
	// or null for no cover. In the summary payload so the tile renders it.
	protected ?string $coverColor = null;
	// Card type (#3402): one of Card::TYPES or '' for none. In the summary
	// payload so the board/stack tile renders the type icon without the detail.
	protected ?string $type = null;
	// Per-board card template flag (#3409): a flagged card is a reusable content
	// blueprint for its own board, EXCLUDED from the live board render (the board
	// card query filters it out) and offered in a small picker. In the summary
	// payload so the template picker (which reads summaries) can read the flag.
	protected ?bool $isTemplate = null;
	// Card visibility (#3741): 'public' | 'internal' | 'private' - the
	// constants live in \OCA\Kanso\Service\CardVisibilityScope, NOT here (the
	// scope owns the rule). `creatorRole` is the creator's board side
	// ('internal'/'external' per \OCA\Kanso\Access\ViewerContext), FROZEN at
	// create - never recomputed on role changes, so 'internal' cards keep
	// their side. `owner` doubles as the 'private' match. Deliberately NOT
	// serialized yet: the payloads gain visibility when the read paths adopt
	// the scope (epic 3, #3743).
	protected ?string $visibility = null;
	protected ?string $creatorRole = null;
	// Description revision (#9848): a per-card counter bumped by every write
	// that CHANGES the description - the real optimistic-concurrency token for
	// the description editor. `lastModified` could not do this job: it has
	// second resolution and also moves for edits that never touched the
	// description. Deliberately NOT in CardMapper::SUMMARY_COLUMNS - it is only
	// meaningful next to the description, which the summary payload does not
	// carry either - so a summary-hydrated entity leaves it null (hence the
	// `?? 0` at every read). For the same reason setDescriptionRevision() must
	// only ever be called on an entity loaded by CardMapper::find().
	protected ?int $descriptionRevision = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('stackId', Types::INTEGER);
		$this->addType('title', Types::STRING);
		$this->addType('description', Types::STRING);
		$this->addType('sortKey', Types::STRING);
		$this->addType('duedate', Types::DATETIME);
		$this->addType('startDate', Types::DATETIME);
		$this->addType('doneAt', Types::INTEGER);
		$this->addType('startedAt', Types::INTEGER);
		$this->addType('archived', Types::BOOLEAN);
		$this->addType('allDay', Types::BOOLEAN);
		$this->addType('owner', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
		$this->addType('lastModified', Types::INTEGER);
		$this->addType('deletedAt', Types::INTEGER);
		$this->addType('parentCardId', Types::INTEGER);
		$this->addType('priority', Types::INTEGER);
		$this->addType('estimate', Types::STRING);
		$this->addType('boardSeq', Types::INTEGER);
		$this->addType('dueReminderSent', Types::INTEGER);
		$this->addType('dayBeforeReminderSent', Types::INTEGER);
		$this->addType('dueReminderDayBefore', Types::BOOLEAN);
		$this->addType('coverColor', Types::STRING);
		$this->addType('type', Types::STRING);
		$this->addType('isTemplate', Types::BOOLEAN);
		$this->addType('visibility', Types::STRING);
		$this->addType('creatorRole', Types::STRING);
		$this->addType('descriptionRevision', Types::INTEGER);
	}

	/**
	 * Summary payload for board/stack listings - deliberately without the
	 * description (the charter's summary-payload performance bet).
	 *
	 * @return array{id: int, boardId: ?int, stackId: ?int, title: ?string, sortKey: ?string, duedate: ?string, startDate: ?string, doneAt: int, startedAt: int, archived: bool, allDay: bool, owner: ?string, createdAt: int, lastModified: int, parentCardId: ?int, priority: int, estimate: ?string, boardSeq: ?int, dueReminderDayBefore: bool, coverColor: ?string, type: string, isTemplate: bool, visibility: string}
	 */
	public function jsonSerializeSummary(): array {
		return [
			'id' => $this->getId(),
			'boardId' => $this->boardId,
			'stackId' => $this->stackId,
			'title' => $this->title,
			'sortKey' => $this->sortKey,
			'duedate' => $this->duedate?->format(\DateTimeInterface::ATOM),
			'startDate' => $this->startDate?->format(\DateTimeInterface::ATOM),
			'doneAt' => $this->doneAt ?? 0,
			'startedAt' => $this->startedAt ?? 0,
			'archived' => $this->archived ?? false,
			'allDay' => $this->allDay ?? false,
			'owner' => $this->owner,
			'createdAt' => $this->createdAt ?? 0,
			'lastModified' => $this->lastModified ?? 0,
			'parentCardId' => $this->parentCardId,
			'priority' => $this->priority ?? 0,
			'estimate' => $this->estimate,
			// The numeric half of the human id; the board supplies the prefix.
			// Null only for a card not yet backfilled (pre-migration rows).
			'boardSeq' => $this->boardSeq,
			// The "1 day before" due reminder opt-in (the at-due reminder is
			// always on when a card has a due date). The sent-markers are
			// internal bookkeeping and deliberately not exposed.
			'dueReminderDayBefore' => $this->dueReminderDayBefore ?? false,
			// Card cover colour (#3549): bare 6-hex or null. In the summary so the
			// board/stack tile can render the cover band without the detail fetch.
			'coverColor' => $this->coverColor,
			// Card type (#3402): one of Card::TYPES or '' for none. In the summary
			// so the tile renders the type icon without the detail fetch.
			'type' => $this->type ?? self::TYPE_NONE,
			// Per-board template flag (#3409). Templates are excluded from the live
			// board render; the flag is surfaced so the template picker and the
			// card detail can tell a template apart from a normal card.
			'isTemplate' => $this->isTemplate ?? false,
			// Card visibility (#3741/#3743): 'public' | 'internal' | 'private'.
			// Every payload row has already passed the visibility scope, so
			// exposing the level leaks nothing - the UI needs it for the badge
			// and the picker. `creatorRole` stays server-side (the scope
			// filters on it; a visible internal card always matches the
			// viewer's own side, so the value would be redundant anyway).
			'visibility' => $this->visibility ?? 'public',
		];
	}

	/**
	 * Full payload including the description - only meaningful for entities
	 * hydrated by {@see CardMapper::find()} (summary queries leave the
	 * description null).
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return $this->jsonSerializeSummary() + [
			'description' => $this->description,
			// The description's optimistic-concurrency token (#9848). Detail-only,
			// exactly like the description it guards - a summary row carries
			// neither. The editor seeds its base version from this and sends it
			// back as `baseDescriptionRevision` on save.
			'descriptionRevision' => $this->descriptionRevision ?? 0,
		];
	}
}
