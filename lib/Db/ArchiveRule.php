<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * An auto-archive rule (table `kanso_archive_rules`): archive done cards on a
 * board - or in a single stack - once they cross an age threshold.
 *
 * `stackId` null means the rule covers the whole board; otherwise it is
 * scoped to that one stack. `condition` selects which age the threshold
 * applies to (see the CONDITION_* constants).
 *
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int|null getStackId()
 * @method void setStackId(?int $stackId)
 * @method int getCondition()
 * @method void setCondition(int $condition)
 * @method int getThresholdSeconds()
 * @method void setThresholdSeconds(int $thresholdSeconds)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class ArchiveRule extends Entity implements \JsonSerializable {
	/** Archive once the card has been marked done for `thresholdSeconds`. */
	public const CONDITION_DONE_FOR = 0;
	/** Archive done cards once they are `thresholdSeconds` old (creation time). */
	public const CONDITION_DONE_AND_AGE = 1;

	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $boardId = null;
	protected ?int $stackId = null;
	protected ?int $condition = null;
	protected ?int $thresholdSeconds = null;
	protected ?bool $enabled = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('boardId', Types::INTEGER);
		$this->addType('stackId', Types::INTEGER);
		$this->addType('condition', Types::INTEGER);
		$this->addType('thresholdSeconds', Types::INTEGER);
		$this->addType('enabled', Types::BOOLEAN);
		$this->addType('createdAt', Types::INTEGER);
	}

	/**
	 * @return array{id: int, boardId: ?int, stackId: ?int, condition: int, thresholdSeconds: int, enabled: bool, createdAt: int}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'boardId' => $this->boardId,
			'stackId' => $this->stackId,
			'condition' => $this->condition ?? self::CONDITION_DONE_FOR,
			'thresholdSeconds' => $this->thresholdSeconds ?? 0,
			'enabled' => $this->enabled ?? false,
			'createdAt' => $this->createdAt ?? 0,
		];
	}
}
