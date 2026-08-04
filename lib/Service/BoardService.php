<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardGroupMemberMapper;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\BoardPinMapper;
use OCA\Kanso\Db\BoardPrefix;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\EstimateScale;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Board CRUD. Every mutation appends a row to the `kanso_changes` log in
 * the same flow, so a board's max change id always reflects its latest
 * state (that id is the board ETag and the delta-sync cursor).
 */
class BoardService {
	private const MAX_TITLE_LENGTH = 100;

	public function __construct(
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private CardMapper $cardMapper,
		private CardReviewMapper $cardReviewMapper,
		private BoardGroupMemberMapper $boardGroupMemberMapper,
		private BoardPinMapper $boardPinMapper,
	) {
	}

	/**
	 * All boards visible to the user (owned or shared with them).
	 *
	 * @return Board[]
	 */
	public function findAll(string $uid): array {
		return $this->boardMapper->findAllForUser(
			$uid,
			$this->permissionService->getUserGroupIds($uid)
		);
	}

	/**
	 * The boards-LIST payload: every visible board serialized to a summary, each
	 * with a `stats` block of per-board signal for the boards page - card count,
	 * done progress %, needs-review count and overdue count.
	 *
	 * The list stays summary-only and, critically, N+1-free: the aggregates are a
	 * FIXED number of batched `GROUP BY board_id` queries over the user's readable
	 * board-id set (the exact set {@see self::findAll()} already ACL-authorizes),
	 * NOT one query per board. A board the user cannot READ is never in that set,
	 * so it contributes nothing - the aggregates are bounded to the readable set
	 * with no full-table scan.
	 *
	 * Archived-consistency: all four signals (`cardCount`, `progress`,
	 * `needsReview`, `overdue`) cover the SAME open scope (non-deleted,
	 * non-archived), matching how the board-stats distribution aggregates treat
	 * archived cards - so the tile is internally consistent (`cardCount` is "cards
	 * remaining", not a historical grand total, and a board can never read
	 * "0 cards" while still showing a review/overdue count from archived work).
	 *
	 * @return list<array<string, mixed>> each board's summary + a `stats` block
	 * @throws \OCP\DB\Exception
	 */
	public function findAllWithStats(string $uid): array {
		$boards = $this->findAll($uid);
		$boardIds = array_map(static fn (Board $b): int => $b->getId(), $boards);

		// A fixed set of batched aggregates over the readable board-id set - the
		// count is constant no matter how many boards the user has.
		$counts = $this->cardMapper->countByBoards($boardIds);
		$ratios = $this->cardMapper->doneRatioByBoards($boardIds);
		$overdue = $this->cardMapper->overdueCountByBoards($boardIds, new \DateTime('@' . time()));
		$needsReview = $this->cardReviewMapper->needsReviewCountByBoards($boardIds);
		// Per-user board folder (#3529): ONE batched WHERE uid = ? AND board_id
		// IN (...) over the same readable set - not one query per board. A board
		// absent from the map is Ungrouped (groupId null).
		$groupIds = $this->boardGroupMemberMapper->findGroupIdsByBoards($uid, $boardIds);
		// Per-user board pinning (#3632): ONE batched WHERE uid = ? AND board_id
		// IN (...) over the same readable set - not one query per board. A board
		// absent from the map is not pinned by this user.
		$pinned = $this->boardPinMapper->pinnedMap($uid, $boardIds);

		$out = [];
		foreach ($boards as $board) {
			$id = $board->getId();
			$ratio = $ratios[$id] ?? ['total' => 0, 'done' => 0];
			$total = $ratio['total'];
			$done = $ratio['done'];
			$out[] = $board->jsonSerialize() + [
				// The folder this board sits in for THIS user, or null (Ungrouped).
				'groupId' => $groupIds[$id] ?? null,
				// Whether THIS user has pinned this board (#3632).
				'pinned' => $pinned[$id] ?? false,
				'stats' => [
					'cardCount' => $counts[$id] ?? 0,
					'doneCount' => $done,
					// Whole-percent done ratio; 0 for an empty board (no divide-by-zero).
					'progress' => $total > 0 ? (int)round($done * 100 / $total) : 0,
					'needsReview' => $needsReview[$id] ?? 0,
					'overdue' => $overdue[$id] ?? 0,
				],
			];
		}
		return $out;
	}

