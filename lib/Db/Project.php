<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A project (table `kanso_projects`): an owner-only, cross-board collection of
 * cards. `owner` is the uid; there is no sharing in v1. `color` is a bare hex
 * string (no '#'), `created_at` is unix seconds.
 *
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getColor()
 * @method void setColor(?string $color)
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Project extends Entity implements \JsonSerializable {
	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?string $title = null;
	protected ?string $description = null;
	protected ?string $color = null;
	protected ?string $owner = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('createdAt', Types::INTEGER);
	}

	/**
	 * @return array{id: int, title: string, description: ?string, color: ?string, owner: string, createdAt: int}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (int)$this->getId(),
			'title' => (string)$this->getTitle(),
			'description' => $this->getDescription(),
			'color' => $this->getColor(),
			'owner' => (string)$this->getOwner(),
			'createdAt' => (int)$this->getCreatedAt(),
		];
	}
}
