<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One user's pin on one board (table `kanso_board_pins`). A pin is the per-user
 * curation signal (#3632): it drives BOTH the boards-page "Pinned" section and
 * the left-sidebar nav. A user pins a board at most once (unique on
 * (uid, board_id)); no row = not pinned. `uid` is duplicated here so every pin
 * lookup stays scoped without a join.
 *
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 */
class BoardPin extends Entity {
	protected ?string $uid = null;
	protected ?int $boardId = null;

	public function __construct() {
		$this->addType('uid', Types::STRING);
		$this->addType('boardId', Types::INTEGER);
	}
}