	/**
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not read the board
	 */
	public function find(int $id, string $uid): Board {
		$board = $this->loadBoard($id);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);
		return $board;
	}

	/**
	 * Creates a board owned by $uid and writes the initial ACTION_CREATE
	 * change row, so the board's max change id is never null.
	 *
	 * @throws InvalidInputException on invalid title or color
	 */
	public function create(string $title, ?string $color, string $uid): Board {
		$now = time();

		$board = new Board();
		$validTitle = $this->validateTitle($title);
		$board->setTitle($validTitle);
		$board->setColor(ColorValidator::assertValid($color));
		$board->setOwner($uid);
		$board->setArchived(false);
		$board->setLastModified($now);
		$board->setDeletedAt(0);
		// Default the human-id prefix from the title (e.g. "My Project" → "MYPRO").
		// Editable later in board settings; the number is per-card (kanso_cards.board_seq).
		$board->setPrefix(BoardPrefix::fromTitle($validTitle));
		$board = $this->boardMapper->insert($board);

		$this->changeNotifier->notify(
			$board->getId(),
			Change::ENTITY_BOARD,
			$board->getId(),
			Change::ACTION_CREATE,
			$uid
		);

		return $board;
	}

	/**
	 * Updates the given fields (null = leave unchanged; an empty color
	 * string clears the color).
	 *
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 * @throws InvalidInputException on invalid title, color, background, estimate scale or prefix
	 */
	public function update(int $id, ?string $title, ?string $color, ?bool $archived, string $uid, ?string $estimateScale = null, ?bool $newCardsOnTop = null, ?string $prefix = null, ?string $background = null): Board {
		$board = $this->loadBoard($id);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);

		if ($title !== null) {
			$board->setTitle($this->validateTitle($title));
		}
		if ($color !== null) {
			$board->setColor(ColorValidator::assertValid($color));
		}
		if ($background !== null) {
			$board->setBackground(BackgroundValidator::assertValid($background));
		}
		if ($archived !== null) {
			$board->setArchived($archived);
		}
		if ($estimateScale !== null) {
			if (!EstimateScale::isValidScale($estimateScale)) {
				throw new InvalidInputException('Unknown estimate scale');
			}
			$board->setEstimateScale($estimateScale);
		}
		if ($newCardsOnTop !== null) {
			$board->setNewCardsOnTop($newCardsOnTop);
		}
		if ($prefix !== null) {
			// Normalize to the stored shape (uppercased, alnum, capped). A value
			// that reduces to nothing is invalid - the caller must pick real chars.
			// Changing the prefix only re-displays existing cards; their assigned
			// numbers (board_seq) are immutable and unaffected.
			$normalized = BoardPrefix::normalize($prefix);
			if ($normalized === null) {
				throw new InvalidInputException('Prefix must contain at least one letter or digit');
			}
			$board->setPrefix($normalized);
		}

		$now = time();
		$board->setLastModified($now);
		$board = $this->boardMapper->update($board);

		$this->changeNotifier->notify(
			$id,
			Change::ENTITY_BOARD,
			$id,
			Change::ACTION_UPDATE,
			$uid
		);

		return $board;
	}

	/**
	 * Soft-deletes the board (sets deleted_at).
	 *
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 */
	public function delete(int $id, string $uid): Board {
		$board = $this->loadBoard($id);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);

		$now = time();
		$board->setDeletedAt($now);
		$board->setLastModified($now);
		$board = $this->boardMapper->update($board);

		$this->changeNotifier->notify(
			$id,
			Change::ENTITY_BOARD,
			$id,
			Change::ACTION_DELETE,
			$uid
		);

		return $board;
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

	/**
	 * @throws InvalidInputException
	 */
	private function validateTitle(string $title): string {
		$title = trim($title);
		if ($title === '') {
			throw new InvalidInputException('Title must not be empty');
		}
		if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
			throw new InvalidInputException(
				'Title must not exceed ' . self::MAX_TITLE_LENGTH . ' characters'
			);
		}
		return $title;
	}
}
