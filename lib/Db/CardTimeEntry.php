<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A manual time entry on a card (table `kanso_card_time_entries`, #3536). Each
 * row records a duration (in SECONDS) the actor logged against the card, with
 * an optional free-text note; the per-card total is the SUM of these rows.
 *
 * This is MANUAL logging only - there is deliberately NO running-timer state
 * (no started_at/stopped_at/is_running): a stopwatch in the client just POSTs a
 * finished duration. `boardId` is denormalized from the card at insert time
 * (set server-side, never client-supplied) so board-permission gating and the
 * purge cascade don't need a card join.
 *
 * All props default to null: {@see Entity::setter()} skips a set() whose value
 * equals the current property value, so a non-null default (e.g. 0) would
 * silently drop that column from the INSERT and violate NOT NULL.
 *
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int getSeconds()
 * @method void setSeconds(int $seconds)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class CardTimeEntry extends Entity implements \JsonSerializable {
	protected ?int $cardId = null;
	protected ?int $boardId = null;
	protected ?int $seconds = null;
	protected ?string $note = null;
	protected ?string $createdBy = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('cardId', Types::INTEGER);
		$this->addType('boardId', Types::INTEGER);
		$this->addType('seconds', Types::INTEGER);
		$this->addType('note', Types::STRING);
		$this->addType('createdBy', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
	}

	/**
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'cardId' => $this->getCardId(),
			'seconds' => $this->getSeconds(),
			'note' => $this->getNote(),
			'createdBy' => $this->getCreatedBy(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
