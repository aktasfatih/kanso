<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One project comment (table `kanso_project_comments`) - an owner-only personal
 * discussion log entry on a project (#3563). A top-level comment has a null
 * `parentCommentId`; a reply carries the id of the top-level comment it answers
 * (one level only - enforced in {@see \OCA\Kanso\Service\ProjectCommentService}).
 *
 * The body is raw markdown - rendered through the same client-side DOMPurify
 * sanitizer as card comments, never trusted as HTML. The author display name is
 * NOT stored; the controller resolves it from the uid at serialize time.
 *
 * @method int getProjectId()
 * @method void setProjectId(int $projectId)
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
 */
class ProjectComment extends Entity implements \JsonSerializable {
	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $projectId = null;
	protected ?int $parentCommentId = null;
	protected ?string $author = null;
	protected ?string $body = null;
	protected ?int $createdAt = null;
	protected ?int $editedAt = null;
	protected ?int $deletedAt = null;

	public function __construct() {
		$this->addType('projectId', Types::INTEGER);
		$this->addType('parentCommentId', Types::INTEGER);
		$this->addType('author', Types::STRING);
		$this->addType('body', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
		$this->addType('editedAt', Types::INTEGER);
		$this->addType('deletedAt', Types::INTEGER);
	}

	/**
	 * @return array{id: int, projectId: ?int, parentCommentId: ?int, author: ?string, body: ?string, createdAt: int, editedAt: int}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'projectId' => $this->projectId,
			'parentCommentId' => $this->parentCommentId,
			'author' => $this->author,
			'body' => $this->body,
			'createdAt' => $this->createdAt ?? 0,
			'editedAt' => $this->editedAt ?? 0,
		];
	}
}
