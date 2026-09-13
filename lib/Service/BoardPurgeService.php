<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\BoardCascade;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The board purge: the irreversible half of a board delete.
 *
 * Deleting a board ({@see BoardService::delete()}) writes a `deleted_at`
 * tombstone and stops - the board leaves every read path immediately, which is
 * what a user asked for, but the rows and the attachment bytes stay. This
 * service is what eventually removes them, driven by
 * {@see \OCA\Kanso\Cron\PurgeDeletedBoards} once the retention window has
 * passed. There is no restore: boards already have a separate `archived` flag
 * for the reversible case, and the delete dialog promises permanence.
 *
 * Two-phase, in this order, and the order is the whole design:
 *
 *  1. STORAGE. App-data objects live outside the database, so they cannot take
 *     part in its transaction. The per-card folders go first, tolerating a
 *     failure on any single card ({@see CardAttachmentService::deleteObjectsForCards()}
 *     reports a count rather than throwing). If ANY card's bytes survived, the
 *     purge stops here and returns false: no row is touched, the board keeps
 *     its tombstone, and it is picked up again on the next run. So the outcome
 *     this phase guards against - rows gone with bytes stranded forever, and
 *     nothing left to find them by - cannot happen.
 *  2. ROWS. Everything registered in {@see BoardCascade}, then the cards, then
 *     the board row - all inside one transaction, so a database error rolls the
 *     board back to its tombstoned state and the next run retries it.
 *
 * The reverse leftover IS possible and is deliberately accepted: bytes gone,
 * then the row transaction fails and rolls back. The board is still tombstoned
 * and unreachable, the next run re-runs phase 1 (a missing folder is success,
 * not a failure) and completes. Retrying is therefore always safe; the only
 * cost of a failed attempt is attachment bytes of a board nobody can open.
 *
 * The storage phase is scoped by INTERSECTING the attachment rows' card ids
 * with the board's own card set. That intersection is the safety property: no
 * app-data folder outside this board can be reached, whichever of the two keys
 * an attachment row's columns happen to disagree on.
 *
 * Nothing here checks permissions: the MANAGE check happened when the board was
 * deleted, and the caller is cron running as the system. No change row is
 * written either - the board is gone, so there is no board-scoped log left to
 * append to (its `kanso_changes` rows are part of the cascade).
 */
class BoardPurgeService {
	public function __construct(
		private IDBConnection $db,
		private BoardMapper $boardMapper,
		private CardMapper $cardMapper,
		private BoardCascade $cascade,
		private CardAttachmentService $cardAttachmentService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Ids of boards whose tombstone is older than $deletedBefore, oldest first.
	 *
	 * @return list<int> at most $limit ids
	 */
	public function findPurgeable(int $deletedBefore, int $limit): array {
		return $this->boardMapper->findPurgeableIds($deletedBefore, $limit);
	}

	/**
	 * Permanently removes one soft-deleted board and everything scoped to it.
	 *
	 * @return bool true when the board is gone; false when the storage sweep
	 *              left bytes behind and the board was deliberately left
	 *              tombstoned for the next run
	 * @throws \OCP\DB\Exception if the row purge fails (the transaction is
	 *                           rolled back first, so the board stays reapable)
	 */
	public function purge(int $boardId): bool {
		// Read the card set ONCE, before anything is deleted: it keys both
		// phases, and after the cards are gone it cannot be recovered.
		$cardIds = $this->cardMapper->findAllIdsByBoard($boardId);

		// Phase 1 - the bytes, outside any transaction. Only cards that hold an
		// attachment row have a folder worth visiting, and only cards of THIS
		// board may be visited at all: a folder is named by card id alone, so an
		// attachment row whose board_id and card_id disagree must never be able
		// to point the sweep at a live board's card.
		$storageFailures = $this->cardAttachmentService->deleteObjectsForCards(
			$this->attachmentCardIds($boardId, $cardIds),
		);
		if ($storageFailures > 0) {
			$this->logger->warning(
				'Kanso: board purge deferred, {failures} card folder(s) could not be removed',
				['app' => 'kanso', 'boardId' => $boardId, 'failures' => $storageFailures],
			);
			return false;
		}

		// Phase 2 - the rows, atomically.
		$this->db->beginTransaction();
		try {
			// Grandchildren first: once their parent row is gone, nothing can
			// find them again. Comment reactions hang off comments (reachable
			// only through the board's cards), change details off change rows
			// and seen-mail markers off the board's mail intake.
			$this->purgeGrandchildren($boardId, $cardIds);

			$this->cascade->deleteByCardIds($cardIds);
			$this->cascade->deleteByBoardId($boardId);
			$this->cardMapper->deleteByBoard($boardId);
			$this->boardMapper->deleteById($boardId);

			$this->db->commit();
		} catch (\Throwable $e) {
			try {
				$this->db->rollBack();
			} catch (\Throwable) {
				// A rollback that fails (connection gone) must not replace the
				// real cause - the job logs $e and retries the board next run.
			}
			throw $e;
		}

		return true;
	}

	/**
	 * The cards of $boardId whose bytes the storage sweep has to visit: cards
	 * carrying an attachment row, found by EITHER key the row can be scoped by,
	 * intersected with the board's own card set so the sweep can never name a
	 * folder belonging to a surviving board.
	 *
	 * @param list<int> $cardIds the board's card ids
	 * @return list<int>
	 * @throws \OCP\DB\Exception
	 */
	private function attachmentCardIds(int $boardId, array $cardIds): array {
		$candidates = array_merge(
			$this->cascade->idsByBoardId('kanso_card_attachments', $boardId, 'card_id'),
			$this->cascade->idsIn('kanso_card_attachments', 'card_id', $cardIds, 'card_id'),
		);

		return array_values(array_unique(array_intersect($candidates, $cardIds)));
	}

	/**
	 * Empties every {@see BoardCascade::BY_PARENT_ID} table, resolving each
	 * parent id set through the parent's OWN link column as the registry
	 * declares it - never through a guess, which would resolve to an empty id
	 * set and silently leave the rows behind.
	 *
	 * @param list<int> $cardIds
	 * @throws \OCP\DB\Exception
	 */
	private function purgeGrandchildren(int $boardId, array $cardIds): void {
		foreach (BoardCascade::BY_PARENT_ID as $table => [$column, $parentTable, $parentLink]) {
			$parentIds = $parentLink === 'board_id'
				? $this->cascade->idsByBoardId($parentTable, $boardId)
				: $this->cascade->idsIn($parentTable, $parentLink, $cardIds);
			$this->cascade->deleteIn($table, $column, $parentIds);
		}
	}
}
