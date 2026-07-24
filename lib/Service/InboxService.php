<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\SubscriptionMapper;
use OCP\IUserManager;

/**
 * The Inbox feed — a durable, browsable activity feed of the cards the user
 * follows, distinct from the Nextcloud bell (which is ephemeral targeted pings).
 * v1 surfaces recent comments on the cards the user watches (subscribed at the
 * card level), newest first, excluding their own. ACL is enforced by
 * restricting to the user's readable board set (mirrors {@see ReviewService::findMine}).
 */
class InboxService {
	/** Most recent items returned in one page of the feed. */
	private const LIMIT = 50;

	public function __construct(
		private BoardService $boardService,
		private SubscriptionMapper $subscriptionMapper,
		private CommentMapper $commentMapper,
		private IUserManager $userManager,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function findMine(string $uid): array {
		$boardIds = array_map(
			static fn ($board): int => $board->getId(),
			$this->boardService->findAll($uid)
		);
		if ($boardIds === []) {
			return [];
		}

		$cardIds = $this->subscriptionMapper->findSubscribedCardIds($uid);
		if ($cardIds === []) {
			return [];
		}

		$rows = $this->commentMapper->findInboxForCards($cardIds, $boardIds, $uid, self::LIMIT);

		// Resolve each comment author's display name (cached per distinct uid so
		// a chatty thread is one lookup, not one per comment).
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
