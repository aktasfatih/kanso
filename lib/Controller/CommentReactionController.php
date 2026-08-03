<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\CommentReactionService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Emoji reactions on card comments (#3550). A reaction is toggled with a
 * PUT (react) / DELETE (unreact) on
 * /api/comments/{commentId}/reactions/{emoji}. Both are idempotent and
 * EDIT-gated at the service layer (comment -> card -> board). The reaction
 * SUMMARY per comment is not read here - it rides along in the comment list
 * payload ({@see CommentController}).
 */
class CommentReactionController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private CommentReactionService $reactionService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function react(int $commentId, string $emoji): JSONResponse {
		return $this->respond(function () use ($commentId, $emoji): JSONResponse {
			$this->reactionService->react($commentId, rawurldecode($emoji), $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	#[NoAdminRequired]
	public function unreact(int $commentId, string $emoji): JSONResponse {
		return $this->respond(function () use ($commentId, $emoji): JSONResponse {
			$this->reactionService->unreact($commentId, rawurldecode($emoji), $this->currentUserId());
			return new JSONResponse([]);
		});
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
