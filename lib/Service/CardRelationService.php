<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardRelation;
use OCA\Kanso\Db\CardRelationMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeDetailMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Card-to-card relations (#3404): blocks / blocked-by / duplicates / relates,
 * same-board only in v1. A flat, fixed set of relation kinds - no custom
 * relation-type config, no cross-board links, no dependency-graph view (the
 * charter trap boundary).
 *
 * Storage keeps three DB types. `blocks` is directional; the API's user-facing
 * `blocked_by` kind is just a `blocks` row with the two cards swapped.
 * `duplicates`/`relates` are symmetric and stored once in canonical
 * (min-id, max-id) order so the pair is never double-recorded.
 */
class CardRelationService {
	// User-facing relation kinds accepted by the API (a superset of the stored types).
	public const KIND_BLOCKS = 'blocks';
	public const KIND_BLOCKED_BY = 'blocked_by';
	public const KIND_DUPLICATES = 'duplicates';
	public const KIND_RELATES = 'relates';
	private const KINDS = [self::KIND_BLOCKS, self::KIND_BLOCKED_BY, self::KIND_DUPLICATES, self::KIND_RELATES];

	/**
	 * How a relation reads in the Activity feed, per SIDE of the stored row: the
	 * source card of a `blocks` row blocks, the target is blocked by. English by
	 * design, like the other server-recorded history labels (CardService's
	 * STATUS_LABELS) - the feed shows what was recorded, when it was recorded.
	 *
	 * @var array<string, array{0: string, 1: string}> stored type → [source-side label, target-side label]
	 */
	private const RELATION_LABELS = [
		CardRelation::TYPE_BLOCKS => ['Blocks', 'Blocked by'],
		CardRelation::TYPE_DUPLICATES => ['Duplicates', 'Duplicates'],
		CardRelation::TYPE_RELATES => ['Relates to', 'Relates to'],
	];

	/**
	 * Cap on a change-detail string, matching the other services that write the
	 * side table (CardService, CardAttachmentService, LabelService).
	 */
	private const MAX_DETAIL_LENGTH = 10000;

	public function __construct(
		private CardRelationMapper $relationMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private PermissionService $permissionService,
		private ChangeNotifier $changeNotifier,
		private CardVisibilityGuard $visibilityGuard,
		private CardVisibilityScope $visibilityScope,
		private ChangeDetailMapper $changeDetailMapper,
	) {
	}

	/**
	 * A card's relations, grouped for the UI. READ-gated.
	 *
	 * @return array{blocks: list<array<string,mixed>>, blockedBy: list<array<string,mixed>>, duplicates: list<array<string,mixed>>, relates: list<array<string,mixed>>}
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not read the board
	 */
	public function relationsForCard(int $cardId, string $uid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);
		$this->visibilityGuard->assertVisible($board, $card, $uid);

		return $this->groupedForCard($cardId, $board, $uid);
	}

	/**
	 * Grouped relations WITHOUT a permission check - for callers that have
	 * already gated READ on the card (e.g. the card-detail payload).
	 *
	 * Visibility (#3743): a counterpart the viewer cannot see keeps its ROW
	 * (the relation itself is board-visible and stays removable) but its
	 * content is MASKED - `hidden: true`, no id, no title, no done state -
	 * so the panel can render "1 hidden linked card" without leaking.
	 *
	 * @return array{blocks: list<array<string,mixed>>, blockedBy: list<array<string,mixed>>, duplicates: list<array<string,mixed>>, relates: list<array<string,mixed>>}
	 * @throws \OCP\DB\Exception
	 */
	public function groupedForCard(int $cardId, Board $board, string $uid): array {
		$outgoing = $this->relationMapper->findOutgoing($cardId);
		$incoming = $this->relationMapper->findIncoming($cardId);

		// Resolve the viewer's side ONCE; each row then evaluates the same
		// rule the SQL scope applies - no per-row card fetch, no per-row ACL.
		$role = $this->visibilityGuard->roleOn($board, $uid);
		$entry = function (array $r) use ($uid, $role): array {
			$other = new Card();
			$other->setOwner($r['otherOwner']);
			$other->setVisibility($r['otherVisibility'] ?? CardVisibilityScope::VISIBILITY_PUBLIC);
			if ($r['otherCreatorRole'] !== null) {
				$other->setCreatorRole($r['otherCreatorRole']);
			}
			if (!$this->visibilityScope->isVisibleTo($other, $uid, $role)) {
				return [
					'id' => $r['id'],
					'cardId' => null,
					'title' => null,
					'done' => false,
					'hidden' => true,
				];
			}
			return [
				'id' => $r['id'],
				'cardId' => $r['otherCardId'],
				'title' => $r['otherTitle'],
				'done' => $r['otherDone'],
				'hidden' => false,
			];
		};
		$pick = static function (array $rows, string $type) use ($entry): array {
			return array_values(array_map($entry, array_filter($rows, static fn ($r): bool => $r['type'] === $type)));
		};

		return [
			// Outgoing blocks = "this card blocks X"; incoming = "blocked by X".
			'blocks' => $pick($outgoing, CardRelation::TYPE_BLOCKS),
			'blockedBy' => $pick($incoming, CardRelation::TYPE_BLOCKS),
			// Symmetric rows land on exactly one side; merge covers both.
			'duplicates' => array_merge($pick($outgoing, CardRelation::TYPE_DUPLICATES), $pick($incoming, CardRelation::TYPE_DUPLICATES)),
			'relates' => array_merge($pick($outgoing, CardRelation::TYPE_RELATES), $pick($incoming, CardRelation::TYPE_RELATES)),
		];
	}

	/**
	 * Every `blocks` edge on the board as {from, to} pairs, masked for $uid -
	 * the timeline's dependency arrows in ONE board-scoped query instead of a
	 * relations fetch per card.
	 *
	 * WHOLE-EDGE masking (#3743) - the security point of this payload. If
	 * EITHER endpoint is invisible to the viewer the edge is dropped ENTIRELY:
	 * no half-edge, no nulled side, and the visible endpoint's id is not
	 * emitted on its own either. A half-edge would still assert "this visible
	 * card is blocked by SOMETHING", which - repeated across the board - makes
	 * the list an existence oracle for restricted cards, and the arrow count
	 * alone would leak how many. The card-detail panel can afford a masked row
	 * ({@see self::groupedForCard()}: the relation is board-visible there and
	 * must stay removable); a bare board-wide edge list carries no such
	 * affordance, so it masks by omission instead.
	 *
	 * No permission check here: the caller has ALREADY gated READ on the board
	 * (BoardController::show()/changes() via BoardService::find) - the same
	 * contract {@see self::groupedForCard()} runs under.
	 *
	 * @return list<array{from: int, to: int}>
	 * @throws \OCP\DB\Exception
	 */
	public function blocksEdgesForBoard(Board $board, string $uid): array {
		$rows = $this->relationMapper->findBlocksEdgesWithVisibilityByBoard((int)$board->getId());

		// Resolve the viewer's side ONCE; each endpoint then evaluates the same
		// rule the SQL scope applies - no per-row card fetch, no per-row ACL.
		$role = $this->visibilityGuard->roleOn($board, $uid);
		$sees = function (?string $visibility, ?string $creatorRole, string $owner) use ($uid, $role): bool {
			$endpoint = new Card();
			$endpoint->setOwner($owner);
			// Pre-migration NULLs read as 'public'; a null creator_role is left
			// UNSET so the scope applies its own backfill fold - verbatim the
			// null handling in groupedForCard()'s $entry closure.
			$endpoint->setVisibility($visibility ?? CardVisibilityScope::VISIBILITY_PUBLIC);
			if ($creatorRole !== null) {
				$endpoint->setCreatorRole($creatorRole);
			}
			return $this->visibilityScope->isVisibleTo($endpoint, $uid, $role);
		};

		$edges = [];
		foreach ($rows as $row) {
			if (!$sees($row['fromVisibility'], $row['fromCreatorRole'], $row['fromOwner'])
				|| !$sees($row['toVisibility'], $row['toCreatorRole'], $row['toOwner'])) {
				continue;
			}
			$edges[] = ['from' => $row['from'], 'to' => $row['to']];
		}
		return $edges;
	}

	/**
	 * Adds a relation from $cardId to $otherCardId. Idempotent per stored row.
	 *
	 * @throws DoesNotExistException if either card or the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException on an unknown kind, self-relation, cross-board pair, or a blocks cycle
	 */
	public function addRelation(int $cardId, int $otherCardId, string $kind, string $uid): CardRelation {
		if (!in_array($kind, self::KINDS, true)) {
			throw new InvalidInputException('Unknown relation kind');
		}
		if ($cardId === $otherCardId) {
			throw new InvalidInputException('A card cannot relate to itself');
		}

		$card = $this->loadCard($cardId);
		$other = $this->loadCard($otherCardId);
		if ($card->getBoardId() !== $other->getBoardId()) {
			throw new InvalidInputException('Related cards must be on the same board');
		}
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);
		// Both endpoints must be visible to the actor (#3743): relating TO a
		// hidden card would both confirm it exists and leak its title into
		// this card's relations panel.
		$this->visibilityGuard->assertVisible($board, $card, $uid);
		$this->visibilityGuard->assertVisible($board, $other, $uid);

		// Resolve the API kind to a stored (source, target, type) triple.
		[$src, $dst, $type] = $this->resolveStorage($cardId, $otherCardId, $kind);

		if ($type === CardRelation::TYPE_BLOCKS && $this->wouldCycle($card->getBoardId(), $src, $dst)) {
			throw new InvalidInputException('That blocks relation would create a cycle');
		}

		if ($this->relationMapper->exists($src, $dst, $type)) {
			// Idempotent - return the existing row.
			foreach ($this->relationMapper->findOutgoing($src) as $r) {
				if ($r['otherCardId'] === $dst && $r['type'] === $type) {
					return $this->relationMapper->find($r['id']);
				}
			}
		}

		$relation = new CardRelation();
		$relation->setCardId($src);
		$relation->setOtherCardId($dst);
		$relation->setType($type);
		$relation->setBoardId($card->getBoardId());
		$relation->setCreatedAt(time());
		try {
			$relation = $this->relationMapper->insert($relation);
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new InvalidInputException('That relation already exists');
			}
			throw $e;
		}

		// Notify in STORAGE order (source, target) so each side's detail reads with
		// the right direction: the source blocks, the target is blocked by.
		[$srcCard, $dstCard] = $src === $cardId ? [$card, $other] : [$other, $card];
		$this->notifyBoth(
			$card->getBoardId(),
			$src,
			$srcCard,
			$dst,
			$dstCard,
			$type,
			$uid,
			Change::VERB_RELATION_ADDED,
		);
		return $relation;
	}

	/**
	 * @throws DoesNotExistException if the relation or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 */
	public function removeRelation(int $relationId, string $uid): void {
		$relation = $this->relationMapper->find($relationId);
		$board = $this->loadBoard($relation->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);
		// The actor must see at least ONE endpoint (#3743): the relation row
		// is rendered (masked) on the visible side, so deleting from there is
		// legitimate - but an id probed blind, where both endpoints are
		// hidden, must read as not-found.
		$endpointVisible = false;
		// Keep each endpoint's CARD while we are here: after the delete the
		// relation row is gone, and the change row is its only remaining trace.
		// The card (not just its title) is what notifyBoth needs - the detail is
		// only written for a counterpart that is public to everyone.
		$endpoints = [];
		foreach ([$relation->getCardId(), $relation->getOtherCardId()] as $endpointId) {
			try {
				$endpoint = $this->cardMapper->find($endpointId);
			} catch (DoesNotExistException) {
				continue;
			}
			$endpoints[$endpointId] = $endpoint;
			if ($this->visibilityGuard->isVisible($board, $endpoint, $uid)) {
				$endpointVisible = true;
			}
		}
		if (!$endpointVisible) {
			throw new DoesNotExistException('Relation ' . $relationId . ' does not exist');
		}

		$this->relationMapper->delete($relation);
		$this->notifyBoth(
			$relation->getBoardId(),
			$relation->getCardId(),
			$endpoints[$relation->getCardId()] ?? null,
			$relation->getOtherCardId(),
			$endpoints[$relation->getOtherCardId()] ?? null,
			$relation->getType(),
			$uid,
			Change::VERB_RELATION_REMOVED,
		);
	}

	/**
	 * @return array{0: int, 1: int, 2: string} [sourceCardId, targetCardId, storedType]
	 */
	private function resolveStorage(int $cardId, int $otherCardId, string $kind): array {
		if ($kind === self::KIND_BLOCKS) {
			return [$cardId, $otherCardId, CardRelation::TYPE_BLOCKS];
		}
		if ($kind === self::KIND_BLOCKED_BY) {
			// "this blocked by other" == "other blocks this".
			return [$otherCardId, $cardId, CardRelation::TYPE_BLOCKS];
		}
		// Symmetric: store once in canonical (min, max) order.
		$type = $kind === self::KIND_DUPLICATES ? CardRelation::TYPE_DUPLICATES : CardRelation::TYPE_RELATES;
		return [min($cardId, $otherCardId), max($cardId, $otherCardId), $type];
	}

	/**
	 * True if adding the blocks edge $src → $dst would close a cycle, i.e. $dst
	 * already reaches $src through existing blocks edges on the board.
	 */
	private function wouldCycle(int $boardId, int $src, int $dst): bool {
		$adjacency = [];
		foreach ($this->relationMapper->findBlocksEdgesByBoard($boardId) as $edge) {
			$adjacency[$edge['from']][] = $edge['to'];
		}

		// BFS from $dst following blocks edges; reaching $src means a cycle.
		$queue = [$dst];
		$seen = [$dst => true];
		while ($queue !== []) {
			$node = array_shift($queue);
			if ($node === $src) {
				return true;
			}
			foreach ($adjacency[$node] ?? [] as $next) {
				if (!isset($seen[$next])) {
					$seen[$next] = true;
					$queue[] = $next;
				}
			}
		}
		return false;
	}

	/**
	 * Writes the card change row for BOTH endpoints of a relation (#119) and
	 * gives each one its OWN detail, naming the card at the other end and the
	 * direction from that side ("Blocks: X" on the source, "Blocked by: Y" on the
	 * target) - recording the same string twice would leave each card's feed
	 * describing itself. The detail rides `to` on an add and `from` on a remove,
	 * the convention labels/assignees/attachments already use.
	 *
	 * $srcId/$dstId are in STORAGE order (the `blocks` direction), not the order
	 * the API call named them. A card may be null when that endpoint has since
	 * been deleted outright; the detail then falls back to the card id.
	 *
	 * Visibility (#3743): the stored detail has NO viewer - it is written once
	 * and later served verbatim by {@see ActivityService::getCardActivity()},
	 * which gates on the card being READ, never on the card being NAMED. So a
	 * counterpart's title is recorded only when that card's audience CONTAINS
	 * the audience of the card whose feed the detail lands in
	 * ({@see CardVisibilityScope::mayBeNamedIn()}); anything less would put the
	 * title the relations panel deliberately masks into the other card's feed,
	 * readable by anyone who can see THIS card. The two sides are decided
	 * INDEPENDENTLY: a public card may legitimately be named in a hidden card's
	 * feed while the hidden one stays unnamed in the public card's.
	 *
	 * A withheld counterpart writes the change row with NO detail at all - a bare
	 * verb, like VERB_FIELD_CHANGED. "#<id>" is not a substitute: a card id is
	 * still an existence oracle for a card the guard hides, and it tells a reader
	 * nothing.
	 */
	private function notifyBoth(int $boardId, int $srcId, ?Card $srcCard, int $dstId, ?Card $dstCard, string $type, string $uid, int $verb): void {
		[$srcLabel, $dstLabel] = self::RELATION_LABELS[$type] ?? ['Relates to', 'Relates to'];
		$sides = [
			// [card the row is about (the HOST of this detail), how the relation
			//  reads from there, the OTHER card]
			[$srcId, $srcCard, $srcLabel, $dstId, $dstCard],
			[$dstId, $dstCard, $dstLabel, $srcId, $srcCard],
		];
		foreach ($sides as [$cardId, $hostCard, $label, $otherId, $otherCard]) {
			$change = $this->changeNotifier->notify(
				$boardId,
				Change::ENTITY_CARD,
				$cardId,
				Change::ACTION_UPDATE,
				$uid,
				verb: $verb,
			);
			$detail = $this->relationDetail($label, $otherId, $otherCard, $hostCard);
			if ($detail === null) {
				continue;
			}
			$added = $verb === Change::VERB_RELATION_ADDED;
			$this->changeDetailMapper->insertDetail(
				$change->getId(),
				$added ? null : $detail,
				$added ? $detail : null,
			);
		}
	}

	/**
	 * The change detail naming the counterpart, or null when it must not be
	 * recorded at all (see {@see self::notifyBoth()}): a counterpart that still
	 * exists but whose audience does not contain the HOST card's never reaches
	 * the side table. $hostCard is the card this detail is written FOR - null
	 * only when that end has been deleted outright, which leaves
	 * {@see CardVisibilityScope::mayBeNamedIn()} on its public-only floor.
	 */
	private function relationDetail(string $label, int $otherId, ?Card $otherCard, ?Card $hostCard): ?string {
		if ($otherCard !== null && !$this->visibilityScope->mayBeNamedIn($otherCard, $hostCard)) {
			return null;
		}
		$title = $otherCard?->getTitle();
		return mb_substr(
			$label . ': ' . (($title !== null && $title !== '') ? $title : '#' . $otherId),
			0,
			self::MAX_DETAIL_LENGTH,
		);
	}

	/**
	 * @throws DoesNotExistException if the card does not exist or is deleted
	 */
	private function loadCard(int $id): Card {
		$card = $this->cardMapper->find($id);
		if ($card->getDeletedAt() > 0) {
			throw new DoesNotExistException('Card ' . $id . ' is deleted');
		}
		return $card;
	}

	/**
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 */
	private function loadBoard(int $id): Board {
		$board = $this->boardMapper->find($id);
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board ' . $id . ' is deleted');
		}
		return $board;
	}
}
