<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Activity\Provider;
use OCP\Activity\IManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Best-effort bridge from Kanso's service-layer mutations to the Nextcloud
 * Activity stream. A deliberately small counterpart to {@see NotificationService}
 * (targeted bell) and {@see ChangeNotifier} (realtime push): it publishes ONE
 * board-scoped Activity event per recipient for the four coarse, user-facing
 * milestones - card created / moved / done, and board shared - and stays silent
 * on everything else (field edits, system/cron sweeps).
 *
 * Publishing is strictly best-effort and never throws into the mutation that
 * already committed: the Activity app may not be installed (its IManager is then
 * absent from the container - the constructor takes a nullable manager), and any
 * publish() error is swallowed and logged, exactly like the notify_push emit in
 * {@see ChangeNotifier::pushBoardChanged()}. Users opt in/out entirely through
 * the Activity app's own settings (see {@see \OCA\Kanso\Activity\Setting}); this
 * class builds no preference UI of its own.
 */
class ActivityPublisher {
	public function __construct(
		private ?IManager $activityManager,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Publishes a "card created" event to everyone with access to the board.
	 *
	 * @param list<string> $recipients uids with board access (from ChangeNotifier)
	 */
	public function cardCreated(int $boardId, int $cardId, string $cardTitle, string $actor, array $recipients): void {
		$this->publish(Provider::SUBJECT_CARD_CREATED, $boardId, $cardId, $cardTitle, $actor, $recipients);
	}

	/**
	 * Publishes a "card moved" event to everyone with access to the board.
	 *
	 * @param list<string> $recipients uids with board access (from ChangeNotifier)
	 */
	public function cardMoved(int $boardId, int $cardId, string $cardTitle, string $actor, array $recipients): void {
		$this->publish(Provider::SUBJECT_CARD_MOVED, $boardId, $cardId, $cardTitle, $actor, $recipients);
	}

	/**
	 * Publishes a "card done" event to everyone with access to the board.
	 *
	 * @param list<string> $recipients uids with board access (from ChangeNotifier)
	 */
	public function cardDone(int $boardId, int $cardId, string $cardTitle, string $actor, array $recipients): void {
		$this->publish(Provider::SUBJECT_CARD_DONE, $boardId, $cardId, $cardTitle, $actor, $recipients);
	}

	/**
	 * Publishes a "board shared" event to everyone with access to the board.
	 * The object is the board itself (there is no single card involved).
	 *
	 * @param list<string> $recipients uids with board access (from ChangeNotifier)
	 */
	public function boardShared(int $boardId, string $boardTitle, string $actor, array $recipients): void {
		$this->publish(Provider::SUBJECT_BOARD_SHARED, $boardId, $boardId, $boardTitle, $actor, $recipients, true);
	}

	/**
	 * Builds and fans out one Activity event per recipient. Never throws - the
	 * mutation that triggered this has already committed.
	 *
	 * @param list<string> $recipients
	 */
	private function publish(string $subject, int $boardId, int $objectId, string $objectName, string $actor, array $recipients, bool $boardObject = false): void {
		if ($this->activityManager === null || $recipients === []) {
			// Activity app not installed, or nobody to notify - a silent no-op.
			return;
		}

		try {
			$objectType = $boardObject ? Provider::OBJECT_BOARD : Provider::OBJECT_CARD;
			$cardId = $boardObject ? 0 : $objectId;
			$link = $boardObject
				? $this->boardLink($boardId)
				: $this->cardLink($objectId);

			foreach ($recipients as $uid) {
				$event = $this->activityManager->generateEvent();
				$event->setApp('kanso')
					->setType(Provider::TYPE_KANSO)
					->setAuthor($actor)
					->setAffectedUser($uid)
					->setTimestamp(time())
					->setObject($objectType, $objectId, $objectName)
					->setSubject($subject, [
						'actor' => $actor,
						'boardId' => $boardId,
						'cardId' => $cardId,
						'name' => $objectName,
					])
					->setLink($link);
				$this->activityManager->publish($event);
			}
		} catch (\Throwable $e) {
			// Activity is a non-critical side effect - never break the mutation.
			// \Throwable also covers the Activity API's IncompleteActivityException.
			$this->logger->debug(
				'kanso: failed to publish activity for board ' . $boardId,
				['exception' => $e]
			);
		}
	}

	/**
	 * Card links use the fragment-free SERVER route (#3744): hash routes lose
	 * their fragment on a login round-trip, and Activity emails reach people
	 * who are not logged in.
	 */
	private function cardLink(int $cardId): string {
		return $this->urlGenerator->linkToRouteAbsolute('kanso.deepLink.card', ['id' => $cardId]);
	}

	private function boardLink(int $boardId): string {
		return $this->urlGenerator->linkToRouteAbsolute('kanso.page.index')
			. '#/board/' . $boardId;
	}
}
