<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Notification;

use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Service\NotificationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders Kanso's Nextcloud notifications for the notification centre / bell.
 * Registered via {@see \OCA\Kanso\AppInfo\Application::register}. Each stored
 * notification carries only a subject key + parameters (see
 * {@see NotificationService}); prepare() resolves them into a localized,
 * linked, rich message at display time.
 */
class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private IUserManager $userManager,
		private CardMapper $cardMapper,
	) {
	}

	#[\Override]
	public function getID(): string {
		return 'kanso';
	}

	#[\Override]
	public function getName(): string {
		return 'Kanso';
	}

	/**
	 * @throws UnknownNotificationException if the notification is not ours or its card is gone
	 */
	#[\Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'kanso') {
			// Not our notification — the manager will try the next notifier.
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get('kanso', $languageCode);
		$subject = $notification->getSubject();
		if ($subject !== NotificationService::SUBJECT_CARD_ASSIGNED
			&& $subject !== NotificationService::SUBJECT_CARD_COMMENT
			&& $subject !== NotificationService::SUBJECT_CARD_REVIEW_REQUESTED) {
			throw new UnknownNotificationException();
		}

		$params = $notification->getSubjectParameters();
		$actorUid = (string)($params['actor'] ?? '');
		$cardId = (int)$notification->getObjectId();

		try {
			$card = $this->cardMapper->find($cardId);
		} catch (DoesNotExistException) {
			// The card was purged after the notification was queued.
			throw new UnknownNotificationException();
		}

		$actor = $this->userManager->get($actorUid);
		$actorName = $actor !== null ? $actor->getDisplayName() : $actorUid;
		$cardTitle = (string)$card->getTitle();

		[$plain, $rich] = match ($subject) {
			NotificationService::SUBJECT_CARD_ASSIGNED
				=> [$l->t('%1$s assigned you to %2$s', [$actorName, $cardTitle]), $l->t('{actor} assigned you to {card}')],
			NotificationService::SUBJECT_CARD_REVIEW_REQUESTED
				=> [$l->t('%1$s requested your review on %2$s', [$actorName, $cardTitle]), $l->t('{actor} requested your review on {card}')],
			default
			=> [$l->t('%1$s commented on %2$s', [$actorName, $cardTitle]), $l->t('{actor} commented on {card}')],
		};

		$notification
			->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('kanso', 'app.svg')))
			->setLink($this->cardLink($card->getBoardId(), $cardId))
			->setParsedSubject($plain)
			->setRichSubject(
				$rich,
				[
					'actor' => ['type' => 'user', 'id' => $actorUid, 'name' => $actorName],
					'card' => ['type' => 'highlight', 'id' => (string)$cardId, 'name' => $cardTitle],
				]
			);

		return $notification;
	}

	/**
	 * Deep link to the card inside the board (the app is a hash-routed SPA).
	 */
	private function cardLink(int $boardId, int $cardId): string {
		return $this->urlGenerator->linkToRouteAbsolute('kanso.page.index')
			. '#/board/' . $boardId . '/card/' . $cardId;
	}
}
