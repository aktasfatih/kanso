<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One emoji reaction on a card comment (table `kanso_comment_reactions`): a
 * (comment, uid, emoji) triple. The (comment_id, uid, emoji) unique index keeps
 * a user from double-reacting the same emoji, so a react is idempotent.
 *
 * The emoji is one value from the FIXED allowed set validated in
 * {@see \OCA\Kanso\Service\CommentReactionService}.
 *
 * @method int getCommentId()
 * @method void setCommentId(int $commentId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getEmoji()
 * @method void setEmoji(string $emoji)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class CommentReaction extends Entity {
	protected ?int $commentId = null;
	protected ?string $uid = null;
	protected ?string $emoji = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('commentId', Types::INTEGER);
		$this->addType('uid', Types::STRING);
		$this->addType('emoji', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
	}
}
