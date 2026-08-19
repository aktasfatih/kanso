<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\CalDav;

use OCA\DAV\CalDAV\Integration\ExternalCalendar;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Service\CardCalendarService;
use Sabre\CalDAV\CalendarQueryValidator;
use Sabre\CalDAV\Plugin;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\PropPatch;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * One board, presented to the CalDAV stack as a read-only calendar of VTODOs
 * (#3534 / issue #49). The children are {@see CardCalendarObject}s, one per due
 * card the syncing user may see. Every write is rejected: this is a display /
 * one-way-sync surface, not an editor. All data + access decisions come from
 * {@see CardCalendarService}.
 */
class CardCalendar extends ExternalCalendar {
	/** @var Card[]|null the board's due cards, loaded once per request. VTODO
	 *  serialisation happens per object only when it is actually read, so a
	 *  calendar-home listing never serialises anything. */
	private ?array $cards = null;

	public function __construct(
		private CardCalendarService $service,
		private Board $board,
		private string $principalUri,
	) {
		parent::__construct(CardCalendarService::APP_ID, $service->calendarUri($board));
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getOwner() {
		return $this->principalUri;
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getGroup() {
		return null;
	}

	/**
	 * @inheritDoc
	 *
	 * Read-only for the owner: the read privileges, no write. That is what makes
	 * a client (DAVx5, Calendar, Tasks) treat the calendar as non-editable.
	 */
	#[\Override]
	public function getACL() {
		return [
			[
				'privilege' => '{DAV:}read',
				'principal' => $this->getOwner(),
				'protected' => true,
			],
			[
				'privilege' => '{' . Plugin::NS_CALDAV . '}read-free-busy',
				'principal' => '{DAV:}authenticated',
				'protected' => true,
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function setACL(array $acl) {
		throw new Forbidden('Setting ACL is not supported on this calendar');
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getSupportedPrivilegeSet() {
		return null;
	}

	/**
	 * @inheritDoc
	 *
	 * Validates each child object against the calendar-query filters and returns
	 * the names that match - the standard external-calendar approach. Sync is not
	 * a hot path, so re-parsing each VTODO per query is acceptable.
	 *
	 * @return string[]
	 */
	#[\Override]
	public function calendarQuery(array $filters) {
		$validator = new CalendarQueryValidator();
		$matches = [];
		foreach ($this->cards() as $card) {
			$vObject = Reader::read($this->service->serializeCard($this->board, $card));
			if ($vObject instanceof VCalendar && $validator->validate($vObject, $filters)) {
				$matches[] = $this->service->objectName($card);
			}
			$vObject->destroy();
		}
		return $matches;
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getChild($name) {
		$card = $this->cardByName($name);
		if ($card === null) {
			throw new NotFound('Card ' . $name . ' not found on this calendar');
		}
		return $this->object($card);
	}

	/**
	 * @inheritDoc
	 *
	 * @return CardCalendarObject[]
	 */
	#[\Override]
	public function getChildren() {
		return array_map(fn (Card $card): CardCalendarObject => $this->object($card), $this->cards());
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function childExists($name) {
		return $this->cardByName($name) !== null;
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function createFile($name, $data = null) {
		throw new Forbidden('This calendar is read-only');
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function delete() {
		throw new Forbidden('This calendar is read-only');
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getLastModified() {
		$latest = 0;
		foreach ($this->cards() as $card) {
			$latest = max($latest, $this->service->lastModified($card));
		}
		if ($latest > 0) {
			return $latest;
		}
		return (int)$this->board->getLastModified();
	}

	/**
	 * @inheritDoc
	 *
	 * Read-only: silently accept nothing. The PropPatch stays unhandled, which
	 * the DAV server reports back as a 403 for each attempted property change.
	 */
	#[\Override]
	public function propPatch(PropPatch $propPatch) {
	}

	/**
	 * @inheritDoc
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function getProperties($properties) {
		$props = [
			'{DAV:}displayname' => $this->service->displayName($this->board),
			'{' . Plugin::NS_CALDAV . '}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VTODO']),
		];
		$color = $this->service->calendarColor($this->board);
		if ($color !== null) {
			$props['{http://apple.com/ns/ical/}calendar-color'] = $color;
		}
		return $props;
	}

	/**
	 * The board's due cards, loaded once per request. A non-user principal
	 * (should never reach here) yields nothing.
	 *
	 * @return Card[]
	 */
	private function cards(): array {
		if ($this->cards !== null) {
			return $this->cards;
		}
		$uid = $this->service->principalUid($this->principalUri);
		$this->cards = $uid === null ? [] : $this->service->dueCards($this->board, $uid);
		return $this->cards;
	}

	private function cardByName(string $name): ?Card {
		$cardId = $this->service->cardIdFromObjectName($name);
		if ($cardId === null) {
			return null;
		}
		foreach ($this->cards() as $card) {
			if ((int)$card->getId() === $cardId) {
				return $card;
			}
		}
		return null;
	}

	/** Serialises one due card into a read-only VTODO object. */
	private function object(Card $card): CardCalendarObject {
		return new CardCalendarObject(
			$this->service->objectName($card),
			$this->service->serializeCard($this->board, $card),
			$this->service->etag($card),
			$this->service->lastModified($card),
		);
	}
}
