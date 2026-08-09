<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardLink;
use OCA\Kanso\Db\CardLinkMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * Inbound GitHub webhooks (slice 2 of the GitHub integration). A board opts in
 * by generating a per-board secret (MANAGE) and pointing a GitHub webhook
 * sending `pull_request` and `issues` events at its endpoint. Each delivery is
 * verified by HMAC-SHA256 against that secret - the endpoint is the ONLY
 * unauthenticated write path, so every field is treated as untrusted and the
 * signature is checked in constant time before anything is parsed.
 *
 * Auto-move reuses stack ROLES, not a config surface: a PR opened moves its card
 * (matched by its `kanso-<id>` branch) to the board's ROLE_REVIEW stack; a PR
 * merged moves it to the ROLE_DONE stack (which stamps it done via the existing
 * move automation). An issue has no branch, so its cards are matched in reverse:
 * alive cards on this board with that issue URL attached as a link. An issue
 * closed moves them to ROLE_DONE; reopened moves them back to ROLE_IN_PROGRESS
 * (or ROLE_TODO if the board has no in-progress stack). A board with no matching
 * role-stack simply doesn't move - the link/state is still recorded. Every move
 * goes through CardService::move so sort keys, the transaction and the change
 * row all fire.
 */
class GithubWebhookService {
	public function __construct(
		private BoardMapper $boardMapper,
		private StackMapper $stackMapper,
		private CardService $cardService,
		private CardLinkService $cardLinkService,
		private CardLinkMapper $cardLinkMapper,
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
	 * business-level no-ops (unknown branch, no target stack) - only for a failed
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

		$payload = json_decode($rawBody, true);
		if (!is_array($payload)) {
			// A bare JSON scalar / null / malformed body - accepted, nothing to do.
			return ['handled' => false];
		}
		$action = is_string($payload['action'] ?? null) ? $payload['action'] : '';

		$pr = $payload['pull_request'] ?? null;
		if (is_array($pr)) {
			return $this->handlePullRequestEvent($board, $action, $pr);
		}
		$issue = $payload['issue'] ?? null;
		if (is_array($issue)) {
			return $this->handleIssueEvent($board, $action, $issue);
		}
		// Not a pull_request/issues event (e.g. a ping) - accepted, nothing to do.
		return ['handled' => false];
	}

