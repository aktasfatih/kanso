<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A board-watch row (table `kanso_board_subscriptions`). Presence = subscribed;
 * there is no state column because nothing auto-subscribes a user to a board,
 * so no opt-out tombstone is required (unlike {@see Subscription}).
 *
 * @method string getSubscriber()
 * @method void setSubscriber(string $subscriber)
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class BoardSubscription extends Entity {
	protected ?string $subscriber = null;
	protected ?int $boardId = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('subscriber', Types::STRING);
		$this->addType('boardId', Types::INTEGER);
		$this->addType('createdAt', Types::INTEGER);
	}
}
