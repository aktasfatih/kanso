<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Db\Comment;
use OCA\Kanso\Service\CommentService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Card comment (discussion) endpoints. Each serialized comment carries the
 * author's resolved display name (looked up from the uid, not stored).
 */
class CommentController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private CommentService $commentService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $cardId): JSONResponse {
		return $this->respond(function () use ($cardId): JSONResponse {
			$comments = $this->commentService->listForCard($cardId, $this->currentUserId());
			return new JSONResponse($this->serializeAll($comments));
		});
	}

	#[NoAdminRequired]
	public function create(int $cardId, string $body = '', ?int $parentCommentId = null): JSONResponse {
		return $this->respond(function () use ($cardId, $body, $parentCommentId): JSONResponse {
			$comment = $this->commentService->addComment($cardId, $body, $parentCommentId, $this->currentUserId());
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
	 * @param Comment[] $comments
	 * @return array<int, array<string, mixed>>
	 */
	private function serializeAll(array $comments): array {
		$names = [];
		return array_map(function (Comment $comment) use (&$names): array {
			return $this->serialize($comment, $names);
		}, $comments);
	}

	/**
	 * @param array<string, string> $nameCache uid => display name, reused across a list
	 * @return array<string, mixed>
	 */
	private function serialize(Comment $comment, array &$nameCache = []): array {
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
