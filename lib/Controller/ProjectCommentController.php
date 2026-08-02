<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Db\ProjectComment;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ProjectCommentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Project comment (discussion) endpoints - owner-only (#3563). Each serialized
 * comment carries the author's resolved display name (looked up from the uid,
 * not stored). Ownership is enforced in {@see ProjectCommentService}; a
 * non-owner gets a 403.
 */
class ProjectCommentController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private ProjectCommentService $commentService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $projectId): JSONResponse {
		return $this->respond(function () use ($projectId): JSONResponse {
			$comments = $this->commentService->listForProject($projectId, $this->currentUserId());
			return new JSONResponse($this->serializeAll($comments));
		});
	}

	#[NoAdminRequired]
	public function create(int $projectId, string $body = '', ?int $parentCommentId = null): JSONResponse {
		return $this->respond(function () use ($projectId, $body, $parentCommentId): JSONResponse {
			$comment = $this->commentService->addComment($projectId, $body, $parentCommentId, $this->currentUserId());
			return new JSONResponse($this->serialize($comment));
		});
	}

	#[NoAdminRequired]
	public function update(int $commentId, string $body = ''): JSONResponse {
		return $this->respond(function () use ($commentId, $body): JSONResponse {
			$comment = $this->commentService->editComment($commentId, $body, $this->currentUserId());
			return new JSONResponse($this->serialize($comment));
		});
	}

	#[NoAdminRequired]
	public function destroy(int $commentId): JSONResponse {
		return $this->respond(function () use ($commentId): JSONResponse {
			$this->commentService->deleteComment($commentId, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	/**
	 * @param ProjectComment[] $comments
	 * @return array<int, array<string, mixed>>
	 */
	private function serializeAll(array $comments): array {
		$names = [];
		return array_map(function (ProjectComment $comment) use (&$names): array {
			return $this->serialize($comment, $names);
		}, $comments);
	}

	/**
	 * @param array<string, string> $nameCache uid => display name, reused across a list
	 * @return array<string, mixed>
	 */
	private function serialize(ProjectComment $comment, array &$nameCache = []): array {
		$uid = (string)$comment->getAuthor();
		if (!isset($nameCache[$uid])) {
			$user = $this->userManager->get($uid);
			$nameCache[$uid] = $user !== null ? $user->getDisplayName() : $uid;
		}
		return $comment->jsonSerialize() + ['authorDisplayName' => $nameCache[$uid]];
	}

	/**
	 * @throws NotPermittedException if there is no user session
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new NotPermittedException('No authenticated user');
		}
		return $user->getUID();
	}
}
