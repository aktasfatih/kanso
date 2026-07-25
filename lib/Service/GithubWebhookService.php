<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * Inbound GitHub webhooks (slice 2 of the GitHub integration). A board opts in
 * by generating a per-board secret (MANAGE) and pointing a GitHub `pull_request`
 * webhook at its endpoint. Each delivery is verified by HMAC-SHA256 against that
 * secret — the endpoint is the ONLY unauthenticated write path, so every field
 * is treated as untrusted and the signature is checked in constant time before
 * anything is parsed.
 *
 * Auto-move reuses stack ROLES, not a config surface: a PR opened moves its card
 * to the board's ROLE_REVIEW stack; a PR merged moves it to the ROLE_DONE stack
 * (which stamps it done via the existing move automation). A board with no such
 * stack simply doesn't move — it still records the PR link. The move goes through
 * CardService::move so sort keys, the transaction and the change row all fire.
 */
class GithubWebhookService {
	public function __construct(
		private BoardMapper $boardMapper,
		private StackMapper $stackMapper,
		private CardService $cardService,
		private CardLinkService $cardLinkService,
		private PermissionService $permissionService,
		private ISecureRandom $secureRandom,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * The board's webhook config for a MANAGE user: whether it's enabled and the
	 * payload URL to paste into GitHub. The secret itself is NOT returned here
	 * (it is shown once, on rotate).
	 *
	 * @return array{enabled: bool, payloadUrl: string}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function getConfig(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		return [
			'enabled' => ($board->getWebhookSecret() ?? '') !== '',
			'payloadUrl' => $this->payloadUrl($boardId),
		];
	}

	/**
	 * Generates (or replaces) the board's webhook secret and returns it ONCE,
	 * alongside the payload URL. Requires MANAGE.
	 *
	 * @return array{secret: string, payloadUrl: string}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function rotateSecret(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);

		$secret = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
		$board->setWebhookSecret($secret);
		$this->boardMapper->update($board);

		return ['secret' => $secret, 'payloadUrl' => $this->payloadUrl($boardId)];
	}

	/**
	 * Disables the webhook by clearing the secret. Requires MANAGE. Idempotent.
	 *
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function disable(int $boardId, string $actorUid): void {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		if ($board->getWebhookSecret() !== null) {
			$board->setWebhookSecret(null);
			$this->boardMapper->update($board);
		}
	}

	/**
	 * Verifies and processes an inbound GitHub delivery. Returns a small summary
	 * of what happened (for the 200 response body). NEVER throws for
	 * business-level no-ops (unknown branch, no target stack) — only for a failed
	 * signature check, which the controller maps to 401.
	 *
	 * @param string $signatureHeader the raw `X-Hub-Signature-256` value
	 * @param string $rawBody the exact request body bytes (HMAC is over these)
	 * @return array{handled: bool, action?: string, cardId?: int, moved?: bool}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the signature is missing or invalid
	 */
	public function handleWebhook(int $boardId, string $signatureHeader, string $rawBody): array {
		$board = $this->loadBoard($boardId);
		$this->verifySignature($board, $signatureHeader, $rawBody);

		/** @var array<string, mixed> $payload */
		$payload = json_decode($rawBody, true) ?? [];
		$pr = $payload['pull_request'] ?? null;
		if (!is_array($pr)) {
			// Not a pull_request event (e.g. a ping) — accepted, nothing to do.
			return ['handled' => false];
		}

		$action = is_string($payload['action'] ?? null) ? $payload['action'] : '';
		$branch = '';
		if (isset($pr['head']) && is_array($pr['head']) && is_string($pr['head']['ref'] ?? null)) {
			$branch = $pr['head']['ref'];
		}
		$cardId = $this->cardIdFromBranch($branch);
		if ($cardId === null) {
			return ['handled' => false];
		}

		// The branch names a card — it must live on THIS board and be alive.
		try {
			$card = $this->cardService->find($cardId, $board->getOwner());
		} catch (\Throwable) {
			return ['handled' => false];
		}
		if ($card->getBoardId() !== $boardId) {
			return ['handled' => false];
		}

		// Record the PR as a link on the card (best-effort, idempotent).
		$prUrl = is_string($pr['html_url'] ?? null) ? $pr['html_url'] : '';
		if ($prUrl !== '') {
			try {
				$this->cardLinkService->addLink($cardId, $prUrl, $board->getOwner());
			} catch (\Throwable) {
				// Non-critical — a bad/duplicate URL must not fail the webhook.
			}
		}

		$moved = $this->applyAutoMove($boardId, $cardId, $action, $pr, $board->getOwner());

		return ['handled' => true, 'action' => $action, 'cardId' => $cardId, 'moved' => $moved];
	}

	/**
	 * Moves the card per the PR action, if the board has a matching role-stack.
	 * A merged PR → ROLE_DONE (stamps done); an opened/reopened/ready PR →
	 * ROLE_REVIEW. Returns whether a move actually happened.
	 *
	 * @param array<string, mixed> $pr
	 */
	private function applyAutoMove(int $boardId, int $cardId, string $action, array $pr, string $actorUid): bool {
		$targetRole = null;
		if ($action === 'closed' && ($pr['merged'] ?? false) === true) {
			$targetRole = Stack::ROLE_DONE;
		} elseif (in_array($action, ['opened', 'reopened', 'ready_for_review'], true)) {
			$targetRole = Stack::ROLE_REVIEW;
		}
		if ($targetRole === null) {
			return false;
		}

		$target = $this->stackMapper->findByBoardAndRole($boardId, $targetRole);
		if ($target === null) {
			return false;
		}

		try {
			$this->cardService->move($cardId, $target->getId(), null, $actorUid);
			return true;
		} catch (\Throwable) {
			// e.g. the review gate blocks a merge while reviews are unapproved —
			// the link is still recorded; the move is simply skipped.
			return false;
		}
	}

	/**
	 * Constant-time HMAC-SHA256 verification of `sha256=<hex>` against the
	 * board's secret.
	 *
	 * @throws NotPermittedException if the webhook is disabled or the signature is absent/invalid
	 */
	private function verifySignature(Board $board, string $signatureHeader, string $rawBody): void {
		$secret = $board->getWebhookSecret() ?? '';
		if ($secret === '') {
			throw new NotPermittedException('Webhook is not enabled for this board');
		}
		$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
		if ($signatureHeader === '' || !hash_equals($expected, $signatureHeader)) {
			throw new NotPermittedException('Invalid webhook signature');
		}
	}

	private function cardIdFromBranch(string $branch): ?int {
		if (preg_match('/^kanso-(\d+)(?:-.*)?$/', $branch, $m) === 1) {
			return (int)$m[1];
		}
		return null;
	}

	private function payloadUrl(int $boardId): string {
		return $this->urlGenerator->linkToRouteAbsolute('kanso.webhook.github', ['id' => $boardId]);
	}

	/**
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 */
	private function loadBoard(int $boardId): Board {
		$board = $this->boardMapper->find($boardId);
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board ' . $boardId . ' is deleted');
		}
		return $board;
	}
}
