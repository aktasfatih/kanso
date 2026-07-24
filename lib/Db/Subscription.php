<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A watcher row (table `kanso_subscriptions`). `commentThreadId` 0 = the whole
 * card; otherwise a specific thread. `state` is subscribed (0) or an explicit
 * opt-out tombstone (1) that suppresses auto-subscribe.
 *
 * @method string getSubscriber()
 * @method void setSubscriber(string $subscriber)
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method int getCommentThreadId()
 * @method void setCommentThreadId(int $commentThreadId)
 * @method int getState()
 * @method void setState(int $state)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Subscription extends Entity {
	public const STATE_SUBSCRIBED = 0;
	public const STATE_OPTED_OUT = 1;

	protected ?string $subscriber = null;
	protected ?int $cardId = null;
	protected ?int $commentThreadId = null;
	protected ?int $state = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('subscriber', Types::STRING);
		$this->addType('cardId', Types::INTEGER);
		$this->addType('commentThreadId', Types::INTEGER);
		$this->addType('state', Types::INTEGER);
		$this->addType('createdAt', Types::INTEGER);
	}
}
