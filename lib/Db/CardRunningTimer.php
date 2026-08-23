<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A single RUNNING timer on a card (table `kanso_card_running_timers`, #73).
 *
 * The manual time-tracking backend ({@see CardTimeEntry}) stores only FINISHED
 * durations; it has no notion of a clock that is currently running. This row is
 * that minimal running-state: while it exists the card's timer is "on", started
 * at `startedAt`. When the timer is stopped the elapsed seconds are written as a
 * finished {@see CardTimeEntry} and this row is deleted, so at most ONE running
 * timer can exist per card (enforced by a UNIQUE index on `card_id`).
 *
 * `boardId` is denormalized from the card at insert time (set server-side) so
 * board-permission gating and the purge cascade don't need a card join.
 *
 * All props default to null: {@see Entity::setter()} skips a set() whose value
 * equals the current property value, so a non-null default (e.g. 0) would
 * silently drop that column from the INSERT and violate NOT NULL.
 *
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method string getStartedBy()
 * @method void setStartedBy(string $startedBy)
 * @method int getStartedAt()
 * @method void setStartedAt(int $startedAt)
 */
class CardRunningTimer extends Entity implements \JsonSerializable {
	protected ?int $cardId = null;
	protected ?int $boardId = null;
	protected ?string $startedBy = null;
	protected ?int $startedAt = null;

	public function __construct() {
		$this->addType('cardId', Types::INTEGER);
		$this->addType('boardId', Types::INTEGER);
		$this->addType('startedBy', Types::STRING);
		$this->addType('startedAt', Types::INTEGER);
	}

	/**
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'cardId' => $this->getCardId(),
			'startedBy' => $this->getStartedBy(),
			'startedAt' => $this->getStartedAt(),
		];
	}
}
