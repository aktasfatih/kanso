<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\NotAMemberException;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use Sabre\VObject\Component\VCalendar;

/**
 * The read-only CalDAV VTODO surface (#3534 / issue #49): every board a user can
 * access is exposed to their CalDAV principal as a calendar of VTODOs, one per
 * card that HAS a due date. Discoverable by Nextcloud Calendar and DAVx5 exactly
 * the way Deck's board calendars are (via the DAV app's
 * {@see \OCA\DAV\CalDAV\Integration\ICalendarProvider} extension point, wired in
 * `appinfo/info.xml`).
 *
 * This class is the Sabre-free CORE: it resolves boards for a principal, applies
 * the viewer's card visibility, and serialises a card to a VTODO. The thin
 * `OCA\Kanso\CalDav\*` adapters (which extend the DAV/Sabre base classes that
 * only exist at runtime) delegate every decision here, so the logic stays unit
 * testable without the server on the include path.
 *
 * Security posture:
 *  - READ-ONLY. The adapters reject every write (PUT/DELETE/MKCALENDAR/PROPPATCH);
 *    nothing here mutates a card. Two-way sync is a separate, larger feature.
 *  - Authenticated + per-person. Unlike the anonymous token ICS feed
 *    ({@see CalendarFeedService}), the reader is the logged-in CalDAV principal:
 *    {@see BoardAccess::contextFor} gates board membership and
 *    {@see CardMapper::findDuedateSummariesByBoard} applies that viewer's
 *    card-visibility, so a member sees exactly the due cards they see on the
 *    board - never another board's, never a card hidden from them.
 */
class CardCalendarService {
	/** Matches `appinfo/info.xml` `<sabre><calendar-plugins>` registration. */
	public const APP_ID = 'kanso';

	/** The per-board calendar uri: `board-<id>` (the DAV app prefixes it with
	 *  `app-generated--kanso--`). Board id keeps it stable across renames. */
	private const CALENDAR_URI_PREFIX = 'board-';

	/** The per-card object name inside a board calendar: `kanso-card-<id>.ics`. */
	private const OBJECT_PREFIX = 'kanso-card-';
	private const OBJECT_SUFFIX = '.ics';

	private const PRODID = '-//Kanso//Card calendar//EN';

