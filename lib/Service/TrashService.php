<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardCascade;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The trash: listing, restoring and permanently deleting soft-deleted cards.
 * A card delete (CardService::delete) is a soft-delete (deleted_at) and the
 * board payload hides such cards; this service is the only way to see, recover
 * or truly remove them.
 *
 *   - listTrash needs READ on the board;
 *   - restore needs EDIT (it puts a card back into play);
 *   - purge needs MANAGE - it is the one irreversible, hard delete in the app,
 *     cascading to the card's labels, assignees, reviews, checklist items,
 *     comments and file attachments (both the app-data objects and the rows).
 *
 * That cascade names no tables of its own: it reads the SAME registry the board
 * purge reads ({@see BoardCascade::BY_CARD_ID}, plus the card-scoped half of
 * {@see BoardCascade::BY_PARENT_ID}), so the anti-rot guard over that registry -
 * BoardCascadeCompletenessTest, which re-scans every migration - covers this
 * path too. A new card-scoped table cannot be forgotten HERE while being
 * remembered in the board purge, because there is only one list to remember.
 *
 * The purge also sweeps the card's Nextcloud NOTIFICATIONS, through the same
 * supported route the board purge uses ({@see NotificationService::dismissAllForObjects()},
 * i.e. `IManager::markProcessed()`, never a write into `oc_notifications`). Both
 * object keys have to go: card notifications are keyed by card id, but
 * `step_assigned` is keyed by CHECKLIST-ITEM id (#3745), so a card-id-only sweep
 * would leave the step entries behind. The residue is invisible rather than
 * wrong-looking - {@see \OCA\Kanso\Notification\Notifier::prepare()} throws once
 * the card is gone, so nothing renders - but the rows keep counting toward the
 * unread badge, which the user can then never clear because there is nothing
 * left to click.
 *
 * It runs FIRST, ahead of every destructive step, for two reasons. A
 * notification is found by its object id, so once the checklist rows are gone
 * there is nothing left to enumerate them by; and a failure there must not leave
 * a half-purged card. Unlike the board purge - cron, retried on its tombstone -
 * this path is interactive, so a sweep failure PROPAGATES and the purge simply
 * does not happen: the card stays in the trash, intact and re-purgeable, and the
 * user is told the delete failed. The alternative (log the failure, purge
 * anyway) would hand back a success while creating exactly the permanent,
 * unclearable badge inflation this sweep exists to prevent - and it is
 * unreachable in practice anyway, since the notification backend and the rows
 * below it share one database.
 *
 * Restore/purge only ever act on an ALREADY-trashed card (deleted_at > 0); a
 * live card is rejected as invalid input - but only once the caller has cleared
 * the board permission AND the card-visibility guard, in that order, so the
 * rejection can never double as an existence oracle (#10307). Both append a
 * card-targeted row to `kanso_changes` so the board ETag bumps and clients
 * refetch.
 */
class TrashService {
	public function __construct(
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private BoardCascade $cascade,
		private CardAttachmentService $cardAttachmentService,
		private BoardAccess $boardAccess,
		private CardVisibilityGuard $visibilityGuard,
		private NotificationService $notificationService,
	) {
	}

	/**
	 * Soft-deleted cards of the board (summaries), most-recently-deleted first.
	 * Requires READ.
	 *
	 * @return Card[]
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function listTrash(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);

		// Visibility (#3743): the trash shows only cards the viewer could see
		// alive - deleting an internal/private card must not surface it here.
		return $this->cardMapper->findDeletedByBoard(
			$boardId,
			$this->boardAccess->contextFor($board, $actorUid),
		);
	}

	/**
	 * Restores a trashed card (clears deleted_at) so it returns to its stack.
	 * Requires EDIT.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist, or the board is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if the card is not in the trash
	 */
	public function restore(int $cardId, string $actorUid): Card {
		$card = $this->cardMapper->find($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);
		// Only AFTER access is settled may the card's trash state be revealed:
		// a 400 "not in the trash" ahead of the guards is an existence oracle.
		$this->assertTrashed($card);

		$card->setDeletedAt(0);
		$card->setLastModified(time());
		$card = $this->cardMapper->update($card);

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_CREATE,
			$actorUid
		);

		return $card;
	}

	/**
	 * Permanently deletes a trashed card and everything hanging off it (labels,
	 * assignees, checklist items, comments) plus its Nextcloud notifications.
	 * Requires MANAGE. Irreversible.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist, or the board is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 * @throws InvalidInputException if the card is not in the trash
	 * @throws \OCP\DB\Exception if the notification sweep fails - deliberately
	 *                           BEFORE anything is deleted, so the card is left
	 *                           whole in the trash and the caller can retry
	 */
	public function purge(int $cardId, string $actorUid): void {
		$card = $this->cardMapper->find($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);
		// Access first, trash state second - see restore().
		$this->assertTrashed($card);

		// Nextcloud's own notification rows go first, while the objects that key
		// them still exist. Only THIS card's id and the checklist items resolved
		// from it: the sweep matches on app + object type + object id with no
		// user, so every id named here clears that object's bell entries for
		// every recipient, and a foreign id would wipe a live card's. Sub-cards
		// are deliberately absent - CardService::delete() detaches children
		// (parent_card_id cleared) before the soft-delete, so a trashed card has
		// none and the purge below cascades to no other card; sweeping child ids
		// would be exactly the foreign-id mistake.
		$this->notificationService->dismissAllForObjects(
			NotificationService::OBJECT_CARD,
			[$cardId],
		);
		$this->notificationService->dismissAllForObjects(
			NotificationService::OBJECT_CHECKLIST_ITEM,
			$this->cascade->idsIn('kanso_checklist_items', 'card_id', [$cardId]),
		);

		// Grandchildren first: a reaction hangs off comment_id, so it has to go
		// BEFORE the comments that locate it are hard-deleted, or the rows are
		// orphaned with nothing left to find them by (#3550).
		$this->purgeGrandchildren($cardId);
		// Then the bytes. Attachments are app-data OBJECTS plus rows, so the
		// cascade goes through the service (it removes both) and has to run while
		// the rows naming the storage keys are still there - otherwise a purge
		// leaks the bytes on disk forever (#3526).
		$this->cardAttachmentService->deleteAllForCard($cardId);
		// Then every card-scoped table, straight from the shared registry: labels,
		// assignees, contacts, reviews, checklist items, comments, subscriptions,
		// links, relations (both ends), project memberships, running timers,
		// time entries, custom-field values, reminders and the recurrence rules
		// anchored on this card as a template.
		$this->cascade->deleteByCardIds([$cardId]);
		$this->cardMapper->delete($card);

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_DELETE,
			$actorUid
		);
	}

	/**
	 * Empties the card-scoped half of {@see BoardCascade::BY_PARENT_ID}: tables
	 * with neither a board_id nor a card_id, reachable only through the id of a
	 * row that itself hangs off the card (today: comment reactions, via the
	 * card's comments). The board purge does the same walk for the board's whole
	 * card set - see {@see BoardPurgeService::purgeGrandchildren()}.
	 *
	 * Entries whose parent is reached by board_id (change details, seen-mail
	 * markers) are skipped: a card purge never deletes their parent, so sweeping
	 * them here would destroy rows of a board that is still very much alive.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function purgeGrandchildren(int $cardId): void {
		$cardScoped = [];
		foreach (BoardCascade::BY_CARD_ID as $table => $columns) {
			foreach ($columns as $column) {
				$cardScoped[$table . '.' . $column] = true;
			}
		}

		foreach (BoardCascade::BY_PARENT_ID as $table => [$column, $parentTable, $parentLink]) {
			if (!isset($cardScoped[$parentTable . '.' . $parentLink])) {
				continue;
			}
			$this->cascade->deleteIn(
				$table,
				$column,
				$this->cascade->idsIn($parentTable, $parentLink, [$cardId]),
			);
		}
	}

	/**
	 * The "is this card actually trashed?" input check, deliberately run LAST -
	 * after board permission AND card visibility. It answers 400 for a live card
	 * while a card the caller may not reach answers 403/404, so running it first
	 * would let ANY logged-in user probe a bare card id and learn that the card
	 * exists and is not in the trash - across board boundaries (#10307).
	 *
	 * @throws InvalidInputException if the card is not in the trash (deleted_at == 0)
	 */
	private function assertTrashed(Card $card): void {
		if ($card->getDeletedAt() === 0) {
			throw new InvalidInputException('Card ' . $card->getId() . ' is not in the trash');
		}
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
