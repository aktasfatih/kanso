<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\IDBConnection;

/**
 * Mapper for `kanso_changes`, the per-board delta-sync change log.
 *
 * @template-extends QBMapper<Change>
 */
class ChangeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_changes', Change::class);
	}

	/**
	 * Append one entry to a board's change log.
	 *
	 * @param int $entityType one of the Change::ENTITY_* constants
	 * @param int $action one of the Change::ACTION_* constants
	 * @param string|null $actor uid of the acting user, null for system actions
	 * @param int $createdAt unix timestamp of the change
	 * @return Change the inserted entry with its id set
	 * @throws Exception
	 */
	public function insertChange(int $boardId, int $entityType, int $entityId, int $action, ?string $actor, int $createdAt): Change {
		$change = new Change();
		$change->setBoardId($boardId);
		$change->setEntityType($entityType);
		$change->setEntityId($entityId);
		$change->setAction($action);
		$change->setActor($actor);
		$change->setCreatedAt($createdAt);

		return $this->insert($change);
	}
}
