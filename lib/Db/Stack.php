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
 * @method int getDeletedAt()
 * @method void setDeletedAt(int $deletedAt)
 */
class Stack extends Entity {
	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $boardId = null;
	protected ?string $title = null;
	protected ?string $sortKey = null;
	protected ?bool $archived = null;
	protected ?int $deletedAt = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('title', Types::STRING);
		$this->addType('sortKey', Types::STRING);
		$this->addType('archived', Types::BOOLEAN);
		$this->addType('deletedAt', Types::INTEGER);
	}
}
