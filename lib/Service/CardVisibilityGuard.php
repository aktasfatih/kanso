<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\NotAMemberException;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The single-card visibility gate (#3743): every endpoint addressed by a CARD
 * ID - reads AND writes - calls {@see self::assertVisible()} right after its
 * board-permission assert, so a card the actor cannot SEE behaves exactly like
 * a card that does not exist. A hidden card must be unmutable and unprobeable:
 * the failure is a {@see DoesNotExistException} (→ 404 via ApiErrorTrait),
 * never a 403 - a 403 would confirm the id exists (an existence oracle).
 *
 * The DECISION lives in {@see CardVisibilityScope::isVisibleTo()} (the same
 * class the list queries take their WHERE from); this guard only resolves the
 * viewer's role via {@see BoardAccess} and maps "not visible" to "not found".
 * Ordering matters: callers assert board membership FIRST (assertPermission →
 * 403 for non-members, unchanged), then visibility (404 for members who may
 * not see this particular card).
 */
class CardVisibilityGuard {
	public function __construct(
		private BoardAccess $boardAccess,
		private CardVisibilityScope $visibilityScope,
	) {
	}

	/**
	 * @param Board $board the card's own board, already loaded by the caller
	 * @throws DoesNotExistException when the card is hidden from the viewer -
	 *                               deliberately the same exception a missing card id raises
	 */
	public function assertVisible(Board $board, Card $card, string $uid): void {
		if (!$this->isVisible($board, $card, $uid)) {
			throw new DoesNotExistException('Card ' . $card->getId() . ' does not exist');
		}
	}

	/**
	 * Whether the viewer may see the card at all. Board membership is NOT
	 * checked here (callers gate on it first); a viewer without a resolvable
	 * membership simply has no role, which drops the internal branch.
	 */
	public function isVisible(Board $board, Card $card, string $uid): bool {
		return $this->visibilityScope->isVisibleTo($card, $uid, $this->roleOn($board, $uid));
	}

	/**
	 * The audience filter for the background fan-outs (#3760): of the given
	 * candidate recipients, the ones who can actually SEE the card - the set
	 * a reminder / comment / mention / activity emission about the card may
	 * reach. A recipient outside the card's visibility gets NO artifact (a
	 * bell entry would be an existence oracle for a hidden card).
	 *
	 * Batched: one {@see BoardAccess::rolesOn()} resolution for the whole
	 * candidate set - never a per-recipient ACL query (these run in cron
	 * over whole boards). Public cards short-circuit without touching the
	 * ACL at all (every board audience may see them). Order is preserved,
	 * duplicates are dropped.
	 *
	 * @param Board $board the card's own board, already loaded by the caller
	 * @param string[] $uids candidate recipients
	 * @return list<string> the recipients the card is visible to
	 */
	public function filterVisible(Board $board, Card $card, array $uids): array {
		$uids = array_values(array_unique($uids));
		if ($uids === [] || $this->visibilityScope->isPublic($card)) {
			return $uids;
		}
		$roles = $this->boardAccess->rolesOn($board, $uids);
		return array_values(array_filter(
			$uids,
			fn (string $uid): bool => $this->visibilityScope->isVisibleTo($card, $uid, $roles[$uid] ?? null),
		));
	}

	/**
	 * The viewer's resolved side on the board, or null for a non-member -
	 * the exact input {@see CardVisibilityScope::isVisibleTo()} expects.
	 */
	public function roleOn(Board $board, string $uid): ?string {
		try {
			return $this->boardAccess->contextFor($board, $uid)->role;
		} catch (NotAMemberException) {
			return null;
		}
	}
}
