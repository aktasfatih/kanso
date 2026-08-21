<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\Comment;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

/**
 * Public / read-only board share links (#3531) - Deck's most-requested feature.
 *
 * A board opts in when a MANAGE user mints a per-board `public_share_token`
 * (64-char ISecureRandom, so ~380 bits of entropy - unguessable, and the only
 * thing that addresses the board publicly). An unauthenticated reader who holds
 * the token gets a STRIPPED, read-only snapshot of the board through
 * {@see self::getPublicBoard()}.
 *
 * Security posture (this is the app's only unauthenticated *read* surface):
 *  - OFF by default; enabling/rotating/disabling all require MANAGE.
 *  - The token column is UNIQUE, so a token resolves to EXACTLY one board - it
 *    can never be pivoted to another board.
 *  - Disable clears the token and rotate replaces it, so a stale token stops
 *    resolving on the very next request (revocable + rotatable).
 *  - {@see self::getPublicBoard()} builds its OWN payload from a deliberately
 *    narrow field list. It NEVER reuses the authenticated board payload, and it
 *    NEVER touches assignees, watchers, activity/changes, ACL/members, owner
 *    uids, reviews, or the webhook config. Only board title + stacks + per-card
 *    {title, description, labels, dates, cover colour, estimate, checklist
 *    counts, priority, status, human id} are exposed - nothing that identifies a
 *    person or leaks internal metadata. Archived stacks/cards are omitted.
 *  - The ONE opt-in exception (#3949): a MANAGE user may DELIBERATELY widen the
 *    link with the `public_share_comments` toggle (OFF by default). When ON, and
 *    only then, each public card also carries a read-only comment thread - author
 *    DISPLAY NAME only (never a uid), body, timestamps, one-level parent link;
 *    still no reactions, members or activity. OFF keeps the person-free baseline.
 *  - An unknown/disabled/rotated/expired token raises DoesNotExistException,
 *    which the controller maps to a throttled 404 (no oracle beyond the throttle,
 *    and no distinction between "wrong token" and "disabled board").
 */
class PublicShareService {
	private const TOKEN_LENGTH = 64;

	public function __construct(
		private BoardMapper $boardMapper,
		private StackMapper $stackMapper,
		private CardMapper $cardMapper,
		private CardLabelMapper $cardLabelMapper,
		private ChecklistItemMapper $checklistItemMapper,
		private LabelMapper $labelMapper,
		private CommentMapper $commentMapper,
		private PermissionService $permissionService,
		private ISecureRandom $secureRandom,
		private IURLGenerator $urlGenerator,
		private IUserManager $userManager,
	) {
	}

