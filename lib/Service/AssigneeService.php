<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\SubscriptionMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Card assignee assignments. Mirrors the label assignment flow in
 * LabelService: assigning needs EDIT on the card's board, mutations append a
 * card-targeted row to the `kanso_changes` log, and both operations are
 * idempotent (no-op writes no change row). The assignee additionally must
 * hold READ on the board - assigning an outsider would create a reference
 * the board payload can never resolve for them.
 */
class AssigneeService {
	public function __construct(
		private CardAssigneeMapper $cardAssigneeMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private NotificationService $notificationService,
		private SubscriptionService $subscriptionService,
	) {
	}

	/**
	 * Assigns the user to the card. Idempotent: re-assigning an already
	 * assigned user is a no-op and writes no change row.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if the assignee cannot read the board (or does not exist)
	 */
	public function assign(int $cardId, string $participantUid, string $actorUid): void {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);

		// Directly or via a group ACL, the assignee must at least see the
		// board. Unknown users hold no permissions, so they fail this too.
		if (($this->permissionService->getPermissions($board, $participantUid) & PermissionService::PERMISSION_READ) === 0) {
			throw new InvalidInputException('User has no access to this board');
		}

		if ($this->cardAssigneeMapper->exists($cardId, $participantUid)) {
			return;
		}

		try {
			$this->cardAssigneeMapper->insertAssignment($cardId, $participantUid);
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// Concurrent PUT lost the check-then-insert race - the
				// assignment exists, which is the idempotent success case.
				return;
			}
			throw $e;
		}

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_UPDATE,
			$actorUid,
			verb: Change::VERB_ASSIGNED,
		);

		// Targeted bell notification to the assignee (not the board fan-out).
		$this->notificationService->notifyCardAssigned($cardId, $participantUid, $actorUid);
		// Being assigned auto-subscribes you to the card (unless you opted out).
		$this->subscriptionService->autoSubscribe($cardId, SubscriptionMapper::THREAD_CARD, $participantUid);
	}

	/**
	 * Removes the user from the card. Idempotent: unassigning an absent
	 * assignment is a no-op and writes no change row.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 */
	public function unassign(int $cardId, string $participantUid, string $actorUid): void {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);

		if ($this->cardAssigneeMapper->deleteAssignment($cardId, $participantUid) === 0) {
			return;
		}

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_UPDATE,
			$actorUid,
			verb: Change::VERB_UNASSIGNED,
		);

		// Clear any pending "assigned to you" bell notification for this card.
		$this->notificationService->dismissCardAssigned($cardId, $participantUid);
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
	private function loadBoard(int $boardId): Board {
		$board = $this->boardMapper->find($boardId);
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board ' . $boardId . ' is deleted');
		}
		return $board;
	}
}
