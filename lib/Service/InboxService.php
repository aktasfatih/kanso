<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeMapper;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\SubscriptionMapper;
use OCP\IUserManager;

/**
 * The Inbox feed - a durable, browsable activity feed of the cards the user
 * follows, distinct from the Nextcloud bell (which is ephemeral targeted pings).
 * It surfaces, newest first and excluding the user's own actions, recent
 * comments AND card-status events (assigned, review requested) on the cards the
 * user watches. Card-status events are read from the `kanso_changes` verb log
 * (#3494) - no new event store. ACL is enforced by restricting to the user's
 * readable board set (mirrors {@see ReviewService::findMine}).
 *
 * Only verbs with a clear, non-noisy typed source are surfaced: a move stamps
 * VERB_MOVED for every drag (no done-transition verb exists), and "due passed"
 * is a time condition that produces no change row - both are deliberately out.
 */
class InboxService {
	/** Most recent items returned in one page of the feed. */
	private const LIMIT = 50;

	/** The card-status verbs surfaced in the feed (assigned, review requested). */
	private const FEED_VERBS = [Change::VERB_ASSIGNED, Change::VERB_REVIEW_REQUESTED];

	public function __construct(
		private BoardService $boardService,
		private SubscriptionMapper $subscriptionMapper,
		private CommentMapper $commentMapper,
		private ChangeMapper $changeMapper,
		private IUserManager $userManager,
		private BoardAccess $boardAccess,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function findMine(string $uid): array {
		// Active boards only (#10126): an archived board is shelved, so nothing
		// on it belongs in this feed.
		$boards = $this->boardService->findAllActive($uid);
		$boardIds = array_map(
			static fn ($board): int => $board->getId(),
			$boards
		);
		if ($boardIds === []) {
			return [];
		}

		$cardIds = $this->subscriptionMapper->findSubscribedCardIds($uid);
		if ($cardIds === []) {
			return [];
		}

		// Visibility (#3743): a followed card that is (or became) hidden for
		// the viewer feeds nothing - the scope runs inside both queries.
		$rolesByBoard = $this->boardAccess->rolesFor($boards, $uid);

		// Comments and card-status changes on the same followed/readable set,
		// each tagged with a type so the frontend can render them differently.
		// Both carry the actor uid under `author` so they merge on one field.
		$comments = $this->commentMapper->findInboxForCards($cardIds, $boardIds, $uid, self::LIMIT, $rolesByBoard);
		foreach ($comments as &$comment) {
			$comment['type'] = 'comment';
		}
		unset($comment);

		$changes = $this->changeMapper->findInboxForCards($cardIds, $boardIds, $uid, self::FEED_VERBS, self::LIMIT, $rolesByBoard);
		foreach ($changes as &$change) {
			$change['type'] = 'change';
		}
		unset($change);

		// Merge, newest first (tie-break by id within the same second), and cap.
		$rows = array_merge($comments, $changes);
		usort($rows, static fn (array $a, array $b): int => ($b['createdAt'] <=> $a['createdAt']) ?: ($b['id'] <=> $a['id']));
		$rows = array_slice($rows, 0, self::LIMIT);

		// Resolve each actor's display name (cached per distinct uid so a chatty
		// thread is one lookup, not one per row).
		$names = [];
		foreach ($rows as &$row) {
			$author = (string)$row['author'];
			if (!array_key_exists($author, $names)) {
				$user = $this->userManager->get($author);
				$names[$author] = $user !== null ? $user->getDisplayName() : $author;
			}
			$row['authorDisplayName'] = $names[$author];
		}
		unset($row);

		return $rows;
	}
}