	/**
	 * The board's public-share config for a MANAGE user: whether it's enabled and
	 * the public URL (present only when enabled). The token itself IS included so
	 * the settings UI can render/copy the live link - it is board content the
	 * MANAGE user already controls, unlike the webhook secret. Requires MANAGE.
	 *
	 * @return array{enabled: bool, token: ?string, url: ?string, expiresAt: ?int, commentsEnabled: bool}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function getConfig(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		return $this->configPayload($board);
	}

	/**
	 * Enables the public link (or rotates an existing one) by minting a fresh
	 * token, and returns the new config incl. the token + URL. Requires MANAGE.
	 * Any previously-issued link stops working immediately.
	 *
	 * @return array{enabled: bool, token: ?string, url: ?string, expiresAt: ?int, commentsEnabled: bool}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function enable(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);

		$board->setPublicShareToken($this->secureRandom->generate(self::TOKEN_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC));
		$this->boardMapper->update($board);

		return $this->configPayload($board);
	}

	/**
	 * Disables the public link by clearing the token. Requires MANAGE. Idempotent
	 * - a board with no link stays that way. The old token 404s from now on.
	 *
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function disable(int $boardId, string $actorUid): void {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		if ($board->getPublicShareToken() !== null) {
			$board->setPublicShareToken(null);
			$board->setPublicShareExpiresAt(null);
			$this->boardMapper->update($board);
		}
	}

	/**
	 * Sets the "show comments (read-only)" opt-in for the public link. Requires
	 * MANAGE. Independent of enable/disable: a MANAGE user can pre-set it, and it
	 * persists across rotate. When ON, {@see self::getPublicBoard()} widens the
	 * anonymous payload to include each public card's read-only comment thread
	 * (author display name only). OFF (the default) keeps the person-free baseline.
	 *
	 * @return array{enabled: bool, token: ?string, url: ?string, expiresAt: ?int, commentsEnabled: bool}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function setComments(int $boardId, bool $enabled, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);

		if (($board->getPublicShareComments() ?? false) !== $enabled) {
			$board->setPublicShareComments($enabled);
			$this->boardMapper->update($board);
		}

		return $this->configPayload($board);
	}

	/**
	 * The STRIPPED, read-only public snapshot of the board a token points at.
	 * This is the ONLY method that runs without a session, so it is deliberately
	 * conservative: it builds its own narrow payload and never reads people,
	 * comments, ACL, reviews, activity or webhook data.
	 *
	 * When the board's `public_share_comments` opt-in is ON (#3949), each card
	 * also carries a `comments` list - a read-only thread of author DISPLAY NAME
	 * (never a uid), body, timestamps and one-level parent link, scoped to the
	 * public card set via {@see CommentMapper::findByBoardPublicOnly()}. No
	 * reactions, no members, no activity. When OFF (default), no `comments` key
	 * is added and the person-free baseline holds.
	 *
	 * @return array{
	 *   board: array{title: ?string, color: ?string, prefix: string, commentsEnabled: bool},
	 *   stacks: list<array{id: int, title: ?string, color: ?string}>,
	 *   cards: list<array{id: int, stackId: ?int, title: ?string, description: ?string, labels: list<array{name: ?string, color: ?string}>, duedate: ?string, coverColor: ?string, startDate: ?string, estimate: ?string, allDay: bool, priority: int, type: string, status: string, humanId: ?string, checklist: array{total: int, done: int}, comments?: list<array{id: int, parentCommentId: ?int, author: string, body: ?string, createdAt: int, editedAt: int}>}>
	 * }
	 * @throws DoesNotExistException if the token is unknown, disabled, or expired
	 */
	public function getPublicBoard(string $token): array {
		$board = $this->boardMapper->findByPublicToken($token);

		// Defence in depth: findByPublicToken already excludes deleted boards and
		// empty tokens, but re-assert the token is really set (never serve a board
		// whose token was concurrently cleared) and honour any expiry.
		if (($board->getPublicShareToken() ?? '') === '') {
			throw new DoesNotExistException('Public share is disabled');
		}
		$expiresAt = $board->getPublicShareExpiresAt();
		if ($expiresAt !== null && $expiresAt > 0 && $expiresAt <= time()) {
			throw new DoesNotExistException('Public share has expired');
		}

		$boardId = (int)$board->getId();

		// Labels by their id, so a card can carry {name, color} only (never the
		// label's numeric id or board id - display data only).
		$labelsById = [];
		foreach ($this->labelMapper->findByBoard($boardId) as $label) {
			/** @var Label $label */
			$labelsById[(int)$label->getId()] = $label;
		}
		// Anonymous viewer: label links and checklist counts over PUBLIC cards
		// only (#3743) - never fetch a hidden card's rows just to discard them.
		$labelIdsByCard = $this->cardLabelMapper->findLabelIdsByBoardPublicOnly($boardId);
		$checklistByCard = $this->checklistItemMapper->progressByBoardPublicOnly($boardId);

		// Comments opt-in (#3949): ONLY fetch (and only ever expose) comments when
		// the MANAGE user deliberately enabled the toggle for this share. Also over
		// PUBLIC cards only, so a hidden card's discussion never surfaces.
		$commentsEnabled = $board->getPublicShareComments() ?? false;
		$commentsByCard = $commentsEnabled ? $this->commentMapper->findByBoardPublicOnly($boardId) : [];
		$displayNames = [];

		// Only NON-archived stacks, in display order; drop the internal board id.
		$stacks = [];
		$liveStackIds = [];
		foreach ($this->stackMapper->findByBoard($boardId) as $stack) {
			/** @var Stack $stack */
			if ($stack->getArchived()) {
				continue;
			}
			$liveStackIds[(int)$stack->getId()] = true;
			$stacks[] = [
				'id' => (int)$stack->getId(),
				'title' => $stack->getTitle(),
				'color' => $stack->getColor(),
			];
		}

		$prefix = $board->jsonSerialize()['prefix'];
		$cards = [];
		foreach ($this->cardMapper->findPublicByBoard($boardId) as $card) {
			/** @var Card $card */
			if ($card->getArchived()) {
				continue;
			}
			// A card whose stack is archived is not shown on a public board either.
			if (!isset($liveStackIds[(int)$card->getStackId()])) {
				continue;
			}

			$cardId = (int)$card->getId();
			$labels = [];
			foreach ($labelIdsByCard[$cardId] ?? [] as $labelId) {
				$label = $labelsById[(int)$labelId] ?? null;
				if ($label !== null) {
					$labels[] = ['name' => $label->getTitle(), 'color' => $label->getColor()];
				}
			}

			$seq = $card->getBoardSeq();
			$cardPayload = [
				'id' => $cardId,
				'stackId' => $card->getStackId(),
				'title' => $card->getTitle(),
				// Descriptions ARE part of the public snapshot per the share model
				// (board content, not a person identifier). Nothing else is added.
				'description' => $card->getDescription(),
				'labels' => $labels,
				'duedate' => $card->getDuedate()?->format(\DateTimeInterface::ATOM),
				// Presentational, non-person card attributes (#3951): a cover colour
				// band, the start date, and the estimate. These are board content, not
				// person identifiers - no assignees/comments/members/activity here.
				'coverColor' => $card->getCoverColor(),
				'startDate' => $card->getStartDate()?->format(\DateTimeInterface::ATOM),
				'estimate' => $card->getEstimate(),
				'allDay' => $card->getAllDay() ?? false,
				'priority' => $card->getPriority() ?? 0,
				'type' => $card->getType() ?? '',
				'status' => ($card->getDoneAt() ?? 0) > 0 ? 'done' : (($card->getStartedAt() ?? 0) > 0 ? 'in_progress' : 'open'),
				'humanId' => $seq !== null ? $prefix . '-' . $seq : null,
				'checklist' => $checklistByCard[$cardId] ?? ['total' => 0, 'done' => 0],
			];

			if ($commentsEnabled) {
				$cardPayload['comments'] = $this->serializeComments($commentsByCard[$cardId] ?? [], $displayNames);
			}

			$cards[] = $cardPayload;
		}

		return [
			'board' => [
				'title' => $board->getTitle(),
				'color' => $board->getColor(),
				'prefix' => $prefix,
				'commentsEnabled' => $commentsEnabled,
			],
			'stacks' => $stacks,
			'cards' => $cards,
		];
	}

