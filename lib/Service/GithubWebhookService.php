<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardLink;
use OCA\Kanso\Db\CardLinkMapper;
use OCA\Kanso\Db\CardMapper;
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
 *
 * Issue intake (#3752, opt-in): when a board configures an intake stack, an
 * `issues`/`opened` delivery for an issue not yet linked anywhere on the board
 * auto-creates a LINK-ONLY card there (title = issue title, the issue attached
 * as a KIND_ISSUE link with state/title cached from the payload - no body copy,
 * no content sync). An optional free-text label filter narrows intake to issues
 * carrying that GitHub label. Creation goes through CardService::create as the
 * board owner, so the sort key, change row and realtime all fire.
 *
 * Egress / visibility rule (#3760): the 200 response body is this service's
 * ONLY outbound payload, and it goes to an EXTERNAL system (GitHub's delivery
 * log, readable by repo admins who need not be board members). It therefore
 * carries ids and booleans only - NEVER card titles or content - and a card id
 * it reports is either an echo of the request's own `kanso-<id>` branch name
 * (PR path) or restricted to PUBLIC cards (issue path: a non-public linked
 * card is processed but not named, so egress can't confirm hidden card ids).
 * Every card mutation runs as the board owner through CardService, whose
 * visibility gates (#3743) apply - a card hidden from the owner is untouched
 * (the no-op response every business-level miss produces).
 */
class GithubWebhookService {
	public function __construct(
		private BoardMapper $boardMapper,
		private StackMapper $stackMapper,
		private CardService $cardService,
		private CardMapper $cardMapper,
		private CardLinkService $cardLinkService,
		private CardLinkMapper $cardLinkMapper,
		private PermissionService $permissionService,
		private CardVisibilityScope $visibilityScope,
		private ISecureRandom $secureRandom,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * The board's webhook config for a MANAGE user: whether it's enabled, the
	 * payload URL to paste into GitHub and the issue-intake settings. The secret
	 * itself is NOT returned here (it is shown once, on rotate).
	 *
	 * @return array{enabled: bool, payloadUrl: string, intakeStackId: int|null, intakeLabel: string}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function getConfig(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		return $this->configArray($board);
	}

	/**
	 * Configures issue intake (#3752): the stack an opened issue's card is
	 * created in, plus an optional GitHub label filter. A null stack turns
	 * intake OFF (and drops the filter). Requires MANAGE.
	 *
	 * @return array{enabled: bool, payloadUrl: string, intakeStackId: int|null, intakeLabel: string}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 * @throws InvalidInputException if the stack is not an alive stack of this board, or the label is too long
	 */
	public function setIntakeConfig(int $boardId, ?int $stackId, string $label, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);

		if ($stackId !== null && $this->findAliveStack($boardId, $stackId) === null) {
			throw new InvalidInputException('Stack does not belong to this board');
		}
		$label = trim($label);
		if (mb_strlen($label) > 100) {
			throw new InvalidInputException('Label filter must not exceed 100 characters');
		}

		$board->setWebhookIntakeStackId($stackId);
		// No stack = intake off; a stale filter must not linger behind it.
		$board->setWebhookIntakeLabel($stackId === null || $label === '' ? null : $label);
		$this->boardMapper->update($board);

		return $this->configArray($board);
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
	 * @return array{handled: bool, action?: string, cardId?: int, moved?: bool, created?: bool}
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
	 * An `opened` issue nobody has linked yet is the intake case (#3752): when
	 * the board configured an intake stack (and the issue passes the optional
	 * label filter), a link-only card is auto-created for it.
	 *
	 * @param array<string, mixed> $issue
	 * @return array{handled: bool, action?: string, cardId?: int, moved?: bool, created?: bool}
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

		$candidates = $this->issueUrlCandidates($owner, $repo, $number);
		$links = $this->cardLinkMapper->findByBoardAndUrls($board->getId(), $candidates);
		if ($links === []) {
			if ($action === 'opened') {
				return $this->intakeIssue($board, $issue, $issueUrl, $candidates, $number);
			}
			return ['handled' => false];
		}

		$this->refreshLinksFromIssue($links, $issue);

		$moved = false;
		$firstCardId = 0;
		foreach ($links as $link) {
			$cardId = $link->getCardId();
			// Egress rule (#3760): the response goes to an external system, so
			// only a PUBLIC card's id may be named in it. Non-public cards are
			// still processed (their moves run as the board owner, gated by the
			// owner's visibility) - they are just never confirmed outward.
			if ($firstCardId === 0 && $this->isPublicCard($cardId)) {
				$firstCardId = $cardId;
			}
			if ($this->applyIssueAutoMove($board->getId(), $cardId, $action, $board->getOwner())) {
				$moved = true;
			}
		}

		return ['handled' => true, 'action' => $action, 'cardId' => $firstCardId, 'moved' => $moved];
	}

	/**
	 * Issue intake (#3752): auto-creates a LINK-ONLY card for a just-opened
	 * issue in the board's configured intake stack. Opt-in and defensive - any
	 * failed precondition degrades to the accepted no-op the webhook always
	 * returns for business-level misses:
	 *
	 *  - no intake stack configured (the default) → off;
	 *  - a configured label filter the issue doesn't carry → filtered out;
	 *  - a stale stack (deleted / moved off this board) → off until re-configured;
	 *  - the issue already linked ANYWHERE on the board - alive, archived or
	 *    trashed card - → dedup, a redelivery never creates a duplicate.
	 *
	 * The dedup is check-then-act (no unique index spans a link URL and the
	 * card's board), so two CONCURRENT deliveries of the same opened event
	 * could in principle both pass it. Accepted residual race: GitHub delivers
	 * redeliveries sequentially in practice, and the worst case is one extra
	 * card a human deletes - not worth a cross-table constraint.
	 *
	 * The card is created via CardService::create as the board owner (sort key
	 * append + change row + realtime), then the issue is attached as a
	 * KIND_ISSUE link with state/title cached straight from the payload. No
	 * body copy, no ongoing content sync - after creation only the issue
	 * auto-move automation applies.
	 *
	 * @param array<string, mixed> $issue
	 * @param string[] $candidates the issue's URL-spelling candidates (dedup key)
	 * @return array{handled: bool, action?: string, cardId?: int, moved?: bool, created?: bool}
	 */
	private function intakeIssue(Board $board, array $issue, string $issueUrl, array $candidates, int $number): array {
		$stackId = $board->getWebhookIntakeStackId();
		if ($stackId === null || $stackId <= 0) {
			return ['handled' => false];
		}
		$labelFilter = trim($board->getWebhookIntakeLabel() ?? '');
		if ($labelFilter !== '' && !$this->issueHasLabel($issue, $labelFilter)) {
			return ['handled' => false];
		}
		$stack = $this->findAliveStack($board->getId(), $stackId);
		if ($stack === null) {
			return ['handled' => false];
		}
		if ($this->cardLinkMapper->existsByBoardAndUrls($board->getId(), $candidates)) {
			return ['handled' => false];
		}

		$title = trim(is_string($issue['title'] ?? null) ? $issue['title'] : '');
		if (mb_strlen($title) > CardService::MAX_TITLE_LENGTH) {
			$title = trim(mb_substr($title, 0, CardService::MAX_TITLE_LENGTH));
		}
		// AFTER truncation, so a degenerate title can never reach create() empty.
		if ($title === '') {
			$title = 'Issue #' . $number;
		}

		try {
			$card = $this->cardService->create($stack->getId(), $title, $board->getOwner());
		} catch (\Throwable) {
			// e.g. a concurrent create storm exhausting the sort-key retry - the
			// delivery is still accepted; GitHub must not see a 5xx and disable
			// the hook.
			return ['handled' => false];
		}

		// Attach the issue as a link with its state/title cached from the payload
		// (authoritative - no poll needed). Best-effort: a failed link insert
		// leaves a plain card rather than failing the delivery.
		$link = new CardLink();
		$link->setCardId($card->getId());
		$link->setUrl($issueUrl);
		$link->setKind(CardLink::KIND_ISSUE);
		$link->setState($this->issueState($issue));
		$link->setTitle(is_string($issue['title'] ?? null) ? $issue['title'] : null);
		$now = time();
		$link->setLastPolled($now);
		$link->setCreatedAt($now);
		try {
			$this->cardLinkMapper->insert($link);
		} catch (\Throwable) {
			// Non-critical - the card exists; the link can be added manually.
		}

		return [
			'handled' => true,
			'action' => 'opened',
			'cardId' => $card->getId(),
			'moved' => false,
			'created' => true,
		];
	}

	/**
	 * Whether the card may be named in the response body - the external-egress
	 * gate (#3760): PUBLIC cards only, per {@see CardVisibilityScope::isPublic()}.
	 * A purged card is simply not public.
	 */
	private function isPublicCard(int $cardId): bool {
		try {
			return $this->visibilityScope->isPublic($this->cardMapper->find($cardId));
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Whether the event payload lists a label of this (case-insensitive) name.
	 * Matched at event time against the delivery's `labels` array only - no
	 * GitHub API call, no stored label state.
	 *
	 * @param array<string, mixed> $issue
	 */
	private function issueHasLabel(array $issue, string $name): bool {
		$labels = $issue['labels'] ?? null;
		if (!is_array($labels)) {
			return false;
		}
		foreach ($labels as $label) {
			if (is_array($label) && is_string($label['name'] ?? null)
				&& mb_strtolower($label['name']) === mb_strtolower($name)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The configured stack, when it still is an alive stack of this board -
	 * null otherwise (deleted, moved, or never valid).
	 */
	private function findAliveStack(int $boardId, int $stackId): ?Stack {
		try {
			$stack = $this->stackMapper->find($stackId);
		} catch (\Throwable) {
			return null;
		}
		if ($stack->getBoardId() !== $boardId || $stack->getDeletedAt() > 0) {
			return null;
		}
		return $stack;
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
		$state = $this->issueState($issue);
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
	 * The payload's issue state mapped onto the CardLink state enum.
	 *
	 * @param array<string, mixed> $issue
	 */
	private function issueState(array $issue): string {
		$rawState = is_string($issue['state'] ?? null) ? $issue['state'] : '';
		return match ($rawState) {
			'open' => CardLink::STATE_OPEN,
			'closed' => CardLink::STATE_CLOSED,
			default => CardLink::STATE_UNKNOWN,
		};
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
	 * The MANAGE-facing config shape shared by getConfig and setIntakeConfig.
	 *
	 * @return array{enabled: bool, payloadUrl: string, intakeStackId: int|null, intakeLabel: string}
	 */
	private function configArray(Board $board): array {
		return [
			'enabled' => ($board->getWebhookSecret() ?? '') !== '',
			'payloadUrl' => $this->payloadUrl($board->getId()),
			'intakeStackId' => $board->getWebhookIntakeStackId(),
			'intakeLabel' => $board->getWebhookIntakeLabel() ?? '',
		];
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
