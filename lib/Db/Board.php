<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A kanban board (table `kanso_boards`).
 *
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method string|null getColor()
 * @method void setColor(?string $color)
 * @method bool getArchived()
 * @method void setArchived(bool $archived)
 * @method int getLastModified()
 * @method void setLastModified(int $lastModified)
 * @method int getDeletedAt()
 * @method void setDeletedAt(int $deletedAt)
 * @method string|null getWebhookSecret()
 * @method void setWebhookSecret(?string $webhookSecret)
 */
class Board extends Entity implements \JsonSerializable {
	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?string $title = null;
	protected ?string $owner = null;
	protected ?string $color = null;
	protected ?bool $archived = null;
	protected ?int $lastModified = null;
	protected ?int $deletedAt = null;
	// MANAGE-only; deliberately NEVER emitted by jsonSerialize().
	protected ?string $webhookSecret = null;

	public function __construct() {
		$this->addType('title', Types::STRING);
		$this->addType('owner', Types::STRING);
		$this->addType('color', Types::STRING);
		$this->addType('archived', Types::BOOLEAN);
		$this->addType('lastModified', Types::INTEGER);
		$this->addType('deletedAt', Types::INTEGER);
		$this->addType('webhookSecret', Types::STRING);
	}

	/**
	 * @return array{id: int, title: ?string, owner: ?string, color: ?string, archived: bool, lastModified: int}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'title' => $this->title,
			'owner' => $this->owner,
			'color' => $this->color,
			'archived' => $this->archived ?? false,
			'lastModified' => $this->lastModified ?? 0,
		];
	}
}
