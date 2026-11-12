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
 * @method int|null getBoardId()
 * @method void setBoardId(?int $id)
 * @method string|null getPrefix()
 * @method void setPrefix(?string $prefix)
 * @method int|null getBoardSeq()
 * @method void setBoardSeq(?int $seq)
 */
class CardMoveAlias extends Entity {
	protected ?int $oldCardId = null;
	protected ?int $targetCardId = null;
	protected ?int $boardId = null;
	protected ?string $prefix = null;
	protected ?int $boardSeq = null;

	public function __construct() {
		$this->addType('oldCardId', Types::INTEGER);
		$this->addType('targetCardId', Types::INTEGER);
		$this->addType('boardId', Types::INTEGER);
		$this->addType('prefix', Types::STRING);
		$this->addType('boardSeq', Types::INTEGER);
	}
}