	/**
	 * A `pull_request` event: the card is named by the PR's `kanso-<id>` head
	 * branch; the PR is recorded as a link and the card auto-moved per action.
	 *
	 * @param array<string, mixed> $pr
	 * @return array{handled: bool, action?: string, cardId?: int, moved?: bool}
	 */
	private function handlePullRequestEvent(Board $board, string $action, array $pr): array {
		$boardId = $board->getId();
		$branch = '';
		if (isset($pr['head']) && is_array($pr['head']) && is_string($pr['head']['ref'] ?? null)) {
			$branch = $pr['head']['ref'];
		}
		$cardId = $this->cardIdFromBranch($branch);
		if ($cardId === null) {
			return ['handled' => false];
		}

		// The branch names a card - it must live on THIS board and be alive.
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
				// Non-critical - a bad/duplicate URL must not fail the webhook.
			}
		}

		$moved = $this->applyAutoMove($boardId, $cardId, $action, $pr, $board->getOwner());

		return ['handled' => true, 'action' => $action, 'cardId' => $cardId, 'moved' => $moved];
	}

	/**
	 * An `issues` event: an issue has no branch, so its cards are matched in
	 * reverse - alive cards on this board with the issue's URL attached as a
	 * link. The link's cached state/title is refreshed from the payload (fresher
	 * and cheaper than a read-time poll), then each card is auto-moved per
	 * action. An `issue_comment` delivery on a PR also carries an `issue` object
	 * (whose html_url is a /pull/ URL) - that is not a Kanso issue link, so it
	 * falls out at the KIND_ISSUE check.
	 *
	 * @param array<string, mixed> $issue
	 * @return array{handled: bool, action?: string, cardId?: int, moved?: bool}
	 */
	private function handleIssueEvent(Board $board, string $action, array $issue): array {
		$issueUrl = is_string($issue['html_url'] ?? null) ? $issue['html_url'] : '';
		if ($issueUrl === '') {
			return ['handled' => false];
		}
		try {
			[$kind, $owner, $repo, $number] = CardLinkService::parseGitHubUrl($issueUrl);
		} catch (InvalidInputException) {
			return ['handled' => false];
		}
		if ($kind !== CardLink::KIND_ISSUE) {
			return ['handled' => false];
		}

		$links = $this->cardLinkMapper->findByBoardAndUrls(
			$board->getId(),
			$this->issueUrlCandidates($owner, $repo, $number),
		);
		if ($links === []) {
			return ['handled' => false];
		}

		$this->refreshLinksFromIssue($links, $issue);

		$moved = false;
		$firstCardId = 0;
		foreach ($links as $link) {
			$cardId = $link->getCardId();
			if ($firstCardId === 0) {
				$firstCardId = $cardId;
			}
			if ($this->applyIssueAutoMove($board->getId(), $cardId, $action, $board->getOwner())) {
				$moved = true;
			}
		}

		return ['handled' => true, 'action' => $action, 'cardId' => $firstCardId, 'moved' => $moved];
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
			// e.g. the review gate blocks a merge while reviews are unapproved -
			// the link is still recorded; the move is simply skipped.
			return false;
		}
	}

	/**
	 * Moves a card per the issue action, if the board has a matching role-stack.
	 * Closed → ROLE_DONE (stamps done); reopened → ROLE_IN_PROGRESS, falling
	 * back to ROLE_TODO when the board has no in-progress stack. Purely
	 * role-based - a board without the role stack simply doesn't move, exactly
	 * like the PR path. Returns whether a move actually happened.
	 */
	private function applyIssueAutoMove(int $boardId, int $cardId, string $action, string $actorUid): bool {
		$roles = [];
		if ($action === 'closed') {
			$roles = [Stack::ROLE_DONE];
		} elseif ($action === 'reopened') {
			$roles = [Stack::ROLE_IN_PROGRESS, Stack::ROLE_TODO];
		}

		$target = null;
		foreach ($roles as $role) {
			$target = $this->stackMapper->findByBoardAndRole($boardId, $role);
			if ($target !== null) {
				break;
			}
		}
		if ($target === null) {
			return false;
		}

		try {
			$this->cardService->move($cardId, $target->getId(), null, $actorUid);
			return true;
		} catch (\Throwable) {
			// Best-effort, like the PR path - the state refresh already happened.
			return false;
		}
	}

	/**
	 * Refreshes the matched links' cached state/title straight from the webhook
	 * payload - authoritative and fresher than any poll - and stamps last_polled
	 * so the read-time throttle doesn't immediately re-poll. Best-effort: a
	 * failed row update must not fail the delivery.
	 *
	 * @param CardLink[] $links
	 * @param array<string, mixed> $issue
	 */
	private function refreshLinksFromIssue(array $links, array $issue): void {
		$rawState = is_string($issue['state'] ?? null) ? $issue['state'] : '';
		$state = match ($rawState) {
			'open' => CardLink::STATE_OPEN,
			'closed' => CardLink::STATE_CLOSED,
			default => CardLink::STATE_UNKNOWN,
		};
		$title = is_string($issue['title'] ?? null) ? $issue['title'] : null;
		$now = time();

		foreach ($links as $link) {
			$link->setState($state);
			if ($title !== null) {
				$link->setTitle($title);
			}
			$link->setLastPolled($now);
			try {
				$this->cardLinkMapper->update($link);
			} catch (\Throwable) {
				// Non-critical - the next read-time poll will catch up.
			}
		}
	}

	/**
	 * The URL spellings under which a github.com issue may have been attached
	 * as a link (host www or not, trailing slash or not). Matching by candidate
	 * set keeps the reverse lookup a plain indexed `url IN (...)` while still
	 * being repo + issue-number based.
	 *
	 * @return string[]
	 */
	private function issueUrlCandidates(string $owner, string $repo, int $number): array {
		$path = '/' . $owner . '/' . $repo . '/issues/' . $number;
		return [
			'https://github.com' . $path,
			'https://github.com' . $path . '/',
			'https://www.github.com' . $path,
			'https://www.github.com' . $path . '/',
		];
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
