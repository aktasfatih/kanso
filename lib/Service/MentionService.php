<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\SubscriptionMapper;

/**
 * `@username` mentions in comments and card descriptions. Parsing the raw body
 * for mentions and acting on them is SERVER-side and the trust boundary: a
 * mention only notifies/subscribes a user who can already READ the board, so a
 * mention of a non-member (or an unknown uid) is inert - no notification, no
 * subscription, no card leak. The client separately renders `@username` as a
 * display chip (see src/services/markdown.js); that render is cosmetic and
 * cannot cause a side effect because the authoritative extraction runs here.
 *
 * Plain `@username` tokens only (no mention-entity store). The accepted token
 * charset matches what the composer autocomplete inserts - a board
 * participant's uid - for the common case; unusual uids (e.g. email-shaped)
 * simply won't match and are inert rather than mis-notified.
 */
class MentionService {
	/**
	 * A `@` that is not preceded by a word char or another `@` (so `foo@bar`
	 * email-style text does not match), followed by a conservative uid charset.
	 */
	private const MENTION_PATTERN = '/(?<![\w@])@([a-zA-Z0-9_.-]+)/';

	/**
	 * How many distinct mentions one body may act on. Each one that survives
	 * dedup costs an uncached ACL resolution
	 * ({@see PermissionService::getPermissions()}), so without a bound the
	 * mention count - not the number of board members - sets the query count for
	 * a single write. A 10,000-char comment - already at CommentService's own cap
	 * - still holds a couple of thousand distinct `@u1`-style tokens, so this
	 * bound is load-bearing on the comment path in its own right, not only for
	 * the (separately length-capped) card description.
	 * Fifty is far above any real "cc the room" body. Mentions past the bound are
	 * ignored rather than rejected, matching how a mention of a non-member is
	 * already silently inert.
	 */
	public const MAX_MENTIONS = 50;

	public function __construct(
		private PermissionService $permissionService,
		private SubscriptionService $subscriptionService,
		private NotificationService $notificationService,
		private CardVisibilityGuard $visibilityGuard,
	) {
	}

	/**
	 * The unique candidate usernames mentioned in a raw body, in first-seen
	 * order, capped at {@see self::MAX_MENTIONS}. Pure - no permission check, no
	 * side effects. The cap lives HERE, at the single extraction point, so both
	 * mention surfaces (comment bodies and card descriptions) are bounded by one
	 * guard.
	 *
	 * @return string[]
	 */
	public function extractUsernames(string $body): array {
		if (!preg_match_all(self::MENTION_PATTERN, $body, $matches)) {
			return [];
		}
		return array_slice(array_values(array_unique($matches[1])), 0, self::MAX_MENTIONS);
	}

	/**
	 * For every mention in $body that resolves to a board participant holding
	 * READ *who can SEE the card* (and is not the actor themselves),
	 * auto-subscribe them to the card (respecting an opt-out tombstone) and
	 * send a "mentioned you" notification. Mentions of non-members / unknown
	 * uids are silently inert - and so is a mention of a user OUTSIDE the
	 * card's visibility (#3760): notifying them would hand them an existence
	 * oracle (a bell entry + a watch row) for a card they cannot open. The
	 * visibility check is one batched role resolution
	 * ({@see CardVisibilityGuard::filterVisible()}), not per-mention queries.
	 *
	 * At most {@see self::MAX_MENTIONS} distinct mentions are acted on; the rest
	 * are ignored, so one body can never turn into an unbounded run of ACL
	 * lookups.
	 */
	public function handleMentions(Card $card, Board $board, string $body, string $actorUid): void {
		$readable = [];
		foreach ($this->extractUsernames($body) as $uid) {
			if ($uid === $actorUid) {
				// You don't notify or (re-)subscribe yourself by mentioning you.
				continue;
			}
			if (($this->permissionService->getPermissions($board, $uid) & PermissionService::PERMISSION_READ) === 0) {
				// Not a readable-board participant - inert (no notify, no leak).
				continue;
			}
			$readable[] = $uid;
		}

		foreach ($this->visibilityGuard->filterVisible($board, $card, $readable) as $uid) {
			$this->subscriptionService->autoSubscribe($card->getId(), SubscriptionMapper::THREAD_CARD, $uid);
			$this->notificationService->notifyCardMentioned($card->getId(), $uid, $actorUid);
		}
	}
}
