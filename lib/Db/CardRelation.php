<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A relation between two cards on the same board (table `kanso_card_relations`).
 *
 * `blocks` is directional - a row {card_id: A, other_card_id: B} means "A blocks
 * B" (equivalently "B is blocked by A"). `duplicates` and `relates` are symmetric
 * and stored once with card_id < other_card_id (see {@see CardRelation::SYMMETRIC}).
 *
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method int getOtherCardId()
 * @method void setOtherCardId(int $otherCardId)
 * @method string getType()
 * @method void setType(string $type)
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class CardRelation extends Entity implements \JsonSerializable {
	public const TYPE_BLOCKS = 'blocks';
	public const TYPE_DUPLICATES = 'duplicates';
	public const TYPE_RELATES = 'relates';

	/** All valid relation types. */
	public const TYPES = [self::TYPE_BLOCKS, self::TYPE_DUPLICATES, self::TYPE_RELATES];

	/** Types with no direction - stored once in canonical (min, max) order. */
	public const SYMMETRIC = [self::TYPE_DUPLICATES, self::TYPE_RELATES];

	protected ?int $cardId = null;
	protected ?int $otherCardId = null;
	protected ?string $type = null;
	protected ?int $boardId = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('cardId', Types::INTEGER);
		$this->addType('otherCardId', Types::INTEGER);
		$this->addType('type', Types::STRING);
		$this->addType('boardId', Types::INTEGER);
		$this->addType('createdAt', Types::INTEGER);
	}

	public static function isSymmetric(string $type): bool {
		return in_array($type, self::SYMMETRIC, true);
	}

	/**
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'cardId' => $this->getCardId(),
			'otherCardId' => $this->getOtherCardId(),
			'type' => $this->getType(),
			'boardId' => $this->getBoardId(),
			'createdAt' => $this->getCreatedAt() ?? 0,
		];
	}
}
