<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\Reminder;
use OCA\Kanso\Db\ReminderMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Personal, one-shot "remind me" on cards / comments (#3816). A user schedules
 * a reminder about a card (optionally a specific comment) for a future instant;
 * the 15-minute {@see \OCA\Kanso\Cron\SendPersonalReminders} sweep notifies the
 * setter once at that time via {@see NotificationService} (bell + push), deep-
 * linking to the card.
 *
 * PERSONAL by construction: every reminder belongs to the `user_id` that set it
 * and is only ever surfaced / cancellable by that same user - another member on
 * the card can neither see nor receive it. Scheduling needs only READ on the
 * board (plus card visibility) - a personal reminder is a private note-to-self,
 * not a mutation others observe, so it is NOT logged to {@see ChangeNotifier}.
 *
 * Idempotent firing, overdue catch-up, per-reminder error isolation and a
 * bounded run mirror {@see DueReminderService}: {@see self::fireDue()} stamps
 * `fired_at` so a reminder fires exactly once, any reminder whose time has
 * already passed is caught up on the next tick, and a throwing reminder is
 * logged and skipped without aborting the sweep.
 *
 * Visibility re-check at fire time (#3761 pattern, mirroring
 * {@see ReviewService}): the card may have NARROWED between scheduling and
 * firing. Each fire re-resolves the card + board and re-checks the visibility
 * guard against the SETTER; a setter who can no longer see the card is skipped
 * (the reminder is consumed - stamped fired - so it is not retried, exactly
 * like the card being purged), so a bell entry never leaks a hidden card.
 *
 * NOT a recurrence/snooze engine and NOT a rules system: one row = one fire.
 */
class ReminderService {
	/**
	 * Reminders processed per cron run. Bounds a single pass; a fired reminder
	 * drops out of the due candidate query, so subsequent runs drain any backlog.
	 */
	public const MAX_PER_RUN = 500;

	/**
	 * Per-run board memo for the fire-time visibility filter - one sweep may
	 * touch many reminders on the same board.
	 *
	 * @var array<int, Board>
	 */
	private array $boards = [];

	public function __construct(
		private ReminderMapper $reminderMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private CommentMapper $commentMapper,
		private PermissionService $permissionService,
		private NotificationService $notificationService,
		private CardVisibilityGuard $visibilityGuard,
		private ITimeFactory $time,
		private \Psr\Log\LoggerInterface $logger,
	) {
	}

	/**
	 * Schedules a personal reminder for $uid on the card at $remindAt (a unix
	 * instant, which must be in the future). Needs READ on the board + card
	 * visibility - the same gate {@see CardService::find} uses; a card the actor
	 * cannot see 404s exactly like a missing one. An optional $commentId must
	 * belong to this card (so the deep link can point at a real comment).
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 * @throws InvalidInputException if $remindAt is not in the future, or $commentId is not on this card
	 */
	public function schedule(int $cardId, string $uid, int $remindAt, ?int $commentId = null): Reminder {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);
		$this->visibilityGuard->assertVisible($board, $card, $uid);

		if ($remindAt <= $this->time->getTime()) {
			throw new InvalidInputException('Reminder time must be in the future');
		}

		if ($commentId !== null) {
			try {
				$comment = $this->commentMapper->find($commentId);
			} catch (DoesNotExistException) {
				throw new InvalidInputException('Comment does not exist');
			}
			if ($comment->getCardId() !== $cardId) {
				throw new InvalidInputException('Comment belongs to another card');
			}
		}

		return $this->reminderMapper->insertReminder($uid, $cardId, $commentId, $remindAt);
	}

	/**
	 * Cancels the caller's OWN reminder by row id. Idempotent: an unknown id, a
	 * reminder on another card, or one owned by someone else is a silent no-op
	 * (a reminder is private, so a foreign id must be indistinguishable from a
	 * missing one - never confirm its existence).
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function cancel(int $cardId, int $reminderId, string $uid): void {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);
		$this->visibilityGuard->assertVisible($board, $card, $uid);

		$reminder = $this->reminderMapper->findById($reminderId);
		if ($reminder === null
			|| $reminder->getCardId() !== $cardId
			|| $reminder->getUserId() !== $uid) {
			return;
		}
		$this->reminderMapper->delete($reminder);
	}

	/**
	 * The caller's own PENDING (un-fired) reminders on the card, soonest first -
	 * the card-detail "your reminders" list. Personal: only $uid's rows.
	 *
	 * @return list<array<string, mixed>>
	 * @throws \OCP\DB\Exception
	 */
	public function listMineForCard(int $cardId, string $uid): array {
		return array_values(array_map(
			static fn (Reminder $r): array => $r->jsonSerialize(),
			$this->reminderMapper->findPendingForUserCard($uid, $cardId),
		));
	}

	/**
	 * The cron entry point: fire every owed personal reminder. For each due
	 * reminder, re-check that its setter can still see the card, notify them, and
	 * stamp `fired_at` so it fires once. A reminder that throws is logged and
	 * skipped (per-reminder isolation); the rest of the run continues.
	 *
	 * @return int number of reminders fired
	 */
	public function fireDue(): int {
		$now = $this->time->getTime();
		$fired = 0;

		foreach ($this->reminderMapper->findDue($now, self::MAX_PER_RUN) as $reminder) {
			try {
				if ($this->fireOne($reminder, $now)) {
					$fired++;
				}
			} catch (\Throwable $e) {
				$this->logger->warning(
					'kanso: could not fire personal reminder ' . $reminder->getId(),
					['exception' => $e]
				);
			}
		}

		return $fired;
	}

	/**
	 * Fires a single due reminder: re-resolve the card + board, re-check the
	 * setter's visibility, notify, and stamp `fired_at`. Returns true when the
	 * bell was actually delivered.
	 *
	 * The reminder is consumed (stamped fired) whether or not it delivered: a
	 * card that was purged, or narrowed past the setter, must not be retried
	 * forever - it is done. Only a genuine error (a throw) leaves it un-stamped
	 * for the next run.
	 */
	private function fireOne(Reminder $reminder, int $now): bool {
		$delivered = false;

		try {
			$card = $this->cardMapper->find($reminder->getCardId());
			if ($card->getDeletedAt() > 0) {
				// The card was trashed after scheduling - consume, deliver nothing.
				$card = null;
			}
		} catch (DoesNotExistException) {
			$card = null;
		}

		if ($card !== null) {
			$board = $this->boards[$card->getBoardId()]
				??= $this->boardMapper->find($card->getBoardId());
			// Fire-time visibility re-check (#3761 pattern): the card may have
			// narrowed past the setter since scheduling - a bell entry naming a
			// card they can no longer see would be an existence oracle.
			if ($this->visibilityGuard->isVisible($board, $card, $reminder->getUserId())) {
				$this->notificationService->notifyCardReminder(
					$card->getId(),
					$reminder->getUserId(),
					$reminder->getCommentId(),
				);
				$delivered = true;
			}
		}

		$reminder->setFiredAt($now);
		$this->reminderMapper->update($reminder);

		return $delivered;
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
