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
 */
class Card extends Entity {
	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $boardId = null;
	protected ?int $stackId = null;
	protected ?string $title = null;
	protected ?string $description = null;
	protected ?string $sortKey = null;
	protected ?\DateTime $duedate = null;
	protected ?int $doneAt = null;
	protected ?bool $archived = null;
	protected ?string $owner = null;
	protected ?int $createdAt = null;
	protected ?int $lastModified = null;
	protected ?int $deletedAt = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('stackId', Types::INTEGER);
		$this->addType('title', Types::STRING);
		$this->addType('description', Types::STRING);
		$this->addType('sortKey', Types::STRING);
		$this->addType('duedate', Types::DATETIME);
		$this->addType('doneAt', Types::INTEGER);
		$this->addType('archived', Types::BOOLEAN);
		$this->addType('owner', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
		$this->addType('lastModified', Types::INTEGER);
		$this->addType('deletedAt', Types::INTEGER);
	}
}
