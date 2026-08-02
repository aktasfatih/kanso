<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardMapper;
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
 * archived and deleted cards are excluded by the candidate query.
 *
 * Timezone: consistent with how due dates are already stored/compared - the
 * unix instant of the stored `duedate`. "At due time" = duedate <= now; "1 day
 * before" = duedate - 86400 <= now (only when opted in). No wall-clock/DST math.
 *
 * Bounded + resilient like the other crons ({@see ArchiveService},
 * {@see RecurrenceService}): at most {@see self::MAX_PER_RUN} cards per run, and
 * each card is wrapped in its own try/catch so one bad card (e.g. a purged
 * assignee) cannot abort the sweep. The remainder is picked up next run.
 */
class DueReminderService {
	/**
	 * Cards processed per cron run. Bounds a single pass; because a stamped card
	 * drops out of the candidate query, subsequent runs drain any backlog.
	 */
	public const MAX_PER_RUN = 500;

	/** One day in seconds - the fixed "day before" lead time. */
	private const DAY_SECONDS = 86400;

	public function __construct(
		private CardMapper $cardMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private SubscriptionMapper $subscriptionMapper,
		private NotificationService $notificationService,
		private ITimeFactory $time,
		private \Psr\Log\LoggerInterface $logger,
	) {
	}

	/**
	 * The cron entry point: fire every owed reminder across all boards. For each
	 * candidate card, sends the at-due and/or day-before reminder to the union of
	 * its assignees and watchers, then stamps the marker(s) so it is not
	 * re-notified. A card that throws is logged and skipped.
	 *
	 * @return int number of reminder notifications' cards processed (a card that
	 *             fired at least one reminder counts once)
	 */
	public function runDueReminders(): int {
		$now = $this->time->getTime();
		$processed = 0;

		foreach ($this->cardMapper->findDueForReminder($now, self::MAX_PER_RUN) as $card) {
			try {
				if ($this->processCard($card, $now)) {
					$processed++;
				}
			} catch (\Throwable $e) {
				$this->logger->warning(
					'kanso: could not send due reminder for card ' . $card->getId(),
					['exception' => $e]
				);
			}
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
		}

		return $fired;
	}

	/**
	 * Fans the reminder out to the union of the card's assignees and its card-
	 * level watchers, deduplicated. Actor-less (a system event), so there is no
	 * one to skip. $daysBefore selects the fixed reminder (0 = at due, 1 = day
	 * before).
	 */
	private function notifyRecipients(Card $card, int $daysBefore): void {
		$recipients = array_unique(array_merge(
			$this->cardAssigneeMapper->findUserIdsByCard($card->getId()),
			$this->subscriptionMapper->findCardSubscriberUids($card->getId()),
		));

		foreach ($recipients as $uid) {
			$this->notificationService->notifyCardDue($card->getId(), $uid, $daysBefore);
		}
	}
}
