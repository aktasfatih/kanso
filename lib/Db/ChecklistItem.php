<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCA\Kanso\Access\ViewerContext;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One checklist item - a flat todo line on a card (table
 * `kanso_checklist_items`). Items are ordered inside their card by the
 * fractional `sortKey` string (see \OCA\Kanso\Service\SortKeyService); a
 * reorder is a single-row UPDATE.
 *
 * A checklist item can be a rich "step" (#3745): assigned to one user (with
 * the assignee's board side FROZEN into `assignedRole` at assignment time by
 * {@see \OCA\Kanso\Service\ChecklistService::assignItem()}), carrying its own
 * due date, and stamping `doneAt` when `done` flips. `done` stays the source
 * of truth; `doneAt` is only the timestamp of the flip.
 *
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method bool getDone()
 * @method void setDone(bool $done)
 * @method string getSortKey()
 * @method void setSortKey(string $sortKey)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method ?string getAssignedUser()
 * @method void setAssignedUser(?string $assignedUser)
 * @method ?string getAssignedRole()
 * @method void setAssignedRole(?string $assignedRole)
 * @method ?int getAssignedAt()
 * @method void setAssignedAt(?int $assignedAt)
 * @method ?\DateTime getDueDate()
 * @method void setDueDate(?\DateTime $dueDate)
 * @method ?int getDoneAt()
 * @method void setDoneAt(?int $doneAt)
 */
class ChecklistItem extends Entity implements \JsonSerializable {
	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $cardId = null;
	protected ?string $title = null;
	protected ?bool $done = null;
	protected ?string $sortKey = null;
	protected ?int $createdAt = null;
	protected ?string $assignedUser = null;
	protected ?string $assignedRole = null;
	protected ?int $assignedAt = null;
	protected ?\DateTime $dueDate = null;
	protected ?int $doneAt = null;

	public function __construct() {
		$this->addType('cardId', Types::INTEGER);
		$this->addType('title', Types::STRING);
		$this->addType('done', Types::BOOLEAN);
		$this->addType('sortKey', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
		$this->addType('assignedUser', Types::STRING);
		$this->addType('assignedRole', Types::STRING);
		$this->addType('assignedAt', Types::INTEGER);
		$this->addType('dueDate', Types::DATETIME);
		$this->addType('doneAt', Types::INTEGER);
	}

	/**
	 * Whether this step is open and parked on the client side - the raw
	 * material for the "waiting on client" derivation (epic 6). Reads the
	 * FROZEN role copy, never the live ACL, so the answer stays stable when
	 * the assignee later changes role or leaves the board.
	 */
	public function waitsOnExternal(): bool {
		return !($this->done ?? false)
			&& $this->assignedRole === ViewerContext::ROLE_EXTERNAL;
	}

	/**
	 * @return array{id: int, cardId: ?int, title: ?string, done: bool, sortKey: ?string, assignedUser: ?string, assignedRole: ?string, assignedAt: ?int, dueDate: ?string, doneAt: ?int}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'cardId' => $this->cardId,
			'title' => $this->title,
			'done' => $this->done ?? false,
			'sortKey' => $this->sortKey,
			'assignedUser' => $this->assignedUser,
			'assignedRole' => $this->assignedRole,
			'assignedAt' => $this->assignedAt,
			'dueDate' => $this->dueDate?->format(\DateTimeInterface::ATOM),
			'doneAt' => $this->doneAt,
		];
	}
}
