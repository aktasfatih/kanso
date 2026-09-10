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
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Due-date reminder sweep (#3545): notifies a card's assignees and watchers
 * when it is due. Two FIXED reminders per card - AT due time (always, once a
 * card has a due date) and an OPTIONAL "1 day before" (the card-level
 * `dueReminderDayBefore` opt-in). NOT a per-user preference matrix and NOT an
 * email-digest engine - a fixed set of two bells fired by the
 * {@see \OCA\Kanso\Cron\SendDueReminders} cron.
 *
 * Idempotency / no duplicate spam: each reminder stamps its own marker column
 * on the card (`dueReminderSent`, `dayBeforeReminderSent`) with the fire time,
 * so a second cron tick over the same card is a no-op. The markers are reset to
 * 0 in {@see CardService::update} whenever the due date CHANGES, which re-arms
 * both reminders for the new date. A card with no due date is ignored; done,
 * archived and deleted cards are excluded by the candidate query, as are cards
 * on an archived or trashed BOARD (#10127) - archiving a board is the user's
 * "I am done with this" gesture, and a reminder pushes at them out of band
 * rather than merely sitting in a list they can ignore. Template cards are
 * excluded on the same grounds (#10180): a template is a blueprint kept off the
 * live board, so its due date is part of the pattern to copy, not work anybody
 * owes yet.
 *
 * Timezone: consistent with how due dates are already stored/compared - the
 * unix instant of the stored `duedate`. "At due time" = duedate <= now; "1 day
 * before" = duedate - 86400 <= now (only when opted in). No wall-clock/DST math.
 *
 * Bounded + resilient like the other crons ({@see ArchiveService},
 * {@see RecurrenceService}): at most {@see self::MAX_PER_RUN} cards per run, and
 * each card is wrapped in its own try/catch so one bad card (e.g. a purged
 * assignee) cannot abort the sweep. The remainder is picked up next run.
 *
 * Change log: stamping a marker rewrites summary columns, so every stamped card
 * appends its own ENTITY_CARD/ACTION_UPDATE row - otherwise the reminder state
 * would be invisible to delta sync and the board ETag. The realtime push, which
 * says nothing more than "this board moved", is coalesced to one event per
 * touched board and emitted at the end of the run.
 */
class DueReminderService {
	/**
	 * Cards processed per cron run. Bounds a single pass; because a stamped card
	 * drops out of the candidate query, subsequent runs drain any backlog.
	 */
	public const MAX_PER_RUN = 500;

	/** One day in seconds - the fixed "day before" lead time. */
	private const DAY_SECONDS = 86400;

	/**
	 * Per-run board memo for the visibility filter - the sweep may touch many
	 * cards of the same board, and each needs the board only to resolve the
	 * audience's roles.
	 *
	 * @var array<int, Board>
	 */
	private array $boards = [];

	public function __construct(
		private CardMapper $cardMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private SubscriptionMapper $subscriptionMapper,
		private NotificationService $notificationService,
		private BoardMapper $boardMapper,
		private CardVisibilityGuard $visibilityGuard,
		private ITimeFactory $time,
		private \Psr\Log\LoggerInterface $logger,
		private ChangeNotifier $changeNotifier,
	) {
	}

	/**
	 * The cron entry point: fire every owed reminder across all boards. For each
	 * candidate card, sends the at-due and/or day-before reminder to the union of
	 * its assignees and watchers, then stamps the marker(s) so it is not
	 * re-notified. A card that throws is logged and skipped.
	 *
	 * Each stamped card gets its own `kanso_changes` row (see {@see self::processCard()}),
	 * but the realtime pushes are coalesced to one per touched board and emitted
	 * here, after the sweep.
	 *
	 * @return int number of reminder notifications' cards processed (a card that
	 *             fired at least one reminder counts once)
	 */
	public function runDueReminders(): int {
		$now = $this->time->getTime();
		$processed = 0;
		// Boards this run actually stamped, keyed by id for dedup. The change ROW is
		// per card (delta sync needs each one), but the push is per BOARD and says
		// nothing beyond "this board moved" - and pushBoardChanged() fans out per
		// board participant, so one push per card would turn a routine tick over
		// MAX_PER_RUN cards into hundreds of identical queue writes.
		$touchedBoards = [];

		foreach ($this->cardMapper->findDueForReminder($now, self::MAX_PER_RUN) as $card) {
			try {
				if ($this->processCard($card, $now)) {
					$processed++;
					// Only a card that fired (and whose change row landed - a throw
					// from processCard skips this) puts its board on the push list.
					$touchedBoards[$card->getBoardId()] = true;
				}
			} catch (\Throwable $e) {
				$this->logger->warning(
					'kanso: could not send due reminder for card ' . $card->getId(),
					['exception' => $e]
				);
			}
		}

		// After the loop, so every stamped card is written before any client is told
		// to refetch. Best-effort by contract - pushBoardChanged() never throws, and
		// the change rows already landed, so a client that misses the event still
		// converges on its next poll.
		foreach (array_keys($touchedBoards) as $boardId) {
			$this->changeNotifier->pushBoardChanged($boardId);
		}

		return $processed;
	}

	/**
	 * Sends whichever reminders this card owes and stamps their markers. Returns
	 * true when at least one reminder fired (so a candidate that matched only the
	 * day-before window but had it disabled, etc. does not inflate the count).
	 *
	 * The candidate query is a coarse filter (either marker possibly owed); the
	 * precise per-marker decision is re-checked here against $now so the count and
	 * the markers are exact.
	 *
	 * A card that fires also appends a change row; a card that fires nothing writes
	 * neither row nor stamp, so the board ETag does not move for a sweep that
	 * changed nothing.
	 */
	private function processCard(Card $card, int $now): bool {
		$duedate = $card->getDuedate();
		if ($duedate === null) {
			// Defensive: the query excludes NULL due dates, but never assume.
			return false;
		}
		$dueTs = $duedate->getTimestamp();

		$fired = false;

		// "1 day before": opted in, unsent, and within the lead window.
		if (($card->getDueReminderDayBefore() ?? false)
			&& $card->getDayBeforeReminderSent() === 0
			&& ($dueTs - self::DAY_SECONDS) <= $now) {
			$this->notifyRecipients($card, 1);
			$card->setDayBeforeReminderSent($now);
			$fired = true;
		}

		// "At due time": unsent and the due date has passed.
		if ($card->getDueReminderSent() === 0 && $dueTs <= $now) {
			$this->notifyRecipients($card, 0);
			$card->setDueReminderSent($now);
			$fired = true;
		}

		if ($fired) {
			$card->setLastModified($now);
			$this->cardMapper->update($card);
			// `last_modified` and both reminder markers are CardMapper::SUMMARY_COLUMNS,
			// so this stamp IS a board-payload mutation. Without the row the board ETag
			// never moves and a delta-sync client keeps serving the pre-reminder card
			// forever. Actor null - the cron acts for nobody (and ChangeNotifier skips
			// Activity for a null actor, which is what we want for a sweep).
			//
			// Written here, inside the caller's per-card try/catch, so a failed insert
			// skips ONE card instead of aborting the sweep. The push is deliberately
			// NOT emitted per card: runDueReminders() collects the boards and fires
			// one event each at the end of the run.
			$this->changeNotifier->recordChange(
				$card->getBoardId(),
				Change::ENTITY_CARD,
				$card->getId(),
				Change::ACTION_UPDATE,
				null,
			);
		}

		return $fired;
	}

	/**
	 * Fans the reminder out to the union of the card's assignees and its card-
	 * level watchers, deduplicated - RESTRICTED to those who can SEE the card
	 * (#3760): a reminder about a card the recipient cannot see would be an
	 * existence oracle for it. The audience is filtered in one batched role
	 * resolution ({@see CardVisibilityGuard::filterVisible()}), never
	 * per-recipient queries. Actor-less (a system event), so there is no one
	 * to skip. $daysBefore selects the fixed reminder (0 = at due, 1 = day
	 * before).
	 */
	private function notifyRecipients(Card $card, int $daysBefore): void {
		$recipients = array_unique(array_merge(
			$this->cardAssigneeMapper->findUserIdsByCard($card->getId()),
			$this->subscriptionMapper->findCardSubscriberUids($card->getId()),
		));
		if ($recipients === []) {
			return;
		}

		$board = $this->boards[$card->getBoardId()]
			??= $this->boardMapper->find($card->getBoardId());

		foreach ($this->visibilityGuard->filterVisible($board, $card, $recipients) as $uid) {
			$this->notificationService->notifyCardDue($card->getId(), $uid, $daysBefore);
		}
	}
}
