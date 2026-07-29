<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One card's membership in a project (table `kanso_project_cards`). A FLAT
 * join row - (project_id, card_id) is unique. No ordering or metadata: a
 * project's card list derives its order from board/sort at query time.
 *
 * @method int getProjectId()
 * @method void setProjectId(int $projectId)
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 */
class ProjectCard extends Entity implements \JsonSerializable {
	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $projectId = null;
	protected ?int $cardId = null;

	public function __construct() {
		$this->addType('projectId', Types::INTEGER);
		$this->addType('cardId', Types::INTEGER);
	}

	/**
	 * @return array{id: int, projectId: int, cardId: int}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (int)$this->getId(),
			'projectId' => (int)$this->getProjectId(),
			'cardId' => (int)$this->getCardId(),
		];
	}
}