	/**
	 * The read-only public serialization of one card's comment thread (#3949).
	 * Emits ONLY {id, parentCommentId, author DISPLAY NAME, body, timestamps} -
	 * deliberately NO uid (the display name is resolved from the uid, like the
	 * authenticated comment endpoint, but the uid itself never leaves), NO
	 * reactions, NO reactor lists. A one-level thread: a reply carries its
	 * top-level parent id and the client nests by it.
	 *
	 * @param Comment[] $comments
	 * @param array<string, string> $displayNames uid => display name cache, reused across cards
	 * @return list<array{id: int, parentCommentId: ?int, author: string, body: ?string, createdAt: int, editedAt: int}>
	 */
	private function serializeComments(array $comments, array &$displayNames): array {
		$out = [];
		foreach ($comments as $comment) {
			$uid = (string)$comment->getAuthor();
			if (!isset($displayNames[$uid])) {
				$user = $this->userManager->get($uid);
				$displayNames[$uid] = $user !== null ? $user->getDisplayName() : $uid;
			}
			$out[] = [
				'id' => (int)$comment->getId(),
				'parentCommentId' => $comment->getParentCommentId(),
				'author' => $displayNames[$uid],
				'body' => $comment->getBody(),
				'createdAt' => $comment->getCreatedAt() ?? 0,
				'editedAt' => $comment->getEditedAt() ?? 0,
			];
		}
		return $out;
	}

	/**
	 * Lightweight token gate for the page renderer: asserts the token resolves to
	 * a live, non-expired share WITHOUT building the whole payload (the client
	 * fetches that separately via {@see self::getPublicBoard()}). Keeps the
	 * unauthenticated page route from doing two full board builds per load.
	 *
	 * @throws DoesNotExistException if the token is unknown, disabled, or expired
	 */
	public function assertTokenValid(string $token): void {
		$board = $this->boardMapper->findByPublicToken($token);
		if (($board->getPublicShareToken() ?? '') === '') {
			throw new DoesNotExistException('Public share is disabled');
		}
		$expiresAt = $board->getPublicShareExpiresAt();
		if ($expiresAt !== null && $expiresAt > 0 && $expiresAt <= time()) {
			throw new DoesNotExistException('Public share has expired');
		}
	}

	/**
	 * @return array{enabled: bool, token: ?string, url: ?string, expiresAt: ?int, commentsEnabled: bool}
	 */
	private function configPayload(Board $board): array {
		$token = $board->getPublicShareToken();
		$enabled = ($token ?? '') !== '';
		return [
			'enabled' => $enabled,
			'token' => $enabled ? $token : null,
			'url' => $enabled ? $this->publicUrl((string)$token) : null,
			'expiresAt' => $board->getPublicShareExpiresAt(),
			// The opt-in state rides the MANAGE config so the settings UI can render
			// the toggle; it persists independent of enable/disable.
			'commentsEnabled' => $board->getPublicShareComments() ?? false,
		];
	}

	private function publicUrl(string $token): string {
		return $this->urlGenerator->linkToRouteAbsolute('kanso.publicShare.show', ['token' => $token]);
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
