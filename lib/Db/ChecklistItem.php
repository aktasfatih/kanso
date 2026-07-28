<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One checklist item - a flat todo line on a card (table
 * `kanso_checklist_items`). Items are ordered inside their card by the
 * fractional `sortKey` string (see \OCA\Kanso\Service\SortKeyService); a
 * reorder is a single-row UPDATE.
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

	public function __construct() {
		$this->addType('cardId', Types::INTEGER);
		$this->addType('title', Types::STRING);
		$this->addType('done', Types::BOOLEAN);
		$this->addType('sortKey', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
	}

	/**
	 * @return array{id: int, cardId: ?int, title: ?string, done: bool, sortKey: ?string}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'cardId' => $this->cardId,
			'title' => $this->title,
			'done' => $this->done ?? false,
			'sortKey' => $this->sortKey,
		];
	}
}
