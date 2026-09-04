<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One card comment (table `kanso_comments`). A top-level comment has a null
 * `parentCommentId`; a reply carries the id of the top-level comment it answers
 * (one level only - enforced in {@see \OCA\Kanso\Service\CommentService}).
 *
 * The body is raw markdown - rendered through the client-side DOMPurify
 * sanitizer, never trusted as HTML. The author display name is NOT stored; the
 * controller resolves it from the uid at serialize time.
 *
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method int|null getParentCommentId()
 * @method void setParentCommentId(?int $parentCommentId)
 * @method string getAuthor()
 * @method void setAuthor(string $author)
 * @method string getBody()
 * @method void setBody(string $body)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getEditedAt()
 * @method void setEditedAt(int $editedAt)
 * @method int getDeletedAt()
 * @method void setDeletedAt(int $deletedAt)
 * @method int getResolvedAt()
 * @method void setResolvedAt(int $resolvedAt)
 */
class Comment extends Entity implements \JsonSerializable {
	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $cardId = null;
	protected ?int $parentCommentId = null;
	protected ?string $author = null;
	protected ?string $body = null;
	protected ?int $createdAt = null;
	protected ?int $editedAt = null;
	protected ?int $deletedAt = null;
	// Non-zero only on a top-level comment: resolving is a thread-level act, so
	// the service refuses to stamp it on a reply.
	protected ?int $resolvedAt = null;

	public function __construct() {
		$this->addType('cardId', Types::INTEGER);
		$this->addType('parentCommentId', Types::INTEGER);
		$this->addType('author', Types::STRING);
		$this->addType('body', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
		$this->addType('editedAt', Types::INTEGER);
		$this->addType('deletedAt', Types::INTEGER);
		$this->addType('resolvedAt', Types::INTEGER);
	}

	/**
	 * @return array{id: int, cardId: ?int, parentCommentId: ?int, author: ?string, body: ?string, createdAt: int, editedAt: int, resolvedAt: int}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'cardId' => $this->cardId,
			'parentCommentId' => $this->parentCommentId,
			'author' => $this->author,
			'body' => $this->body,
			'createdAt' => $this->createdAt ?? 0,
			'editedAt' => $this->editedAt ?? 0,
			// 0 = the thread is open. Clients derive the collapsed rendering from
			// this alone; no collapse state is persisted anywhere.
			'resolvedAt' => $this->resolvedAt ?? 0,
		];
	}
}
