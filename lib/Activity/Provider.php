<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Activity;

use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * Renders Kanso's Nextcloud Activity-stream events. Registered via the app's
 * info.xml `<activity>` block. Events are produced at the service layer by
 * {@see \OCA\Kanso\Service\ActivityPublisher} (driven from the same
 * {@see \OCA\Kanso\Service\ChangeNotifier} choke point that writes the change
 * log), each carrying a subject key + parameters; parse() resolves them into a
 * localized, linked, rich message at display time - mirroring how
 * {@see \OCA\Kanso\Notification\Notifier} handles bell notifications.
 *
 * Only four coarse, user-facing milestones are surfaced: a card created, moved
 * or marked done, and a board shared. Everything else stays out of the stream.
 */
class Provider implements IProvider {
	/** The single Activity type Kanso registers - kept small on purpose. */
	public const TYPE_KANSO = 'kanso';

	public const SUBJECT_CARD_CREATED = 'card_created';
	public const SUBJECT_CARD_MOVED = 'card_moved';
	public const SUBJECT_CARD_DONE = 'card_done';
	public const SUBJECT_BOARD_SHARED = 'board_shared';

	public const OBJECT_CARD = 'card';
	public const OBJECT_BOARD = 'board';

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private IUserManager $userManager,
	) {
	}

	/**
	 * @param string $language
	 * @param IEvent $event
	 * @param IEvent|null $previousEvent
	 * @return IEvent
	 * @throws UnknownActivityException if the event is not Kanso's or is an
	 *                                  unknown subject. UnknownActivityException
	 *                                  extends \InvalidArgumentException, so the
	 *                                  short-circuit is the NC 30+ preferred form
	 *                                  and still an \InvalidArgumentException.
	 */
	#[\Override]
	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== 'kanso') {
			// Not our activity - the manager will try the next provider.
			throw new UnknownActivityException();
		}

		$l = $this->l10nFactory->get('kanso', $language);
		$params = $event->getSubjectParameters();
		$actorUid = (string)($params['actor'] ?? '');
		$name = (string)($params['name'] ?? '');

		$actor = $this->userManager->get($actorUid);
		$actorName = $actor !== null ? $actor->getDisplayName() : $actorUid;

		[$plain, $rich] = match ($event->getSubject()) {
			self::SUBJECT_CARD_CREATED
				=> [$l->t('%1$s created %2$s', [$actorName, $name]), $l->t('{actor} created {object}')],
			self::SUBJECT_CARD_MOVED
				=> [$l->t('%1$s moved %2$s', [$actorName, $name]), $l->t('{actor} moved {object}')],
			self::SUBJECT_CARD_DONE
				=> [$l->t('%1$s marked %2$s as done', [$actorName, $name]), $l->t('{actor} marked {object} as done')],
			self::SUBJECT_BOARD_SHARED
				=> [$l->t('%1$s shared %2$s with you', [$actorName, $name]), $l->t('{actor} shared {object} with you')],
			// Any other subject is not one of ours - decline it.
			default => throw new UnknownActivityException(),
		};

		$event
			->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('kanso', 'app.svg')))
			->setParsedSubject($plain)
			->setRichSubject(
				$rich,
				[
					'actor' => [
						'type' => 'user',
						'id' => $actorUid,
						'name' => $actorName,
					],
					'object' => [
						'type' => 'highlight',
						'id' => (string)$event->getObjectId(),
						'name' => $name,
					],
				]
			);

		// The absolute deep link set by the publisher (ActivityPublisher) is left
		// as-is - it already points at the card/board inside the SPA.

		return $event;
	}
}
