<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A project (table `kanso_projects`): a cross-board collection of cards. `owner`
 * is the uid; others reach it through `kanso_project_shares`. `color` is a bare
 * hex string (no '#'), `created_at` is unix seconds, `start_date` / `target_date`
 * are optional YYYY-MM-DD calendar dates.
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
 * @method string|null getStartDate()
 * @method void setStartDate(?string $startDate)
 * @method string|null getTargetDate()
 * @method void setTargetDate(?string $targetDate)
 * @method string|null getLead()
 * @method void setLead(?string $lead)
 * @method string|null getLifecycleStatus()
 * @method void setLifecycleStatus(string $lifecycleStatus)
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
	protected ?string $startDate = null;
	protected ?string $targetDate = null;
	protected ?string $lead = null;
	protected ?string $lifecycleStatus = null;

	public function __construct() {
		$this->addType('createdAt', Types::INTEGER);
	}

	/**
	 * @return array{id: int, title: string, description: ?string, color: ?string, owner: string, createdAt: int, startDate: ?string, targetDate: ?string, lead: ?string, lifecycleStatus: string}
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
			'startDate' => $this->getStartDate(),
			'targetDate' => $this->getTargetDate(),
			'lead' => $this->getLead(),
			'lifecycleStatus' => $this->getLifecycleStatus() ?? 'planned',
		];
	}
}
