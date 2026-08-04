<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\BoardPinMapper;

/**
 * Per-user board pinning (#3632).
 *
 * A pin is PER-USER curation: my pins are invisible to you and are never
 * shared - so, exactly like {@see BoardGroupService} folders, this is personal
 * state in its own table, NOT a column on the shared board row and NOT a board
 * `kanso_changes` entry (it must not churn the board ETag for everyone).
 *
 * Pinning is READ-gated: you may pin any board you can READ. The gate is
 * delegated to {@see BoardService::find}, which asserts READ or throws
 * NotPermitted / DoesNotExist - so a user can never pin a board they cannot
 * see (IDOR guard on the pin endpoints).
 */
class BoardPinService {
	public function __construct(
		private BoardPinMapper $pinMapper,
		private BoardService $boardService,
	) {
	}

	/**
	 * Pin a board for the user (idempotent). The board must be READable by the
	 * caller.
	 *
	 * @throws NotPermittedException if the user may not read the board
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if the board is gone
	 * @throws \OCP\DB\Exception
	 */
	public function pin(string $uid, int $boardId): void {
		// READ-gate: find() asserts READ (or throws), so a user can never pin a
		// board they cannot see.
		$this->boardService->find($boardId, $uid);
		$this->pinMapper->pin($uid, $boardId);
	}

	/**
	 * Unpin a board for the user (idempotent). No read-gate is needed to REMOVE
	 * one's own pin (a user may always drop their own curation row, and the
	 * delete is scoped to their uid), so this succeeds even if the board is no
	 * longer readable.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function unpin(string $uid, int $boardId): void {
		$this->pinMapper->unpin($uid, $boardId);
	}
}
