<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\CalDav;

use OCA\DAV\CalDAV\Integration\ExternalCalendar;
use OCA\DAV\CalDAV\Integration\ICalendarProvider;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Service\CardCalendarService;

/**
 * Exposes Kanso boards to the CalDAV stack as read-only VTODO calendars (#3534 /
 * issue #49). Registered via `appinfo/info.xml` `<sabre><calendar-plugins>`; the
 * DAV app instantiates it through the app container, so it is dependency-injected
 * like any service. The DAV app's `CalendarHome` calls these methods when a
 * client (Nextcloud Calendar, DAVx5, Tasks apps) lists or opens a calendar.
 *
 * Every decision is delegated to {@see CardCalendarService} - this class only
 * bridges the DAV integration interface to that Sabre-free core.
 */
class CalendarProvider implements ICalendarProvider {
	public function __construct(
		private CardCalendarService $service,
	) {
	}

	#[\Override]
	public function getAppId(): string {
		return CardCalendarService::APP_ID;
	}

	/**
	 * @inheritDoc
	 * @return ExternalCalendar[]
	 */
	#[\Override]
	public function fetchAllForCalendarHome(string $principalUri): array {
		return array_map(
			fn (Board $board): ExternalCalendar => new CardCalendar($this->service, $board, $principalUri),
			$this->service->boardsForPrincipal($principalUri),
		);
	}

	#[\Override]
	public function hasCalendarInCalendarHome(string $principalUri, string $calendarUri): bool {
		return $this->service->boardForPrincipal($principalUri, $calendarUri) !== null;
	}

	#[\Override]
	public function getCalendarInCalendarHome(string $principalUri, string $calendarUri): ?ExternalCalendar {
		$board = $this->service->boardForPrincipal($principalUri, $calendarUri);
		if ($board === null) {
			return null;
		}
		return new CardCalendar($this->service, $board, $principalUri);
	}
}
