<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\ChangeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Sabre\VObject\Component\VCalendar;

/**
 * iCal / ICS read-only feed of a board's card due dates (#3541).
 *
 * A board opts in when a MANAGE user mints a per-board `ical_feed_token`
 * (64-char ISecureRandom, ~380 bits of entropy - unguessable, and the only thing
 * that addresses the feed publicly). Any calendar client that holds the token can
 * subscribe to a READ-ONLY VCALENDAR built by {@see self::renderFeed()}: one VEVENT
 * per card that HAS a due date, carrying only the card title, the due date and a
 * deep link back to the card. This is the CHEAP complement to full CalDAV
 * write-sync (#3534): it is deliberately read-only - no write-back, no VTODO, no
 * per-event auth beyond the token, no CalDAV backend.
 *
 * Security posture (mirrors {@see PublicShareService}, the app's other
 * unauthenticated read surface):
 *  - OFF by default; enabling/rotating/disabling all require MANAGE.
 *  - The token column is UNIQUE, so a token resolves to EXACTLY one board - it can
 *    never be pivoted to another board.
 *  - Disable clears the token and rotate replaces it, so a stale token stops
 *    resolving on the very next fetch (revocable + rotatable).
 *  - The feed exposes ONLY the current board's due cards, and only their title +
 *    due date + deep link. It never touches assignees, comments, descriptions,
 *    ACL/members, owner uids, reviews, activity or the webhook config, and never
 *    reaches cards on any other board (the query is board-scoped).
 *  - An unknown/disabled/rotated token raises DoesNotExistException, which the
 *    controller maps to a throttled 404 (no oracle beyond the throttle).
 */
class CalendarFeedService {
	private const TOKEN_LENGTH = 64;
	private const PRODID = '-//Kanso//Card due dates//EN';

	/**
	 * Hard ceiling on the VEVENTs one feed fetch may build. The feed is the app's
	 * only ANONYMOUS card read and it serialises every row it gets, so the work
	 * per fetch must be bounded by a constant rather than by however large a board
	 * grew. 2000 due cards is already far past any board a human curates (and past
	 * what a calendar client renders usefully), so in practice this never clips -
	 * it only stops a pathological board from turning one unauthenticated GET into
	 * an unbounded scan plus serialise.
	 */
	public const MAX_EVENTS = 2000;

	public function __construct(
		private BoardMapper $boardMapper,
		private CardMapper $cardMapper,
		private ChangeMapper $changeMapper,
		private PermissionService $permissionService,
		private ISecureRandom $secureRandom,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * The board's calendar-feed config for a MANAGE user: whether it's enabled and
	 * the feed URL (present only when enabled). The token itself IS included so the
	 * settings UI can render/copy the live link - it is board content the MANAGE
	 * user already controls. Requires MANAGE.
	 *
	 * @return array{enabled: bool, token: ?string, url: ?string}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function getConfig(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		return $this->configPayload($board);
	}

	/**
	 * Enables the feed (or rotates an existing one) by minting a fresh token, and
	 * returns the new config incl. the token + URL. Requires MANAGE. Any
	 * previously-issued feed URL stops working immediately.
	 *
	 * @return array{enabled: bool, token: ?string, url: ?string}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function enable(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);

		$board->setIcalFeedToken($this->secureRandom->generate(self::TOKEN_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC));
		$this->boardMapper->update($board);

		return $this->configPayload($board);
	}

	/**
	 * Disables the feed by clearing the token. Requires MANAGE. Idempotent - a
	 * board with no feed stays that way. The old token 404s from now on.
	 *
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 */
	public function disable(int $boardId, string $actorUid): void {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);
		if ($board->getIcalFeedToken() !== null) {
			$board->setIcalFeedToken(null);
			$this->boardMapper->update($board);
		}
	}

	/**
	 * The READ-ONLY VCALENDAR body a token points at, serialised as an ICS string.
	 * This is the ONLY method that runs without a session, so it is deliberately
	 * conservative: one VEVENT per card WITH a due date, carrying title + due +
	 * deep link and nothing else. It never reads people, comments, descriptions,
	 * ACL, reviews, activity or webhook data. Reflects the board's CURRENT due
	 * cards on every fetch.
	 *
	 * @throws DoesNotExistException if the token is unknown or disabled
	 *
	 * @psalm-suppress UndefinedMagicPropertyAssignment VCalendar exposes its
	 *  properties (PRODID, ...) through Sabre\VObject's magic __set, which psalm
	 *  can't see; the RecurrenceService uses the same library the same way.
	 */
	public function renderFeed(string $token): string {
		$board = $this->resolveFeedBoard($token);

		$boardId = (int)$board->getId();
		$boardTitle = $board->getTitle() ?? 'Kanso board';

		$calendar = new VCalendar();
		$calendar->PRODID = self::PRODID;
		// A calendar-subscription client shows this as the calendar name.
		$calendar->add('X-WR-CALNAME', $boardTitle);

		foreach ($this->cardMapper->findWithDuedateByBoard($boardId, self::MAX_EVENTS) as $card) {
			/** @var Card $card */
			$due = $card->getDuedate();
			if ($due === null) {
				continue;
			}
			$this->addEvent($calendar, $board, $card, $due);
		}

		return $calendar->serialize();
	}