	public function __construct(
		private BoardMapper $boardMapper,
		private CardMapper $cardMapper,
		private BoardAccess $boardAccess,
		private PermissionService $permissionService,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * The uid behind a CalDAV principal, or null if it is not a personal user
	 * principal (`principals/users/<uid>`). Group/system/calendar-resource
	 * principals get no Kanso calendars - the feature is per-person.
	 */
	public function principalUid(string $principalUri): ?string {
		$prefix = 'principals/users/';
		if (!str_starts_with($principalUri, $prefix)) {
			return null;
		}
		$uid = substr($principalUri, \strlen($prefix));
		return $uid === '' ? null : $uid;
	}

	/**
	 * Every board the principal may see, one calendar each. Archived boards are
	 * skipped (the user shelved them - keep them out of the calendar list);
	 * deleted boards never reach here. Empty for a non-user principal.
	 *
	 * @return Board[]
	 */
	public function boardsForPrincipal(string $principalUri): array {
		$uid = $this->principalUid($principalUri);
		if ($uid === null) {
			return [];
		}

		$boards = $this->boardMapper->findAllForUser(
			$uid,
			$this->permissionService->getUserGroupIds($uid),
		);
		return array_values(array_filter(
			$boards,
			static fn (Board $board): bool => !$board->getArchived(),
		));
	}

	/**
	 * The board a `board-<id>` calendar uri resolves to FOR THIS principal, or
	 * null when the uri is malformed, the board is gone/archived, or the user is
	 * not a member. The membership check is the access gate for every child read.
	 */
	public function boardForPrincipal(string $principalUri, string $calendarUri): ?Board {
		$uid = $this->principalUid($principalUri);
		$boardId = $this->boardIdFromCalendarUri($calendarUri);
		if ($uid === null || $boardId === null) {
			return null;
		}

		try {
			$board = $this->boardMapper->find($boardId);
		} catch (DoesNotExistException) {
			return null;
		}
		if ($board->getDeletedAt() > 0 || $board->getArchived()) {
			return null;
		}

		try {
			// Throws for a non-member - the per-board access gate.
			$this->boardAccess->contextFor($board, $uid);
		} catch (NotAMemberException) {
			return null;
		}
		return $board;
	}

	/**
	 * The board's due cards visible to $uid, as VTODO source rows. The caller has
	 * already proven membership (via {@see self::boardForPrincipal}); this mints
	 * the viewer context again to scope card visibility.
	 *
	 * @return Card[]
	 * @throws NotAMemberException if $uid is not a member (defence in depth)
	 */
	public function dueCards(Board $board, string $uid): array {
		$viewer = $this->boardAccess->contextFor($board, $uid);
		return $this->cardMapper->findDuedateSummariesByBoard((int)$board->getId(), $viewer);
	}

	public function calendarUri(Board $board): string {
		return self::CALENDAR_URI_PREFIX . (int)$board->getId();
	}

	/** Parses `board-<id>` back to the board id, or null if it does not match. */
	public function boardIdFromCalendarUri(string $calendarUri): ?int {
		if (!str_starts_with($calendarUri, self::CALENDAR_URI_PREFIX)) {
			return null;
		}
		$rest = substr($calendarUri, \strlen(self::CALENDAR_URI_PREFIX));
		if ($rest === '' || !ctype_digit($rest)) {
			return null;
		}
		return (int)$rest;
	}

	public function objectName(Card $card): string {
		return self::OBJECT_PREFIX . (int)$card->getId() . self::OBJECT_SUFFIX;
	}

	/** Parses `kanso-card-<id>.ics` back to the card id, or null if it does not match. */
	public function cardIdFromObjectName(string $name): ?int {
		if (!str_starts_with($name, self::OBJECT_PREFIX) || !str_ends_with($name, self::OBJECT_SUFFIX)) {
			return null;
		}
		$mid = substr($name, \strlen(self::OBJECT_PREFIX), -\strlen(self::OBJECT_SUFFIX));
		if ($mid === '' || !ctype_digit($mid)) {
			return null;
		}
		return (int)$mid;
	}

	/** The calendar's display name (the board title) for CalDAV clients. */
	public function displayName(Board $board): string {
		return $board->getTitle() ?? 'Kanso board';
	}

	/** The board colour as a CSS hex (`#rrggbb`) for calendar-color, or null. */
	public function calendarColor(Board $board): ?string {
		$color = $board->getColor();
		if ($color === null || $color === '') {
			return null;
		}
		return str_starts_with($color, '#') ? $color : ('#' . $color);
	}

	/**
	 * The card's ETag - changes whenever the card changes. `last_modified` is
	 * bumped by every card-mutating call (it backs delta-sync), so it is a
	 * sufficient version stamp for a client's conditional sync.
	 */
	public function etag(Card $card): string {
		return '"kanso-' . (int)$card->getId() . '-' . $this->lastModified($card) . '"';
	}

	/** The card's last-modified unix time (falls back to created, then 0). */
	public function lastModified(Card $card): int {
		$modified = (int)$card->getLastModified();
		if ($modified > 0) {
			return $modified;
		}
		return (int)$card->getCreatedAt();
	}

	/**
	 * Serialises one card to a single-VTODO VCALENDAR string.
	 *
	 * @psalm-suppress UndefinedMagicPropertyAssignment VCalendar exposes PRODID
	 *  via Sabre\VObject's magic __set, which psalm can't see (same use as
	 *  {@see CalendarFeedService} and {@see RecurrenceService}).
	 */
	public function serializeCard(Board $board, Card $card): string {
		$calendar = new VCalendar();
		$calendar->PRODID = self::PRODID;
		$this->addTodo($calendar, $board, $card);
		return $calendar->serialize();
	}

	/**
	 * @psalm-suppress UndefinedMethod Sabre\VObject\Component::add() is a real
	 *  method on the concrete VTODO/VCalendar components but is typed on the
	 *  abstract Node in the shipped stubs, so psalm can't resolve it here.
	 */
	private function addTodo(VCalendar $calendar, Board $board, Card $card): void {
		$cardId = (int)$card->getId();
		// Pin DTSTAMP to the card's own version stamp for a DETERMINISTIC body:
		// passing it in the create array means VObject does not auto-add its own
		// "now" DTSTAMP (which would both duplicate the property and churn the
		// serialisation on every fetch).
		$stamp = $this->utc($this->lastModified($card));
		$todo = $calendar->add('VTODO', [
			// Stable per card, so a client updates the same task across syncs
			// instead of duplicating it. Scoped to the card + app + board.
			'UID' => 'kanso-card-' . $cardId . '@board-' . $board->getId(),
			'SUMMARY' => $card->getTitle() ?? ('#' . $cardId),
			'DTSTAMP' => $stamp,
		]);

		$allDay = $card->getAllDay() ?? false;
		$this->addDate($todo, 'DUE', $card->getDuedate(), $allDay);
		$this->addDate($todo, 'DTSTART', $card->getStartDate(), $allDay);

		$doneAt = (int)$card->getDoneAt();
		if ($doneAt > 0) {
			$todo->add('STATUS', 'COMPLETED');
			$todo->add('PERCENT-COMPLETE', 100);
			$todo->add('COMPLETED', $this->utc($doneAt));
		} else {
			$todo->add('STATUS', 'NEEDS-ACTION');
		}

		$link = $this->cardUrl($card);
		$todo->add('URL', $link);
		$todo->add('DESCRIPTION', $link);

		$created = (int)$card->getCreatedAt();
		if ($created > 0) {
			$todo->add('CREATED', $this->utc($created));
		}
		$todo->add('LAST-MODIFIED', $stamp);
	}

	/**
	 * Adds a date-valued property, mirroring the ICS feed's all-day handling: an
	 * all-day card emits `NAME;VALUE=DATE:YYYYMMDD` (timezone-irrelevant); a timed
	 * card is normalised to an unambiguous UTC instant. No-op when $value is null.
	 *
	 * @psalm-suppress UndefinedMethod see {@see self::addTodo}.
	 */
	private function addDate(object $todo, string $name, ?\DateTime $value, bool $allDay): void {
		if ($value === null) {
			return;
		}
		if ($allDay) {
			$todo->add($name, $value, ['VALUE' => 'DATE']);
		} else {
			$todo->add($name, (clone $value)->setTimezone(new \DateTimeZone('UTC')));
		}
	}

	private function utc(int $timestamp): \DateTime {
		return (new \DateTime('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
	}

	/**
	 * The deep link back to a card via the fragment-free SERVER route (#3744),
	 * so a calendar client can open the card cold (surviving the login round-trip).
	 */
	private function cardUrl(Card $card): string {
		return $this->urlGenerator->linkToRouteAbsolute('kanso.deepLink.card', ['id' => (int)$card->getId()]);
	}
}
