<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A per-user board folder (table `kanso_board_groups`). FLAT, one-level nav
 * organization owned by a single user (`uid`); `sort` orders the folders in
 * that user's nav. See {@see \OCA\Kanso\Service\BoardGroupService}.
 *
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getSort()
 * @method void setSort(int $sort)
 */
class BoardGroup extends Entity implements \JsonSerializable {
	protected ?string $uid = null;
	protected ?string $name = null;
	protected ?int $sort = null;

	public function __construct() {
		$this->addType('uid', Types::STRING);
		$this->addType('name', Types::STRING);
		$this->addType('sort', Types::INTEGER);
	}

	/**
	 * @return array{id: int, name: string, sort: int}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->getName(),
			'sort' => $this->getSort(),
		];
	}
}