	/**
	 * The feed's validator for a token: the board's latest `kanso_changes` id, the
	 * SAME value the board read uses as its ETag (perf bet #4). Every mutation that
	 * could change a VEVENT - a card's title, due date, visibility, archival or
	 * deletion - writes a change row, so the id moves whenever the ICS body could.
	 *
	 * Calendar clients poll on a fixed schedule (Thunderbird ~30 min, iOS ~15 min,
	 * DAVx5 configurable) and send back `If-None-Match`, so answering the
	 * unchanged-board case with a 304 turns the routine poll into two indexed
	 * lookups instead of a card scan plus a VObject serialise. That is what makes
	 * this endpoint cheap under a fleet of subscribers - a per-IP rate limit could
	 * not, since a whole NAT'd office shares one address.
	 *
	 * @throws DoesNotExistException if the token is unknown or disabled - same
	 *                               failure shape as {@see self::renderFeed()}, so the conditional request
	 *                               leaks no more than the plain one
	 */
	public function feedEtag(string $token): string {
		$board = $this->resolveFeedBoard($token);
		return (string)$this->changeMapper->getLatestChangeId((int)$board->getId());
	}

	/**
	 * Resolves a feed token to its board, or throws. Shared by the ETag probe and
	 * the render so both accept and reject exactly the same tokens.
	 *
	 * @throws DoesNotExistException if the token is unknown or disabled
	 */
	private function resolveFeedBoard(string $token): Board {
		$board = $this->boardMapper->findByIcalFeedToken($token);

		// Defence in depth: findByIcalFeedToken already excludes deleted boards and
		// empty tokens, but re-assert the token is really set (never serve a board
		// whose token was concurrently cleared).
		if (($board->getIcalFeedToken() ?? '') === '') {
			throw new DoesNotExistException('Calendar feed is disabled');
		}

		return $board;
	}

	/**
	 * @psalm-suppress UndefinedMethod Sabre\VObject\Component::add() is a real
	 *  method on the concrete VEVENT/VCalendar components but is typed on the
	 *  abstract Node in the shipped stubs, so psalm can't resolve it here.
	 */
	private function addEvent(VCalendar $calendar, Board $board, Card $card, \DateTime $due): void {
		$cardId = (int)$card->getId();
		$event = $calendar->add('VEVENT', [
			// Stable per card, so a client updates the same event across fetches
			// instead of duplicating it. Scoped to the card + app + board.
			'UID' => 'kanso-card-' . $cardId . '@' . $board->getId(),
			'SUMMARY' => $card->getTitle() ?? ('#' . $cardId),
		]);

		if ($card->getAllDay() ?? false) {
			// All-day due (#3520): a DATE-valued DTSTART with no time. The VALUE=DATE
			// parameter makes Sabre emit `DTSTART;VALUE=DATE:YYYYMMDD` (date only), so
			// the timezone is irrelevant - the client shows it on that calendar day.
			$event->add('DTSTART', $due, ['VALUE' => 'DATE']);
		} else {
			// A timed due date: a single point in time. Normalise to UTC first so the
			// emitted DTSTART is an unambiguous `...Z` instant regardless of how the
			// DB layer hydrated the DATETIME - a subscriber must never see a shifted
			// hour. DTEND == DTSTART keeps the event zero-length (a "due at" marker,
			// not a busy block).
			$utcDue = (clone $due)->setTimezone(new \DateTimeZone('UTC'));
			$event->add('DTSTART', $utcDue);
			$event->add('DTEND', $utcDue);
		}

		$link = $this->cardUrl($card);
		$event->add('URL', $link);
		$event->add('DESCRIPTION', $link);
	}

	/**
	 * @return array{enabled: bool, token: ?string, url: ?string}
	 */
	private function configPayload(Board $board): array {
		$token = $board->getIcalFeedToken();
		$enabled = ($token ?? '') !== '';
		return [
			'enabled' => $enabled,
			'token' => $enabled ? $token : null,
			'url' => $enabled ? $this->feedUrl((string)$token) : null,
		];
	}

	private function feedUrl(string $token): string {
		return $this->urlGenerator->linkToRouteAbsolute('kanso.calendarFeed.feed', ['token' => $token]);
	}

	/**
	 * The deep link back to a card via the fragment-free SERVER route (#3744).
	 * Calendar clients open these links cold (no session), so the target must
	 * survive the login round-trip - a hash route's fragment would not.
	 */
	private function cardUrl(Card $card): string {
		return $this->urlGenerator->linkToRouteAbsolute('kanso.deepLink.card', ['id' => (int)$card->getId()]);
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
