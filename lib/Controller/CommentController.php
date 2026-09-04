<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Db\Comment;
use OCA\Kanso\Db\CommentReactionMapper;
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
 * author's resolved display name (looked up from the uid, not stored) and its
 * emoji-reaction summary (#3550): per emoji present, the count, whether the
 * current user reacted, and the reactor display names for a tooltip.
 */
class CommentController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private CommentService $commentService,
		private CommentReactionMapper $reactionMapper,
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
	 * Marks the comment's thread resolved. Idempotent - resolving an already
	 * resolved thread returns it unchanged.
	 */
	#[NoAdminRequired]
	public function resolve(int $commentId): JSONResponse {
		return $this->respond(function () use ($commentId): JSONResponse {
			$comment = $this->commentService->setResolved($commentId, true, $this->currentUserId());
			return new JSONResponse($this->serialize($comment));
		});
	}

	/**
	 * Reopens a resolved thread. Idempotent, like {@see self::resolve()}.
	 */
	#[NoAdminRequired]
	public function unresolve(int $commentId): JSONResponse {
		return $this->respond(function () use ($commentId): JSONResponse {
			$comment = $this->commentService->setResolved($commentId, false, $this->currentUserId());
			return new JSONResponse($this->serialize($comment));
		});
	}

	/**
	 * @param Comment[] $comments
	 * @return array<int, array<string, mixed>>
	 */
	private function serializeAll(array $comments): array {
		$names = [];
		$reactions = $this->reactionsByComment($comments);
		return array_map(function (Comment $comment) use (&$names, $reactions): array {
			return $this->serialize($comment, $names, $reactions[$comment->getId()] ?? []);
		}, $comments);
	}

	/**
	 * Aggregate every reaction on the thread into per-comment, per-emoji summary
	 * rows in one query. Resolves reactor display names through a shared cache so
	 * a user shared across many reactions is looked up once. The reactor list is
	 * bounded by construction: at most one reaction per (comment, uid, emoji), and
	 * the emoji set is fixed.
	 *
	 * @param Comment[] $comments
	 * @return array<int, list<array{emoji: string, count: int, mine: bool, reactors: list<string>}>> map of commentId => ordered reaction summaries (insertion order of emoji)
	 */
	private function reactionsByComment(array $comments): array {
		if ($comments === []) {
			return [];
		}
		$me = $this->currentUserId();
		$ids = array_map(static fn (Comment $c): int => $c->getId(), $comments);
		$rows = $this->reactionMapper->findByComments($ids);

		// commentId => emoji => ['count' => int, 'mine' => bool, 'reactors' => uid[]]
		$agg = [];
		foreach ($rows as $row) {
			$cid = $row['commentId'];
			$emoji = $row['emoji'];
			if (!isset($agg[$cid][$emoji])) {
				$agg[$cid][$emoji] = ['count' => 0, 'mine' => false, 'reactors' => []];
			}
			$agg[$cid][$emoji]['count']++;
			$agg[$cid][$emoji]['reactors'][] = $row['uid'];
			if ($row['uid'] === $me) {
				$agg[$cid][$emoji]['mine'] = true;
			}
		}

		$nameCache = [];
		$out = [];
		foreach ($agg as $cid => $byEmoji) {
			$summaries = [];
			foreach ($byEmoji as $emoji => $data) {
				$summaries[] = [
					'emoji' => (string)$emoji,
					'count' => $data['count'],
					'mine' => $data['mine'],
					'reactors' => array_map(
						fn (string $uid): string => $this->displayName($uid, $nameCache),
						$data['reactors'],
					),
				];
			}
			$out[$cid] = $summaries;
		}
		return $out;
	}

	/**
	 * @param array<string, string> $nameCache uid => display name, reused across a list
	 * @param list<array{emoji: string, count: int, mine: bool, reactors: list<string>}> $reactions
	 * @return array<string, mixed>
	 */
	private function serialize(Comment $comment, array &$nameCache = [], array $reactions = []): array {
		return $comment->jsonSerialize()
			+ ['authorDisplayName' => $this->displayName((string)$comment->getAuthor(), $nameCache)]
			+ ['reactions' => $reactions];
	}

	/**
	 * @param array<string, string> $nameCache uid => display name, reused across a list
	 */
	private function displayName(string $uid, array &$nameCache): string {
		if (!isset($nameCache[$uid])) {
			$user = $this->userManager->get($uid);
			$nameCache[$uid] = $user !== null ? $user->getDisplayName() : $uid;
		}
		return $nameCache[$uid];
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
