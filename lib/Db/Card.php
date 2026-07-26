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
 * carry the description (it stays null) — only CardMapper::find() loads it.
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
 * @method bool getArchived()
 * @method void setArchived(bool $archived)
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
 */
class Card extends Entity implements \JsonSerializable {
	public const PRIORITY_NONE = 0;
	public const PRIORITY_URGENT = 4;

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
	protected ?bool $archived = null;
	protected ?string $owner = null;
	protected ?int $createdAt = null;
	protected ?int $lastModified = null;
	protected ?int $deletedAt = null;
	protected ?int $parentCardId = null;
	protected ?int $priority = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('stackId', Types::INTEGER);
		$this->addType('title', Types::STRING);
		$this->addType('description', Types::STRING);
		$this->addType('sortKey', Types::STRING);
		$this->addType('duedate', Types::DATETIME);
		$this->addType('startDate', Types::DATETIME);
		$this->addType('doneAt', Types::INTEGER);
		$this->addType('archived', Types::BOOLEAN);
		$this->addType('owner', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
		$this->addType('lastModified', Types::INTEGER);
		$this->addType('deletedAt', Types::INTEGER);
		$this->addType('parentCardId', Types::INTEGER);
		$this->addType('priority', Types::INTEGER);
	}

	/**
	 * Summary payload for board/stack listings — deliberately without the
	 * description (the charter's summary-payload performance bet).
	 *
	 * @return array{id: int, boardId: ?int, stackId: ?int, title: ?string, sortKey: ?string, duedate: ?string, startDate: ?string, doneAt: int, archived: bool, owner: ?string, createdAt: int, lastModified: int, parentCardId: ?int, priority: int}
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
			'archived' => $this->archived ?? false,
			'owner' => $this->owner,
			'createdAt' => $this->createdAt ?? 0,
			'lastModified' => $this->lastModified ?? 0,
			'parentCardId' => $this->parentCardId,
			'priority' => $this->priority ?? 0,
		];
	}

	/**
	 * Full payload including the description — only meaningful for entities
	 * hydrated by {@see CardMapper::find()} (summary queries leave the
	 * description null).
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return $this->jsonSerializeSummary() + ['description' => $this->description];
	}
}
