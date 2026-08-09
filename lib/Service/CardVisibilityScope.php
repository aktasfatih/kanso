<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Card;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * THE card-visibility rule (#3741), in one place: appends the
 * public / internal-role-match / private-owner-match WHERE to any
 * QueryBuilder selecting from `kanso_cards`.
 *
 *   visible(card, viewer) =
 *        card.visibility = 'public'
 *     OR (card.visibility = 'internal' AND card.creator_role = role(viewer, card.board))
 *     OR (card.visibility = 'private'  AND card.owner = viewer)
 *
 * 'internal' is symmetric: the provider side sees provider-internal cards,
 * the client side sees client-internal cards - and there is deliberately
 * NO owner/admin exception (the owner's role resolves like anyone else's,
 * see {@see BoardAccess}).
 *
 * Resolve-then-bind: the viewer's per-board effective role arrives as the
 * {@see BoardAccess::rolesFor()} map and is BOUND into the query - the
 * scope never JOINs `kanso_board_acl` itself. Kanso's ACL can hold several
 * rows per (user, board) via groups, so a naive role JOIN would both
 * duplicate card rows and risk matching 'internal' through the wrong row;
 * binding the already-folded role keeps one row per card and the fold rule
 * in one place. Cross-board mode stays ONE query: the internal branch
 * becomes per-side `board_id IN (...)` lists over the role map.
 *
 * Membership itself is NOT this scope's job: callers gate on membership
 * first (assertPermission / contextFor) - a board absent from the role map
 * simply contributes no internal-branch match.
 *
 * An architecture test pins the set of classes allowed to query
 * `kanso_cards` so this rule cannot silently fork. Wiring the existing
 * read paths through here is epic 3 (#3743).
 */
class CardVisibilityScope {
	public const VISIBILITY_PUBLIC = 'public';
	public const VISIBILITY_INTERNAL = 'internal';
	public const VISIBILITY_PRIVATE = 'private';

	public const VISIBILITIES = [
		self::VISIBILITY_PUBLIC,
		self::VISIBILITY_INTERNAL,
		self::VISIBILITY_PRIVATE,
	];

	/**
	 * Appends the visibility WHERE (and, board-scoped, the board filter) to
	 * a query the caller has already pointed at the cards table via a
	 * from() with $cardAlias or an equivalent JOIN.
	 *
	 * @param string $cardAlias alias of the kanso_cards table in the query
	 * @param string $userId the viewer (matched against `owner` for 'private')
	 * @param int|null $boardId board-scoped mode; null = cross-board (My
	 *                          Cards, Inbox): the rule applies per board with the role that
	 *                          holds THERE, still in one query
	 * @param array<int, string> $rolesByBoard the viewer's effective role per
	 *                                         board id, from {@see BoardAccess::rolesFor()} (or one
	 *                                         contextFor() for board-scoped callers) - never raw ACL rows
	 */
	public function apply(IQueryBuilder $qb, string $cardAlias, string $userId, ?int $boardId, array $rolesByBoard): void {
		$visibility = fn (string $value) => $qb->expr()->eq(
			$this->column($cardAlias, 'visibility'),
			$qb->createNamedParameter($value),
		);

		$branches = [$visibility(self::VISIBILITY_PUBLIC)];

		$roleMatch = $boardId !== null
			? $this->boardRoleMatch($qb, $cardAlias, $rolesByBoard[$boardId] ?? null)
			: $this->crossBoardRoleMatch($qb, $cardAlias, $rolesByBoard);
		if ($roleMatch !== null) {
			$branches[] = $qb->expr()->andX($visibility(self::VISIBILITY_INTERNAL), $roleMatch);
		}

		$branches[] = $qb->expr()->andX(
			$visibility(self::VISIBILITY_PRIVATE),
			$qb->expr()->eq($this->column($cardAlias, 'owner'), $qb->createNamedParameter($userId)),
		);

		$qb->andWhere($qb->expr()->orX(...$branches));

		if ($boardId !== null) {
			$qb->andWhere($qb->expr()->eq(
				$this->column($cardAlias, 'board_id'),
				$qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT),
			));
		}
	}

	/**
	 * Board-scoped convenience over {@see self::apply()} for callers holding a
	 * resolved {@see ViewerContext} - the (uid, boardId, role) triple IS the
	 * proof of membership, so the role map is derived instead of hand-built.
	 */
	public function applyForViewer(IQueryBuilder $qb, string $cardAlias, ViewerContext $viewer): void {
		$this->apply($qb, $cardAlias, $viewer->userId, $viewer->boardId, [$viewer->boardId => $viewer->role]);
	}

	/**
	 * The anonymous-viewer mode (#3743): public cards ONLY, no internal or
	 * private branch ever - for the token-gated surfaces (public board share,
	 * ICS feed) where there is no session and therefore no role to match.
	 * Deliberately its own method (not apply() with a magic uid) so an
	 * anonymous read can never accidentally grow a role branch.
	 */
	public function applyPublicOnly(IQueryBuilder $qb, string $cardAlias): void {
		$qb->andWhere($qb->expr()->eq(
			$this->column($cardAlias, 'visibility'),
			$qb->createNamedParameter(self::VISIBILITY_PUBLIC),
		));
	}

	/**
	 * The SAME rule as {@see self::apply()}, evaluated in PHP against one
	 * already-loaded card - the single-card gate behind every card-id-addressed
	 * endpoint ({@see CardVisibilityGuard}). Kept HERE so the visibility
	 * decision cannot fork: SQL branches and this evaluator read off one class.
	 *
	 * @param string|null $role the viewer's resolved role on the card's board
	 *                          ({@see \OCA\Kanso\Access\BoardAccess::contextFor()}), or null when the
	 *                          viewer has no membership there - which drops the internal branch,
	 *                          exactly like the SQL side
	 */
	public function isVisibleTo(Card $card, string $userId, ?string $role): bool {
		// Rows predating the visibility migration read as 'public' (the
		// migration backfill value) - default open, matching today's behavior.
		$visibility = $card->getVisibility() ?? self::VISIBILITY_PUBLIC;

		if ($visibility === self::VISIBILITY_PUBLIC) {
			return true;
		}
		if ($visibility === self::VISIBILITY_PRIVATE) {
			return $card->getOwner() === $userId;
		}
		if ($visibility === self::VISIBILITY_INTERNAL) {
			// A null creator_role reads as 'internal', matching the backfill
			// (same fold as BoardAccess for pre-hydration ACL rows).
			$creatorSide = $card->getCreatorRole() ?? ViewerContext::ROLE_INTERNAL;
			return $role !== null && in_array($role, ViewerContext::ROLES, true) && $creatorSide === $role;
		}
		// An unknown stored value fails CLOSED - never "sees everything".
		return false;
	}

	/**
	 * Column reference under the caller's alias; '' addresses an un-aliased
	 * single-table query (the aggregate counts).
	 */
	private function column(string $cardAlias, string $name): string {
		return $cardAlias === '' ? $name : $cardAlias . '.' . $name;
	}

	/**
	 * Board-scoped internal branch: the frozen creator side must equal the
	 * viewer's resolved role on THIS board. Null (viewer has no resolved
	 * role here) drops the branch - no internal cards, never a wildcard.
	 */
	private function boardRoleMatch(IQueryBuilder $qb, string $cardAlias, ?string $role): ?string {
		if (!in_array($role, ViewerContext::ROLES, true)) {
			return null;
		}
		return $qb->expr()->eq(
			$this->column($cardAlias, 'creator_role'),
			$qb->createNamedParameter($role),
		);
	}

	/**
	 * Cross-board internal branch: per-side `board_id IN (...)` lists over
	 * the role map, so the role that holds on EACH board applies - one
	 * query, one row per card, no ACL join.
	 *
	 * @param array<int, string> $rolesByBoard
	 */
	private function crossBoardRoleMatch(IQueryBuilder $qb, string $cardAlias, array $rolesByBoard): string|ICompositeExpression|null {
		$boardsBySide = [
			ViewerContext::ROLE_INTERNAL => [],
			ViewerContext::ROLE_EXTERNAL => [],
		];
		foreach ($rolesByBoard as $roleBoardId => $role) {
			if (isset($boardsBySide[$role])) {
				$boardsBySide[$role][] = (int)$roleBoardId;
			}
		}

		$sides = [];
		foreach ($boardsBySide as $side => $boardIds) {
			if ($boardIds === []) {
				continue;
			}
			$sides[] = $qb->expr()->andX(
				$qb->expr()->in(
					$this->column($cardAlias, 'board_id'),
					$qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY),
				),
				$qb->expr()->eq(
					$this->column($cardAlias, 'creator_role'),
					$qb->createNamedParameter($side),
				),
			);
		}

		if ($sides === []) {
			return null;
		}
		return count($sides) === 1 ? $sides[0] : $qb->expr()->orX(...$sides);
	}
}
