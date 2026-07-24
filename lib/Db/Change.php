<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One row of the per-board change log (table `kanso_changes`).
 *
 * The log powers delta sync: clients poll with their last seen change id
 * (`?since=<changeId>`) and receive only newer rows.
 *
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int getEntityType()
 * @method void setEntityType(int $entityType)
 * @method int getEntityId()
 * @method void setEntityId(int $entityId)
 * @method int getAction()
 * @method void setAction(int $action)
 * @method string|null getActor()
 * @method void setActor(?string $actor)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Change extends Entity {
	public const ENTITY_BOARD = 0;
	public const ENTITY_STACK = 1;
	public const ENTITY_CARD = 2;
	public const ENTITY_LABEL = 3;
	public const ENTITY_ACL = 4;
	public const ENTITY_REVIEW_TYPE = 5;

	public const ACTION_CREATE = 0;
	public const ACTION_UPDATE = 1;
	public const ACTION_DELETE = 2;
	public const ACTION_MOVE = 3;

	// Properties default to null (not to 0 / a constant): Entity::setter()
	// skips values equal to the current one, so e.g. a default of
	// ACTION_CREATE would silently drop `action` from INSERTs of create
	// changes and violate the NOT NULL constraint.
	protected ?int $boardId = null;
	protected ?int $entityType = null;
	protected ?int $entityId = null;
	protected ?int $action = null;
	protected ?string $actor = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('entityType', Types::INTEGER);
		$this->addType('entityId', Types::INTEGER);
		$this->addType('action', Types::INTEGER);
		$this->addType('actor', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
	}
}
