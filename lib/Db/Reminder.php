<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One personal, one-shot "remind me" on a card (table `kanso_reminders`),
 * #3816. PER-USER and private: a reminder belongs to the `user_id` that set it
 * and is never visible to anyone else on the card. `comment_id` is nullable -
 * a reminder set from the overflow menu is about the card as a whole, one set
 * from a comment carries that comment id so the deep link can point at it.
 *
 * `remind_at` is the unix instant the notification is owed; `fired_at` is the
 * unix instant it fired (null while pending). Firing stamps `fired_at` so the
 * 15-minute {@see \OCA\Kanso\Cron\SendPersonalReminders} sweep delivers each
 * reminder exactly once and catches up any overdue backlog on the next run.
 *
 * NOT a recurrence/snooze engine: there is no repeat rule, no reschedule chain
 * - a fired reminder is simply done. Card recurrence already exists and is a
 * separate feature.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method int|null getCommentId()
 * @method void setCommentId(?int $commentId)
 * @method int getRemindAt()
 * @method void setRemindAt(int $remindAt)
 * @method int|null getFiredAt()
 * @method void setFiredAt(?int $firedAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Reminder extends Entity implements \JsonSerializable {
	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?string $userId = null;
	protected ?int $cardId = null;
	protected ?int $commentId = null;
	protected ?int $remindAt = null;
	protected ?int $firedAt = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('userId', Types::STRING);
		$this->addType('cardId', Types::INTEGER);
		$this->addType('commentId', Types::INTEGER);
		$this->addType('remindAt', Types::INTEGER);
		$this->addType('firedAt', Types::INTEGER);
		$this->addType('createdAt', Types::INTEGER);
	}

	/**
	 * @return array{id: int, userId: string, cardId: int, commentId: ?int, remindAt: int, firedAt: ?int, createdAt: int}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (int)$this->getId(),
			'userId' => (string)$this->getUserId(),
			'cardId' => (int)$this->getCardId(),
			'commentId' => $this->getCommentId() !== null ? (int)$this->getCommentId() : null,
			'remindAt' => (int)$this->getRemindAt(),
			'firedAt' => $this->getFiredAt() !== null ? (int)$this->getFiredAt() : null,
			'createdAt' => (int)$this->getCreatedAt(),
		];
	}
}
