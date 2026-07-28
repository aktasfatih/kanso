<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A column on a board (table `kanso_stacks`).
 *
 * Stacks are ordered by their fractional `sortKey` string (see
 * \OCA\Kanso\Service\SortKeyService), so a reorder is a single-row UPDATE.
 *
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getSortKey()
 * @method void setSortKey(string $sortKey)
 * @method bool getArchived()
 * @method void setArchived(bool $archived)
 * @method int getRole()
 * @method void setRole(int $role)
 * @method int|null getWipLimit()
 * @method void setWipLimit(?int $wipLimit)
 * @method string|null getColor()
 * @method void setColor(?string $color)
 * @method int getDeletedAt()
 * @method void setDeletedAt(int $deletedAt)
 */
class Stack extends Entity implements \JsonSerializable {
	// Workflow roles: a stack's function in the board pipeline. ROLE_DONE is
	// the one the automation reacts to (moving a card into a done-role stack
	// stamps done_at); the others are advisory metadata for the client.
	public const ROLE_NONE = 0;
	public const ROLE_BACKLOG = 1;
	public const ROLE_TODO = 2;
	public const ROLE_IN_PROGRESS = 3;
	public const ROLE_REVIEW = 4;
	public const ROLE_DONE = 5;

	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $boardId = null;
	protected ?string $title = null;
	protected ?string $sortKey = null;
	protected ?bool $archived = null;
	protected ?int $role = null;
	protected ?int $wipLimit = null;
	protected ?string $color = null;
	protected ?int $deletedAt = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('title', Types::STRING);
		$this->addType('sortKey', Types::STRING);
		$this->addType('archived', Types::BOOLEAN);
		$this->addType('role', Types::INTEGER);
		$this->addType('wipLimit', Types::INTEGER);
		$this->addType('color', Types::STRING);
		$this->addType('deletedAt', Types::INTEGER);
	}

	/**
	 * @return array{id: int, boardId: ?int, title: ?string, sortKey: ?string, archived: bool, role: int, wipLimit: ?int, color: ?string}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'boardId' => $this->boardId,
			'title' => $this->title,
			'sortKey' => $this->sortKey,
			'archived' => $this->archived ?? false,
			'role' => $this->role ?? self::ROLE_NONE,
			'wipLimit' => $this->wipLimit,
			'color' => $this->color,
		];
	}
}
