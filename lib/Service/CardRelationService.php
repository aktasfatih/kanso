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

	public function __construct(
		private CardRelationMapper $relationMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private PermissionService $permissionService,
		private ChangeNotifier $changeNotifier,
		private CardVisibilityGuard $visibilityGuard,
		private CardVisibilityScope $visibilityScope,
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

		$this->notifyBoth($card->getBoardId(), $cardId, $otherCardId, $uid);
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
		foreach ([$relation->getCardId(), $relation->getOtherCardId()] as $endpointId) {
			try {
				$endpoint = $this->cardMapper->find($endpointId);
			} catch (DoesNotExistException) {
				continue;
			}
			if ($this->visibilityGuard->isVisible($board, $endpoint, $uid)) {
				$endpointVisible = true;
				break;
			}
		}
		if (!$endpointVisible) {
			throw new DoesNotExistException('Relation ' . $relationId . ' does not exist');
		}

		$this->relationMapper->delete($relation);
		$this->notifyBoth($relation->getBoardId(), $relation->getCardId(), $relation->getOtherCardId(), $uid);
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

	private function notifyBoth(int $boardId, int $cardIdA, int $cardIdB, string $uid): void {
		foreach ([$cardIdA, $cardIdB] as $id) {
			$this->changeNotifier->notify($boardId, Change::ENTITY_CARD, $id, Change::ACTION_UPDATE, $uid);
		}
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
