<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getOldCardId()
 * @method void setOldCardId(int $id)
 * @method int getTargetCardId()
 * @method void setTargetCardId(int $id)
 */
class CardMoveAlias extends Entity {
	protected ?int $oldCardId = null;
	protected ?int $targetCardId = null;

	public function __construct() {
		$this->addType('oldCardId', Types::INTEGER);
		$this->addType('targetCardId', Types::INTEGER);
	}
}
