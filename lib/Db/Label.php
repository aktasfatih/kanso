<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A board-scoped label (table `kanso_labels`).
 *
 * Labels are attached to cards via `kanso_card_labels` rows (see
 * {@see CardLabelMapper}); a label always belongs to exactly one board.
 *
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getColor()
 * @method void setColor(?string $color)
 */
class Label extends Entity implements \JsonSerializable {
	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $boardId = null;
	protected ?string $title = null;
	protected ?string $color = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('title', Types::STRING);
		$this->addType('color', Types::STRING);
	}

	/**
	 * @return array{id: int, boardId: ?int, title: ?string, color: ?string}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'boardId' => $this->boardId,
			'title' => $this->title,
			'color' => $this->color,
		];
	}
}
