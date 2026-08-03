<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * The placement of one board into one folder for one user (table
 * `kanso_board_group_members`). A board is in at most one folder per user
 * (unique on (uid, board_id)); no row = the board is Ungrouped. `uid` is
 * duplicated here so every membership lookup stays scoped without a join.
 *
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method int getGroupId()
 * @method void setGroupId(int $groupId)
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 */
class BoardGroupMember extends Entity {
	protected ?string $uid = null;
	protected ?int $groupId = null;
	protected ?int $boardId = null;

	public function __construct() {
		$this->addType('uid', Types::STRING);
		$this->addType('groupId', Types::INTEGER);
		$this->addType('boardId', Types::INTEGER);
	}
}
