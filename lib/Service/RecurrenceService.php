<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\RecurRule;
use OCA\Kanso\Db\RecurRuleMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property\ICalendar\Recur;
use Sabre\VObject\Recur\RRuleIterator;

/**
 * Recurring card rules: board automation that spawns a card on a schedule
 * (RFC 5545 RRULE). Rules are board-automation config (like labels and
 * auto-archive rules), so creating/editing them needs MANAGE and listing needs
 * READ. The schedule is expanded with sabre/vobject's {@see RRuleIterator},
 * anchored (its DTSTART) at the template card's Start date, else its due date,
 * else the rule's `createdAt` - see {@see self::anchorFor}. RFC 5545 puts
 * DTSTART itself in the recurrence set, so a template card dated in the FUTURE
 * first fires on that date to the minute, unfiltered by any BY* rule part; the
 * BY* parts shape every occurrence after it (see {@see self::firstFireFor}).
 * The next fire time is cached in `next_occurrence_at` so the cron scan is a
 * single indexed range query. The schedule is expanded as floating wall-clock
 * time (RFC 5545 / CalDAV) in the rule's IANA `timezone` (defaulting to the
 * owner's personal timezone, server default as fallback), so e.g. "daily at
 * 09:00" fires 09:00 local on both sides of a DST boundary. A delayed/downed
 * cron catches up on every MISSED occurrence - one card per occurrence -
 * bounded per run by {@see self::MAX_CATCHUP}; see {@see self::runDueRules}.
 *
 * Two modes (see {@see RecurRule} MODE_* constants):
 *   - CLONE: each occurrence creates a fresh card in the target stack, copying
 *     the template's title, description, labels and assignees;
 *   - RESET: each occurrence moves the template card itself back to the target
 *     stack and clears its done state (household-chore style).
 *
 * KANSO note vs the Deck-recurrence port this mirrors: Kanso's
 * {@see PermissionService} is actor-independent (no session setUserId), so the
 * rule's owner uid is passed explicitly to every CardService call; the spawned
 * card's ordering comes from SortKeyService via CardService (Kanso has no
 * `order` column); there is no host-app-installed guard; and spawn failures are
 * logged only (no notification manager yet).
 */
class RecurrenceService {
	/** Ten years, a sane upper bound for a due-date offset. */
	private const MAX_OFFSET_SECONDS = 315360000;

	/**
	 * Catch-up cap: the most occurrences a single rule may spawn in one cron
	 * run. A rule dormant for months (server down, rule re-enabled) must not
	 * flood a board with hundreds of cards in one pass - it stamps up to this
	 * many, logs that catch-up was truncated, and the remainder continue on the
	 * next run (the cursor is durable per occurrence). Kept modest: a daily rule
	 * catches up ~2 months of backlog per run, hourly ~2 days.
	 */
	public const MAX_CATCHUP = 50;

	/**
	 * The frequencies sabre's {@see RRuleIterator}::next() actually implements, and
	 * therefore the only ones this app will store. Lowercase, because that is the
	 * form next() switches on.
	 *
	 * The iterator's CONSTRUCTOR is wider than its next() is: it accepts all seven
	 * RFC 5545 frequencies, SECONDLY and MINUTELY included, and only next() reveals
	 * that two of them go nowhere. See {@see self::assertIterableRule}.
	 */
	private const ITERABLE_FREQUENCIES = ['hourly', 'daily', 'weekly', 'monthly', 'yearly'];

	/**
	 * The RRULE parts this app stores - an ALLOWLIST, so a part nobody here has
	 * reasoned about cannot reach {@see RRuleIterator} at all.
	 *
	 * It is a superset of what the recurrence editor emits (FREQ, INTERVAL,
	 * BYDAY, COUNT, UNTIL - see src/utils/rrule.js) widened by the parts a rule
	 * authored through the API, the MCP server or a board import legitimately
	 * carries: BYMONTH, BYMONTHDAY and WKST.
	 *
	 * The parts deliberately NOT on it - BYHOUR, BYMINUTE, BYSECOND, BYYEARDAY,
	 * BYWEEKNO, BYSETPOS - are the ones that reach sabre code paths whose loops
	 * cannot be bounded from the outside; see {@see self::assertIterableRule}.
	 */
	private const SUPPORTED_PARTS = [
		'FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'WKST', 'BYDAY', 'BYMONTH', 'BYMONTHDAY',
	];

	/** The RFC 5545 weekday tokens, for BYDAY and WKST. */
	private const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

	/**
	 * Ceiling on INTERVAL. "Every thousandth week/month/year" is already far past
	 * any board schedule, and an unbounded INTERVAL is arithmetic sabre hands
	 * straight to DateTimeImmutable::modify('+N months'), which stops behaving at
	 * absurd magnitudes.
	 */
	private const MAX_INTERVAL = 1000;

	/**
	 * Ceiling on COUNT. A card that respawns more than ten thousand times is not
	 * a schedule; keeping COUNT in the same sane envelope as INTERVAL also keeps
	 * {@see self::countLimitFor}'s durable tally meaningful.
	 */
	private const MAX_COUNT = 10000;

	/**
	 * The most occurrences a single {@see self::computeNextOccurrence} call may
	 * step through before it gives up and rejects the rule.
	 *
	 * This is the version-INDEPENDENT half of the guard, and the one that does not
	 * depend on anybody having correctly enumerated sabre's behaviour: whatever the
	 * rule, whatever the sabre release, one call does a bounded amount of work.
	 *
	 * Sizing. The walk that actually happens is {@see self::firstFireFor}'s: from
	 * the template card's anchor date up to now. The finest grain this app accepts
	 * is HOURLY, so 50000 steps covers an anchor 5.7 YEARS in the past; every
	 * coarser frequency covers far longer (DAILY ~137 years, WEEKLY ~958 years,
	 * MONTHLY ~4166 years). No real template card is anchored further back than
	 * that, and a rule already running spends a single step per call. Measured
	 * against the
	 * pinned sabre 4.x, a step costs 0.7-3.7us, so the cap bounds the worst case at
	 * roughly 0.2s of CPU instead of the unbounded spin it replaces.
	 */
	private const MAX_ADVANCE_STEPS = 50000;

	public function __construct(
		private RecurRuleMapper $ruleMapper,
		private CardMapper $cardMapper,
		private StackMapper $stackMapper,
		private BoardMapper $boardMapper,
		private CardLabelMapper $cardLabelMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private CardService $cardService,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private CardVisibilityGuard $visibilityGuard,
		private ITimeFactory $time,
		private IDBConnection $db,
		private IConfig $config,
		private \Psr\Log\LoggerInterface $logger,
	) {
	}

	// ---- RRULE expansion --------------------------------------------------

	/**
	 * First occurrence strictly after $afterTs (unix seconds), honoring any
	 * COUNT/UNTIL embedded in the RRULE. Returns 0 when the rule is exhausted
	 * (no further occurrence) - the caller treats 0 as "self-disable".
	 *
	 * The RRULE is anchored at $dtstartTs - the DTSTART callers get from
	 * {@see self::anchorFor} (the template card's Start date, else its due date,
	 * else the rule's creation time) - reinterpreted as a wall-clock time in
	 * $timezone, so occurrences are floating local times per RFC 5545 / CalDAV:
	 * "daily at 09:00" fires 09:00 local on both sides of a
	 * DST boundary and the concrete UTC instant shifts to keep the local hour
	 * stable. $timezone null falls back to the server default timezone (back-compat
	 * for rules created before the timezone column existed). We do NOT hand-roll
	 * DST math - sabre's {@see RRuleIterator} does it when anchored in a real zone.
	 *
	 * @throws InvalidInputException if the RRULE cannot be parsed, carries a part or
	 *                               value this app does not store, names a FREQ
	 *                               sabre cannot iterate
	 *                               ({@see self::assertIterableRule}), or needs more
	 *                               than {@see self::MAX_ADVANCE_STEPS} steps to
	 *                               reach the requested time
	 */
	public function computeNextOccurrence(string $rrule, int $afterTs, int $dtstartTs, ?string $timezone = null): int {
		// Reject a rule sabre cannot step BEFORE any iterator exists - see the
		// method doc.
		$this->assertIterableRule($rrule);

		$tz = $this->timezoneFor($timezone);
		// Anchor as a floating wall-clock time in the rule's zone: take the UTC
		// instant of $dtstartTs and re-interpret its calendar fields in $tz.
		$start = (new \DateTimeImmutable('@' . $dtstartTs))->setTimezone($tz);
		try {
			$iterator = new RRuleIterator($rrule, $start);
		} catch (\Throwable $e) {
			// sabre throws assorted exception types for malformed input;
			// normalize to the API's InvalidInputException. \Throwable, not
			// \Exception - see the fastForward() catch below for why.
			throw new InvalidInputException('Invalid recurrence rule');
		}

		// Advance to the first occurrence >= the target; asking for afterTs + 1
		// makes the result strictly after afterTs.
		//
		// This is sabre's own fastForward() - `while (valid() && currentDate < $dt)
		// next()` - re-implemented here for one reason: to put a HARD CEILING on the
		// number of steps. sabre's version is unbounded, so the cost of a single
		// call is whatever the rule says it is: an RRULE whose occurrence set is
		// empty walks to sabre's year-9999 stop one occurrence at a time, and an
		// ancient DTSTART (a template card dated 0001-01-01 is accepted by
		// DueDateParser) makes even a perfectly legal FREQ=HOURLY rule step ~17.7
		// MILLION legal occurrences to reach today. Neither is malformed; both are
		// remote CPU burn, reachable from card create/update, rule create/update,
		// board import and the cron.
		//
		// The cap is deliberately the version-independent half of this guard. The
		// allowlists in assertIterableRule() encode what a PARTICULAR sabre release
		// does with particular rule parts, and that knowledge goes stale - the bug
		// this replaces existed precisely because vobject 4 and 5 behave differently.
		// The cap needs no such knowledge: it bounds the work whatever the library
		// underneath decides to do between two calls to next().
		$target = (new \DateTimeImmutable('@' . ($afterTs + 1)))->setTimezone($tz);
		$overrun = false;
		try {
			$steps = 0;
			while ($iterator->valid() && $iterator->current() < $target) {
				if ($steps >= self::MAX_ADVANCE_STEPS) {
					$overrun = true;
					break;
				}
				$steps++;
				$iterator->next();
			}
		} catch (\Throwable $e) {
			// Some malformed rules only fail while iterating, and NOT always as an
			// \Exception - sabre can raise a bare \Error mid-walk. Catching only
			// \Exception would let that escape as a 500: crashing a board import,
			// turning an invalid-rule API call into a fatal instead of a 400, and
			// killing the cron pass on a rule stored before it was validated.
			// This no longer has to cover the frequencies sabre cannot step -
			// assertIterableRule() above rejects those before an iterator is
			// ever built - but it still earns its keep for every other rule that
			// only misbehaves while stepping.
			throw new InvalidInputException('Invalid recurrence rule');
		}
		if ($overrun) {
			// Not "malformed" - just more work than any real board schedule needs.
			// Same exception type as every other rejection so the controllers keep
			// turning it into a 400, ImportService keeps dropping the rule and
			// continuing, and advanceSchedule() keeps disabling a stored rule that
			// has drifted into this state instead of wedging the cron.
			throw new InvalidInputException('Recurrence rule needs too many steps to reach its next occurrence');
		}

		if (!$iterator->valid()) {
			return 0;
		}
		// vobject 5 typed current() as nullable (?DateTimeImmutable); the valid()
		// guard above already ensures a current occurrence, but psalm can't narrow
		// that, so coalesce defensively.
		$occurrence = $iterator->current();
		return $occurrence?->getTimestamp() ?? 0;
	}

	/**
	 * Rejects an RRULE whose FREQ is missing, or is one sabre accepts but cannot
	 * step, before {@see self::computeNextOccurrence} can hand it to an
	 * {@see RRuleIterator}. Only an explicit member of
	 * {@see self::ITERABLE_FREQUENCIES} gets through.
	 *
	 * Two distinct shapes wedge the iterator, and both end the same way - a
	 * `fastForward()` that never returns:
	 *
	 * 1. **No FREQ at all.** It is the one part RFC 5545 makes mandatory and the one
	 *    part sabre never checks for. `''`, `'COUNT=3'` and `'INTERVAL=2'` all
	 *    CONSTRUCT cleanly; the damage only shows while stepping, and what it looks
	 *    like depends on the sabre release:
	 *      - vobject 5 types the property as a string, so `switch ($this->frequency)`
	 *        hits a typed-but-uninitialized property and raises an \Error the catch
	 *        in computeNextOccurrence() turns into a clean 400;
	 *      - vobject 4 - what Nextcloud bundles in 3rdparty, and therefore what this
	 *        app actually runs against, since the release tarball ships no vendor/
	 *        (see scripts/build-release.sh) - declares `protected $frequency;`
	 *        UNTYPED, so it is simply null. No switch arm matches, the cursor never
	 *        advances, and there is nothing to throw.
	 * 2. **FREQ=SECONDLY / FREQ=MINUTELY.** These are valid RFC 5545 and the
	 *    constructor's own frequency whitelist accepts all seven values - but next()
	 *    only implements five. On the missing two it falls through without touching
	 *    currentDate, so the cursor stands still exactly as in case 1. Nothing is
	 *    thrown on either sabre line; a guard that merely checked "is FREQ present"
	 *    waved these straight through.
	 *
	 * In both cases fastForward() is an unbounded
	 * `while (valid() && current < target)`, and with a cursor that never moves (and
	 * no COUNT/UNTIL to end it) valid() stays true forever: the request wedges its
	 * worker until max_execution_time, and under the cron CLI (no time limit at all)
	 * it wedges the whole background job. So the catch cannot be the guard here - on
	 * the version that ships, control never reaches it. Refusing the rule up front is
	 * what actually holds, and it holds identically on both lines:
	 * Recur::stringToArray parses and upper-cases the parts the same way in 4 and 5.
	 *
	 * Rejecting SECONDLY/MINUTELY is also right on the merits: a card that respawns
	 * every second is meaningless for a board, and sabre could not iterate it anyway.
	 *
	 * composer.json pins sabre/vobject to the 4.x line for exactly this reason: a
	 * suite running 5.x agrees with a guard written against 5.x semantics and proves
	 * nothing about what users run.
	 *
	 * A present-but-nonsense FREQ (`FREQ=`, `FREQ=BOGUS`) is rejected here too, by
	 * the same allowlist, rather than being left to the iterator's constructor.
	 *
	 * ---
	 *
	 * FREQ is necessary but NOT sufficient, and this is the second half of the
	 * lesson. Every other rule part reached the iterator unvalidated, and sabre
	 * range-checks only some of them (BYYEARDAY, BYWEEKNO and BYMONTH are checked;
	 * BYHOUR, BYMINUTE, BYSECOND, BYMONTHDAY and BYSETPOS are stored verbatim). An
	 * out-of-range value on an unchecked part does not throw - it produces an
	 * occurrence set that can never be satisfied, and sabre's next() then spins
	 * looking for it:
	 *
	 *   - `FREQ=WEEKLY;BYHOUR=99` - nextWeekly() ends in
	 *     `while (byHour && !in_array(currentHour, recurrenceHours))`, format('G')
	 *     only ever yields 0-23, and nextWeekly() is the ONLY next*() with no
	 *     year-9999 escape. A true infinite loop; nothing is thrown. Verified:
	 *     99.8% CPU until killed.
	 *   - `FREQ=YEARLY;BYYEARDAY=1;BYDAY=2MO` - every part is in range, but
	 *     nextYearly()'s BYYEARDAY branch is a bare `while (true)` that indexes
	 *     `$this->dayMap[$byDay]` RAW (the weekly/monthly paths use
	 *     `substr($day, -2)` and so tolerate the ordinal; this one does not).
	 *     '2MO' is not a key, the offset is null, no candidate date ever matches
	 *     and the year counter climbs forever. Also verified at 99.8% CPU.
	 *   - `FREQ=DAILY;BYHOUR=99` and `FREQ=MONTHLY;BYMONTHDAY=32` are the same
	 *     defect with a floor under it: those next*() DO have the year-9999 escape,
	 *     so they terminate - after ~70M and ~95k iterations respectively.
	 *
	 * Note where those first two loops live: INSIDE a single next() call. That is
	 * why {@see self::MAX_ADVANCE_STEPS}, which bounds how many times we CALL
	 * next(), cannot save us from them and this allowlist is load-bearing rather
	 * than cosmetic - PHP cannot interrupt a loop inside a library call. The two
	 * guards cover different halves of the problem and neither is redundant:
	 * this one keeps the inputs that wedge a single step out of the iterator, the
	 * step cap bounds everything that merely takes too many steps.
	 *
	 * So the parts are an ALLOWLIST ({@see self::SUPPORTED_PARTS}), not a
	 * blocklist. BYHOUR/BYMINUTE/BYSECOND/BYYEARDAY/BYWEEKNO/BYSETPOS are simply
	 * not stored: the editor cannot author them (src/utils/rrule.js), and a rule
	 * that arrives with one from the API, the MCP server or an import is refused.
	 * The parts that ARE accepted cover every schedule this app offers - a weekly
	 * BYDAY, a monthly BYMONTHDAY or positional BYDAY, a yearly BYMONTH - and each
	 * is range-checked here, because the only reason `BYMONTHDAY=32` costs 0.18s
	 * instead of hanging is an escape hatch in sabre we would rather not depend on.
	 *
	 * @throws InvalidInputException if the RRULE is empty, unparseable, carries no
	 *                               FREQ, names a frequency sabre cannot step, or
	 *                               carries a part or value this app does not store
	 */
	private function assertIterableRule(string $rrule): void {
		if (trim($rrule) === '') {
			// The API's own default for an omitted rrule parameter, so this is the
			// likeliest way in - name it explicitly rather than leaning on the parse.
			throw new InvalidInputException('Invalid recurrence rule');
		}
		try {
			// stringToArray upper-cases every part name and value and splits a
			// comma-separated value into an array. Both sabre 4 and 5 do it
			// identically, so the guard reads the rule exactly as the iterator will.
			$parts = Recur::stringToArray($rrule);
		} catch (\Throwable $e) {
			// Unparseable garbage; the iterator would reject it too, just later.
			throw new InvalidInputException('Invalid recurrence rule');
		}

		$freq = $parts['FREQ'] ?? null;
		if (!is_string($freq) || !in_array(strtolower($freq), self::ITERABLE_FREQUENCIES, true)) {
			throw new InvalidInputException('Invalid recurrence rule');
		}

		foreach ($parts as $name => $value) {
			if (!in_array($name, self::SUPPORTED_PARTS, true)) {
				throw new InvalidInputException('Unsupported recurrence rule part: ' . $name);
			}
			switch ($name) {
				case 'FREQ':
					// Already checked above.
					break;
				case 'INTERVAL':
				case 'COUNT':
					// Single-valued parts: `COUNT=1,2` parses to an array, which sabre
					// would cast with (int) and read as 1.
					if (!is_string($value)) {
						throw new InvalidInputException('Invalid ' . $name . ' in the recurrence rule');
					}
					$this->assertNumericPart(
						$name,
						$value,
						1,
						$name === 'INTERVAL' ? self::MAX_INTERVAL : self::MAX_COUNT,
						false,
					);
					break;
				case 'BYMONTH':
					$this->assertNumericPart($name, $value, 1, 12, false);
					break;
				case 'BYMONTHDAY':
					// Negative is legal and useful: -1 is the last day of the month.
					$this->assertNumericPart($name, $value, 1, 31, true);
					break;
				case 'BYDAY':
					// Mirrors sabre's own BYDAY regex, including its narrower 1-5
					// ordinal (RFC 5545 allows 1-53, but there is no 6th Monday in a
					// month and sabre refuses it anyway). Checking it here means the
					// rejection happens before an iterator exists, with our message.
					foreach ((array)$value as $token) {
						if (!is_string($token)
							|| preg_match('#^[+-]?[1-5]?(' . implode('|', self::WEEKDAYS) . ')$#', $token) !== 1) {
							throw new InvalidInputException('Invalid BYDAY in the recurrence rule');
						}
					}
					break;
				case 'WKST':
					// sabre range-checks nothing here and then uses the value as an
					// array key in two places; an unknown token yields null offsets.
					if (!is_string($value) || !in_array($value, self::WEEKDAYS, true)) {
						throw new InvalidInputException('Invalid WKST in the recurrence rule');
					}
					break;
				case 'UNTIL':
					if (!is_string($value)) {
						throw new InvalidInputException('Invalid UNTIL in the recurrence rule');
					}
					try {
						// The same parser the iterator's constructor uses, so an UNTIL
						// this accepts is one sabre accepts.
						DateTimeParser::parse($value, new \DateTimeZone('UTC'));
					} catch (\Throwable $e) {
						throw new InvalidInputException('Invalid UNTIL in the recurrence rule');
					}
					break;
			}
		}
	}

	/**
	 * Asserts every value of a numeric RRULE part is an integer within
	 * [$min, $max] - or, when $signed, within that range in either direction with
	 * 0 excluded, which is how RFC 5545 writes the "-1 means the last one" parts.
	 *
	 * @param string|array<int, string> $value the raw part value from Recur::stringToArray
	 * @throws InvalidInputException on a non-numeric or out-of-range value
	 */
	private function assertNumericPart(string $name, $value, int $min, int $max, bool $signed): void {
		$values = (array)$value;
		if ($values === []) {
			throw new InvalidInputException('Invalid ' . $name . ' in the recurrence rule');
		}
		foreach ($values as $raw) {
			// Deliberately strict: sabre casts these with a plain (int), which reads
			// "12abc" as 12. Anything that is not cleanly an optionally-signed
			// integer is refused rather than silently reinterpreted.
			if (!is_string($raw) || preg_match('#^[+-]?\d+$#', $raw) !== 1) {
				throw new InvalidInputException('Invalid ' . $name . ' in the recurrence rule');
			}
			$int = (int)$raw;
			$magnitude = $signed ? abs($int) : $int;
			if ($magnitude < $min || $magnitude > $max) {
				throw new InvalidInputException('Invalid ' . $name . ' in the recurrence rule');
			}
		}
	}

	/**
	 * Resolves the rule's stored IANA timezone id to a DateTimeZone. A null or
	 * empty stored value (pre-#3587 rules) or an unparseable id falls back to the
	 * server default timezone, then UTC as a last resort.
	 */
	private function timezoneFor(?string $timezone): \DateTimeZone {
		if ($timezone !== null && $timezone !== '') {
			try {
				return new \DateTimeZone($timezone);
			} catch (\Exception $e) {
				// fall through to the server default
			}
		}
		try {
			return new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
		} catch (\Exception $e) {
			return new \DateTimeZone('UTC');
		}
	}

	/**
	 * The IANA timezone a new rule owned by $uid should carry: the user's
	 * Nextcloud personal timezone (Settings → Personal), falling back to the
	 * server default. Stored on the rule so its schedule is stable even if the
	 * user later changes their personal timezone.
	 */
	private function defaultTimezoneFor(string $uid): string {
		$tz = $this->config->getUserValue($uid, 'core', 'timezone', '');
		if ($tz !== '') {
			try {
				new \DateTimeZone($tz);
				return $tz;
			} catch (\Exception $e) {
				// invalid stored value - fall through to server default
			}
		}
		return date_default_timezone_get() ?: 'UTC';
	}

	/**
	 * The timezone a create/update should store: an explicitly supplied IANA id
	 * when the caller sent one (API clients scheduling on someone else's behalf),
	 * otherwise the owner's personal timezone. Unlike {@see self::timezoneFor},
	 * which is lenient about already-stored values for back-compat, an explicit
	 * bad id is a client error and is rejected.
	 *
	 * @throws InvalidInputException if $timezone is a non-empty, unparseable id
	 */
	private function resolveTimezone(?string $timezone, string $uid): string {
		if ($timezone === null || $timezone === '') {
			return $this->defaultTimezoneFor($uid);
		}
		try {
			new \DateTimeZone($timezone);
		} catch (\Exception $e) {
			throw new InvalidInputException('Invalid timezone');
		}
		return $timezone;
	}

	// ---- rule CRUD --------------------------------------------------------

	/**
	 * Rules on a board, excluding any whose template card is in the trash (#67):
	 * a soft-deleted template pauses its rule (it can't spawn) and resurrects on
	 * restore, so the rule row is kept — but showing it in the automation list
	 * makes it look like a live orphan. Filter those out here rather than in the
	 * mapper so card reads stay behind CardMapper (architecture rule #3741). Card
	 * counts per board are small, so the per-rule template read is not a concern.
	 *
	 * @return RecurRule[]
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not read the board
	 */
	public function listForBoard(int $boardId, string $uid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);
		$rules = $this->ruleMapper->findByBoard($boardId);

		return array_values(array_filter($rules, function (RecurRule $rule): bool {
			try {
				return $this->cardMapper->find($rule->getTemplateCardId())->getDeletedAt() === 0;
			} catch (DoesNotExistException $e) {
				// Template hard-deleted (purged) — the rule is an orphan that the
				// purge cascade should already have removed; hide it regardless.
				return false;
			}
		}));
	}

	/**
	 * Creates a rule. The template card and the target stack must both belong
	 * to $boardId; the RRULE must parse. The rule's owner is the creating user
	 * - spawns run as them, so revoked board access naturally disables spawning.
	 * `next_occurrence_at` is computed and stored on creation.
	 *
	 * @throws DoesNotExistException if the board, template card or target stack does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 * @throws InvalidInputException on invalid mode, policy, offset, RRULE, timezone or cross-board references
	 */
	public function create(
		int $boardId,
		int $templateCardId,
		int $targetStackId,
		int $mode,
		string $rrule,
		int $duedatePolicy,
		int $duedateOffsetSeconds,
		bool $skipWhileOpen,
		string $uid,
		?string $timezone = null,
	): RecurRule {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);
		$this->validate($boardId, $templateCardId, $targetStackId, $mode, $rrule, $duedatePolicy, $duedateOffsetSeconds);
		// Visibility (#3760): a rule may only be anchored on a template card its
		// creator can SEE - a hidden template reads as "does not exist" (404,
		// same as a bogus id - no existence oracle). Spawns re-check against the
		// rule OWNER, so a later visibility narrowing cannot keep leaking copies.
		$template = $this->loadCard($templateCardId);
		$this->visibilityGuard->assertVisible($board, $template, $uid);

		$now = $this->time->getTime();
		$rule = new RecurRule();
		$rule->setBoardId($boardId);
		$rule->setTemplateCardId($templateCardId);
		$rule->setTargetStackId($targetStackId);
		$rule->setMode($mode);
		$rule->setRrule($rrule);
		$rule->setDuedatePolicy($duedatePolicy);
		$rule->setDuedateOffsetSeconds($duedateOffsetSeconds);
		$rule->setSkipWhileOpen($skipWhileOpen);
		$rule->setEnabled(true);
		$rule->setOwner($uid);
		$rule->setLastSpawnedAt(0);
		$rule->setOccurrencesSpawned(0);
		$rule->setCreatedAt($now);
		// The schedule is expanded as floating wall-clock time in this zone: an
		// explicit IANA id from the caller wins, otherwise the owner's personal
		// timezone (server default fallback).
		$rule->setTimezone($this->resolveTimezone($timezone, $uid));
		// Work out when this rule should fire for the FIRST time.
		//
		// The schedule is anchored at the card's Start date (its due date, then the
		// creation time, as fallbacks - see anchorFor()). We pick the first
		// occurrence at or after that anchor, but NEVER the occurrence that coincides
		// with "now": the card the user just set up already exists, and firing on it
		// would immediately reset/clone the card and overwrite the date they just
		// picked (that was bug #80 - a "Yearly" repeat re-stamped to today). A Start
		// date set for the future fires on that date; otherwise the first fire is the
		// next occurrence after now. See firstFireFor(). "Create now" is the way to
		// spawn one right away on purpose.
		$rule->setNextOccurrenceAt($this->firstFireFor($rule, $this->anchorFor($template, $rule)));

		return $this->ruleMapper->insert($rule);
	}

	/**
	 * Updates the given fields of a rule (null = leave unchanged). The cached
	 * `next_occurrence_at` is re-armed only when the schedule actually changes (a
	 * different RRULE, timezone or template card - the template carries the
	 * anchor date) or the rule is re-enabled (disabled → enabled),
	 * and even then it is never rewound onto an occurrence the cron already spawned - a
	 * no-op edit leaves the cursor exactly where it was, so editing a rule can no
	 * longer duplicate an already-fired occurrence dated today (#65).
	 *
	 * A changed RRULE additionally resets the `occurrences_spawned` tally, so an
	 * "ends after N times" edit means N MORE cards rather than N counted from the
	 * rule's creation - see the reset below for the trade-off.
	 *
	 * @throws DoesNotExistException if the rule, its board, the template card or the target stack does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 * @throws InvalidInputException on invalid mode, policy, offset, RRULE, timezone or cross-board references
	 */
	public function update(
		int $id,
		?int $templateCardId,
		?int $targetStackId,
		?int $mode,
		?string $rrule,
		?int $duedatePolicy,
		?int $duedateOffsetSeconds,
		?bool $skipWhileOpen,
		?bool $enabled,
		string $uid,
		?string $timezone = null,
	): RecurRule {
		$rule = $this->ruleMapper->find($id);
		$board = $this->loadBoard($rule->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);

		$newTemplate = $templateCardId ?? $rule->getTemplateCardId();
		$newStack = $targetStackId ?? $rule->getTargetStackId();
		$newMode = $mode ?? $rule->getMode();
		$newRrule = $rrule ?? $rule->getRrule();
		$newPolicy = $duedatePolicy ?? $rule->getDuedatePolicy();
		$newOffset = $duedateOffsetSeconds ?? $rule->getDuedateOffsetSeconds();
		// Turning a rule OFF must never depend on its stored RRULE still passing
		// validation. Rules predate their guards: a `FREQ=MINUTELY` (spec-valid, and
		// accepted until it was found to wedge the iterator), a `FREQ=WEEKLY;BYHOUR=99`,
		// or the empty rrule the API's own default once produced are all sitting in
		// existing installs. Re-validating the UNCHANGED stored rule on every update
		// made `PATCH {"enabled": false}` 400, so the only way to stop such a rule was
		// to delete it - losing the template link and the history with it. When the
		// caller supplies no new RRULE and is switching the rule off, skip the RRULE
		// check; every other field is still validated, and the cron already refuses to
		// run the rule (advanceSchedule disables it on the same exception). Re-enabling
		// still validates - a rule that cannot run must not be switchable back on.
		$disablingStoredRule = $enabled === false && $rrule === null;
		$this->validate(
			$rule->getBoardId(),
			$newTemplate,
			$newStack,
			$newMode,
			$newRrule,
			$newPolicy,
			$newOffset,
			!$disablingStoredRule,
		);
		// Same gate as create() (#3760): re-anchoring on a hidden template is a 404.
		$template = $this->loadCard($newTemplate);
		$this->visibilityGuard->assertVisible($board, $template, $uid);

		// Capture the pre-edit schedule + enabled state BEFORE applying the setters
		// so we can tell an actual schedule change from a no-op edit (#65). The
		// cursor may only be re-armed when the schedule really changed, and never
		// rewound onto an occurrence the cron has already spawned.
		$originalRrule = $rule->getRrule();
		$originalTimezone = $rule->getTimezone();
		$originalTemplate = $rule->getTemplateCardId();
		$wasEnabled = $rule->getEnabled();
		// A null/empty timezone means "leave the zone alone"; there is no way to
		// clear it (a rule always expands in SOME zone, cleared just means the
		// server default). An explicit bad id is rejected by resolveTimezone().
		$newTimezone = ($timezone === null || $timezone === '')
			? $originalTimezone
			: $this->resolveTimezone($timezone, $uid);

		$rule->setTimezone($newTimezone);
		$rule->setTemplateCardId($newTemplate);
		$rule->setTargetStackId($newStack);
		$rule->setMode($newMode);
		$rule->setRrule($newRrule);
		$rule->setDuedatePolicy($newPolicy);
		$rule->setDuedateOffsetSeconds($newOffset);
		if ($skipWhileOpen !== null) {
			$rule->setSkipWhileOpen($skipWhileOpen);
		}
		if ($enabled !== null) {
			$rule->setEnabled($enabled);
		}

		// A new RRULE means a new series, so the "ends after N times" tally starts
		// again from zero. `occurrences_spawned` is a LIFETIME counter, and the only
		// thing that reads it is the COUNT guard in advanceSchedule(); leaving it
		// alone made an edit that adds or lowers COUNT collapse to a single card -
		// a weekly rule that had already spawned 12, edited to FREQ=WEEKLY;COUNT=4
		// ("four more"), spawned one and then disabled itself because 13 >= 4.
		// Trade-off, taken deliberately: lowering COUNT=10 to COUNT=3 half-way
		// through also restarts the count instead of ending the series early. An
		// RRULE edit is read as "this is the new schedule", not as a correction to
		// the old one. Only an actual rule change resets - a no-op re-save does not.
		if ($newRrule !== $originalRrule) {
			$rule->setOccurrencesSpawned(0);
		}

		// Do we need to recalculate when this rule fires next?
		//
		// Only in two cases: the user changed the actual schedule (a different
		// repeat rule), or they switched the rule back on after it was off. If they
		// changed nothing about the schedule - e.g. just re-saved the card's Repeat
		// control, or toggled "enabled" while it was already on - we must leave the
		// next fire time exactly as it is. Recalculating a no-op edit could pull the
		// fire time back onto a date the system already acted on, and re-firing it
		// makes a duplicate card dated today (that was bug #65). The two flags below
		// make sure a no-op edit skips this block entirely.
		//
		// When we DO recalculate, we pick the next date STRICTLY AFTER now (that is
		// what passing $now does). Two reasons:
		//   1. It fixes the "yearly reset to today" family of bugs (#80) - the rule
		//      is never left ready to fire the instant the next cron runs.
		//   2. If the user speeds up a rule (say Weekly -> Daily), it starts on the
		//      new schedule right away. The old code kept the far-off weekly date, so
		//      "Daily" did nothing for up to a week. It's also safe: a date after now
		//      is always in the future, so it can never be one we already fired -
		//      meaning no duplicate card (still safe for #65).
		//
		// A timezone change counts as a schedule change too: re-anchoring the same
		// repeat rule in a different zone shifts every future occurrence. So does
		// pointing the rule at a DIFFERENT template card: the schedule is anchored
		// on the template's own Start/End date, so the old card's anchor is
		// meaningless once the rule follows another card. Without this the rule
		// would keep firing on the old card's dates while stamping copies of the
		// new one, until some unrelated schedule edit happened to re-arm it.
		$scheduleChanged = $newRrule !== $originalRrule
			|| $newTimezone !== $originalTimezone
			|| $newTemplate !== $originalTemplate;
		$reEnabled = $rule->getEnabled() && !$wasEnabled;
		if ($rule->getEnabled() && ($scheduleChanged || $reEnabled)) {
			$rule->setNextOccurrenceAt($this->firstFireFor($rule, $this->anchorFor($template, $rule)));
		}

		return $this->ruleMapper->update($rule);
	}

	/**
	 * @throws DoesNotExistException if the rule or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 */
	public function delete(int $id, string $uid): RecurRule {
		$rule = $this->ruleMapper->find($id);
		$board = $this->loadBoard($rule->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);
		return $this->ruleMapper->delete($rule);
	}

	/**
	 * Spawns a rule once immediately, ignoring its schedule, then persists the
	 * usual bookkeeping (occurrences_spawned, last_spawned_at, recomputed
	 * next_occurrence_at). Manual creation still honors skip_while_open? No -
	 * create-now is an explicit user action, so it always spawns.
	 *
	 * Schedule-advance decision: create-now does NOT bring the schedule forward
	 * on its own. After the manual spawn we recompute next_occurrence_at as the
	 * next occurrence at or after now, exactly as a scheduled spawn would - so a
	 * missed/early manual spawn never skips the upcoming scheduled fire, it just
	 * stamps an extra card now. (Matches deck-recurrence "create now": it stamps
	 * a card and leaves the cadence intact.)
	 *
	 * @throws DoesNotExistException if the rule, its board, the template card or the target stack does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 */
	public function createNow(int $id, string $uid): ?Card {
		$rule = $this->ruleMapper->find($id);
		$board = $this->loadBoard($rule->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);
		// Visibility (#3760): the spawned/reset card (title, description) is
		// returned to the ACTOR - a template hidden from them must read as
		// missing (404), or create-now would be a read oracle for hidden
		// content. The spawn itself re-checks against the rule OWNER.
		$this->visibilityGuard->assertVisible($board, $this->loadCard($rule->getTemplateCardId()), $uid);
		return $this->spawn($rule, true);
	}

	/**
	 * Re-point every repeat anchored on this card after its Start/due date was
	 * edited, so the series follows the new dates - what a user naturally expects
	 * when they reschedule a repeating card ("move it to the 15th" should make it
	 * repeat from the 15th). Called by {@see CardService::update} whenever a card's
	 * start or due date changes; a card with no rules is a cheap no-op.
	 *
	 * The next fire is recomputed from the card's new anchor exactly like create()
	 * (first occurrence at/after the anchor, never one that coincides with "now"),
	 * so a Start pushed into the future fires then and a Start moved earlier picks
	 * up the next occurrence after now - never a back-dated spawn. A disabled or
	 * exhausted rule is left alone.
	 *
	 * This is a best-effort convenience that runs AFTER the card edit has already
	 * committed (CardService::update), so it must NEVER throw out of that flow: a
	 * failure here (a rule row deleted concurrently, a DB hiccup, an unparseable
	 * legacy RRULE) would surface as a 500 for an edit that actually succeeded and
	 * leave the user's change apparently lost. Every failure - the rule lookup and
	 * each per-rule recompute/update - is caught and logged; a stale cursor
	 * self-heals on the next legitimate schedule edit or is a no-op if the rule is
	 * gone.
	 */
	public function rearmForTemplateCard(Card $card): void {
		try {
			$rules = $this->ruleMapper->findByTemplateCard($card->getId());
		} catch (\Throwable $e) {
			$this->logger->warning(
				'kanso: could not load recurring rules to re-arm after a date edit on card ' . $card->getId(),
				['exception' => $e]
			);
			return;
		}
		foreach ($rules as $rule) {
			if (!$rule->getEnabled()) {
				continue;
			}
			try {
				$rule->setNextOccurrenceAt($this->firstFireFor($rule, $this->anchorFor($card, $rule)));
				$this->ruleMapper->update($rule);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'kanso: could not re-arm recurring rule ' . $rule->getId() . ' after a date edit',
					['exception' => $e]
				);
			}
		}
	}

	// ---- spawning ---------------------------------------------------------

	/**
	 * Spawns one occurrence of a rule and advances its bookkeeping. CLONE mode
	 * creates a fresh card in the target stack (copying description, labels and
	 * assignees) with a due date per the rule's policy; RESET mode moves the
	 * template card back to the target stack and clears its done state.
	 *
	 * $manual is set by create-now: it ignores skip_while_open (an explicit user
	 * action always spawns). Returns the spawned/reset card, or null when a
	 * scheduled CLONE spawn was skipped because the previous card is still open.
	 *
	 * Spawns exactly ONE occurrence - the one currently cached in
	 * next_occurrence_at - and advances the cursor to the NEXT occurrence strictly
	 * after it (NOT to now). Advancing per occurrence, rather than jumping the
	 * cursor to now, is what lets a delayed cron catch up: {@see self::runDueRules}
	 * calls spawn() repeatedly while the rule stays due, so a rule N intervals in
	 * the past yields N cards - one per missed occurrence - each in its own
	 * transaction, so partial progress is durable (a crash mid-catch-up never
	 * re-spawns an already-committed occurrence). Manual create-now fires the
	 * cached occurrence too, then advances the same way.
	 *
	 * Bookkeeping always runs (except on a skip): occurrences_spawned and
	 * last_spawned_at are bumped, and next_occurrence_at is advanced past the
	 * fired occurrence - 0 (COUNT/UNTIL exhausted) self-disables the rule.
	 *
	 * Atomicity (idempotency on cron retry): the card mutation AND the rule
	 * bookkeeping/schedule advance are wrapped in a single DB transaction, so a
	 * crash (or a throwing enrich write) after the card insert but before
	 * next_occurrence_at is advanced rolls the whole occurrence back - both the
	 * card and the un-advanced rule. Without this, a half-done CLONE spawn left
	 * the rule still due and the next cron run stamped a duplicate card
	 * (RESET self-corrected; CLONE duplicated unboundedly). Nextcloud DB
	 * transactions nest via savepoints, so CardService's own transactions run
	 * inside this outer one. Ordering decision: single-transaction (not
	 * advance-cursor-first, not an occurrence key) - it fits the existing
	 * IDBConnection begin/commit/rollBack idiom used across the services and
	 * keeps every write in the occurrence rolled back together. A sibling card
	 * (#3575) adds the spawned-card change-log row and can hang it off this same
	 * transaction.
	 *
	 * @throws DoesNotExistException if the template card or target stack is gone
	 * @throws NotPermittedException if the owner lost board access
	 * @throws InvalidInputException on a card mutation error
	 */
	public function spawn(RecurRule $rule, bool $manual = false): ?Card {
		$occurrenceTs = $rule->getNextOccurrenceAt() > 0
			? $rule->getNextOccurrenceAt()
			: $this->time->getTime();

		// Read the template up front - both the soft-trash pause below and the CLONE
		// enrichment need it, so read once (a missing/hard-deleted template stays
		// null here and falls through to the mode branch, which throws its usual
		// DoesNotExistException that the cron logs and retries).
		$template = $this->findTemplateOrNull($rule);
		// The cadence is anchored at the card's Start date (see anchorFor()); read it
		// once here so every advanceSchedule() below walks the same anchor.
		$anchorTs = $this->anchorFor($template, $rule);

		// Pause on a SOFT-trashed template (#4124): create/update guard the template
		// via loadCard() (throws on deleted_at > 0), but the spawn hot path read it
		// raw, so a template moved to the trash kept cloning/resetting. Treat a
		// soft-trashed template as a pause, not an error and not a hard-disable: skip
		// the spawn but still advance the schedule past this occurrence (like a
		// skip_while_open skip) so the cron does not busy-loop, and leave the rule
		// enabled so it resumes automatically when the template is restored. A PURGED
		// template is a different case - the purge drops the rule outright (#4123).
		if ($template !== null && $template->getDeletedAt() > 0) {
			$this->db->beginTransaction();
			try {
				$this->logger->info(
					'kanso: recurring rule ' . $rule->getId()
					. ' paused, template card is in the trash'
				);
				$this->advanceSchedule($rule, $this->advanceFrom($rule, $occurrenceTs, $manual), $anchorTs);
				$this->ruleMapper->update($rule);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			return null;
		}

		$this->db->beginTransaction();
		try {
			if ($rule->getMode() === RecurRule::MODE_RESET) {
				$card = $this->spawnReset($rule, $occurrenceTs);
			} else {
				if (!$manual && $rule->getSkipWhileOpen() && $this->previousCardOpen($rule)) {
					$this->logger->info(
						'kanso: recurring rule ' . $rule->getId()
						. ' skipped, previously spawned card is still open'
					);
					// A skip is not an occurrence: leave the counters be, but still
					// advance the schedule past this occurrence so the rule does not
					// re-fire it (the next due occurrence is still handled next loop).
					$this->advanceSchedule($rule, $this->advanceFrom($rule, $occurrenceTs, $manual), $anchorTs);
					$this->ruleMapper->update($rule);
					$this->db->commit();
					return null;
				}
				$card = $this->spawnClone($rule, $occurrenceTs, $template);
			}

			$rule->setOccurrencesSpawned($rule->getOccurrencesSpawned() + 1);
			$rule->setLastSpawnedAt($card->getId());
			$this->advanceSchedule($rule, $this->advanceFrom($rule, $occurrenceTs, $manual), $anchorTs);
			$this->ruleMapper->update($rule);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Commit succeeded - now it is safe to broadcast the enrichment UPDATE
		// row that spawnClone deferred (push=false inside the transaction).
		$this->changeNotifier->pushBoardChanged($card->getBoardId());

		return $card;
	}

	/**
	 * CLONE: a new card at the bottom of the target stack (CardService handles
	 * the EDIT check, the sort key and the CREATE change), then the template's
	 * description, due date, labels and assignees copied over.
	 *
	 * The enrichment (description + due date + labels + assignees) is written
	 * straight through the mappers - it bypasses CardService, so none of it
	 * lands in the change log on its own. CardService::create logged only a
	 * title-only CREATE, so a delta-only client that consumed that CREATE would
	 * refetch a card whose description/labels/assignees had not yet reached the
	 * board's change log, and would keep that stripped copy until an unrelated
	 * mutation happened to bump the board. To close that gap we append an
	 * ACTION_UPDATE change row for the enriched card here, inside the spawn
	 * transaction (#3574), so the enrichment advances getLatestChangeId / the
	 * board ETag atomically with the CREATE. The push is deferred (push=false)
	 * and emitted by {@see self::spawn()} after commit - a pre-commit push could
	 * make a client refetch state the transaction may still roll back.
	 */
	private function spawnClone(RecurRule $rule, int $occurrenceTs, ?Card $template = null): Card {
		// $template is the copy spawn() already read; null only when spawn() found
		// no template (hard-deleted) - re-read to raise the usual DoesNotExistException.
		$template ??= $this->cardMapper->find($rule->getTemplateCardId());

		// Visibility (#3760): the spawn runs as the rule OWNER - if the template
		// has been narrowed past them since the rule was created, copying its
		// content into a card the owner CAN see would be a leak. Fails like a
		// missing template (DoesNotExistException); the cron logs and retries,
		// exactly as when the owner lost board access.
		$board = $this->loadBoard($rule->getBoardId());
		$this->visibilityGuard->assertVisible($board, $template, $rule->getOwner());

		// CardService::create runs as the owner: EDIT check, bottom-of-stack
		// sort key, CREATE change - all in one place. The spawned card inherits
		// the template's visibility class AND frozen creator side VERBATIM
		// (#3760) - set on the INSERT itself, so the create-time fan-outs
		// (activity, board watchers) never see a wider interim 'public' card.
		$card = $this->cardService->create(
			$rule->getTargetStackId(),
			$template->getTitle(),
			$rule->getOwner(),
			null,
			null,
			$template->getVisibility() ?? CardVisibilityScope::VISIBILITY_PUBLIC,
			$template->getCreatorRole(),
		);

		$card->setDescription($template->getDescription());
		// Slide the template's Start→End window forward to this occurrence (see
		// windowFor): the occurrence becomes the new Start, the End keeps the same
		// gap. The clone inherits the template's dates shifted, not a stamped-on due
		// date.
		[$newStart, $newEnd] = $this->windowFor($template, $occurrenceTs);
		$card->setStartDate($newStart);
		$card->setDuedate($newEnd);
		// Carry the template's all-day flag (#4125): without it the clone defaults
		// to all_day=false and shows a spurious 00:00 time on an all-day template.
		$card->setAllDay($template->getAllDay() ?? false);
		$card->setLastModified($this->time->getTime());
		$card = $this->cardMapper->update($card);

		foreach ($this->cardLabelMapper->findLabelIdsByCard($template->getId()) as $labelId) {
			if (!$this->cardLabelMapper->exists($card->getId(), $labelId)) {
				$this->cardLabelMapper->insertAssignment($card->getId(), $labelId);
			}
		}
		foreach ($this->cardAssigneeMapper->findUserIdsByCard($template->getId()) as $assigneeUid) {
			if (!$this->cardAssigneeMapper->exists($card->getId(), $assigneeUid)) {
				$this->cardAssigneeMapper->insertAssignment($card->getId(), $assigneeUid);
			}
		}

		// Log the enrichment as an UPDATE so delta-sync (?since=) and the board
		// ETag reflect the full card, not just its title. Deferred push - the
		// spawn transaction owns the commit and the post-commit broadcast.
		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$card->getId(),
			Change::ACTION_UPDATE,
			$rule->getOwner(),
			false,
			Change::VERB_UPDATED,
		);

		return $card;
	}

	/**
	 * RESET: the template card is the one working card. Move it back to the
	 * target stack (CardService::move handles the EDIT check, the sort key and
	 * the MOVE change), then clear its done/archived state and re-arm the due
	 * date per policy.
	 *
	 * The done/archived/duedate reset is a direct mapper update - like CLONE's
	 * enrichment it bypasses CardService and so writes no change row of its own;
	 * only the MOVE reaches the log. A delta-only client that consumed the MOVE
	 * would refetch a card still flagged done / carrying its old due date until
	 * an unrelated mutation bumped the board. Append an ACTION_UPDATE change row
	 * for the reset (deferred push - the spawn transaction broadcasts after
	 * commit) so the cleared state advances getLatestChangeId / the board ETag
	 * atomically.
	 */
	private function spawnReset(RecurRule $rule, int $occurrenceTs): Card {
		// Move to the bottom of the target stack (afterCardId = last card).
		$last = $this->cardMapper->findLastInStack($rule->getTargetStackId());
		$afterCardId = $last !== null && $last->getId() !== $rule->getTemplateCardId()
			? $last->getId()
			: null;
		$card = $this->cardService->move(
			$rule->getTemplateCardId(),
			$rule->getTargetStackId(),
			$afterCardId,
			$rule->getOwner(),
		);

		$card->setDoneAt(0);
		// Clear started_at too: status is the (started_at, done_at) PAIR, so leaving
		// started_at stamped made a freshly reset occurrence read "In progress"
		// forever - it had never been started, only its predecessor had.
		$card->setStartedAt(0);
		$card->setArchived(false);
		// Slide this card's OWN Start→End window forward to the occurrence (the reset
		// card is its own template). $card still carries its pre-reset dates here, so
		// windowFor reads the old window and returns the shifted one.
		[$newStart, $newEnd] = $this->windowFor($card, $occurrenceTs);
		$card->setStartDate($newStart);
		$card->setDuedate($newEnd);
		// Re-arm the due-date reminders (#3545) for the reset card's new due date.
		$card->setDueReminderSent(0);
		$card->setDayBeforeReminderSent(0);
		$card->setLastModified($this->time->getTime());
		$card = $this->cardMapper->update($card);

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$card->getId(),
			Change::ACTION_UPDATE,
			$rule->getOwner(),
			false,
			Change::VERB_UPDATED,
		);

		return $card;
	}

	/**
	 * The timestamp the repeat schedule is anchored at - its RFC 5545 DTSTART.
	 * The template card's Start date, else its End (due) date, else the rule's
	 * creation time. Anchoring at the card's own dates is what makes "starts
	 * Jan 5, repeats weekly" land on Jan 5, 12, 19 rather than on whatever day
	 * Repeat happened to be switched on. A card with no dates falls back to the
	 * rule's creation time (the pre-window behaviour).
	 */
	private function anchorFor(?Card $template, RecurRule $rule): int {
		if ($template !== null) {
			$start = $template->getStartDate()?->getTimestamp();
			if ($start !== null) {
				return $start;
			}
			$end = $template->getDuedate()?->getTimestamp();
			if ($end !== null) {
				return $end;
			}
		}
		return $rule->getCreatedAt();
	}

	/**
	 * The first fire for a freshly created or re-armed rule: the next occurrence
	 * at or after the anchor, but never the occurrence that coincides with "now"
	 * itself - firing that one would immediately reset/clone the card the user
	 * just set up and overwrite the date they picked (#80). A Start date set for
	 * the FUTURE fires on that date; a past/now anchor fires on the next
	 * occurrence strictly after now.
	 */
	private function firstFireFor(RecurRule $rule, int $anchorTs): int {
		$now = $this->time->getTime();
		$after = max($anchorTs - 1, $now);
		return $this->computeNextOccurrence($rule->getRrule(), $after, $anchorTs, $rule->getTimezone());
	}

	/**
	 * The [start, end] dates a card spawned/reset at $occurrenceTs should carry.
	 * The template's Start→End window slides forward to the occurrence, keeping
	 * its length (the calendar-event model): the occurrence becomes the new Start
	 * and the End keeps the same distance after it. A template with only one of
	 * the two dates slides just that one; a template with neither stays date-less
	 * (a repeat never invents a date the user did not set).
	 *
	 * @return array{0: ?\DateTime, 1: ?\DateTime} [start, end]
	 */
	private function windowFor(Card $template, int $occurrenceTs): array {
		$start = $template->getStartDate()?->getTimestamp();
		$end = $template->getDuedate()?->getTimestamp();

		if ($start !== null && $end !== null) {
			$duration = max(0, $end - $start);
			return [
				new \DateTime('@' . $occurrenceTs),
				new \DateTime('@' . ($occurrenceTs + $duration)),
			];
		}
		if ($start !== null) {
			return [new \DateTime('@' . $occurrenceTs), null];
		}
		if ($end !== null) {
			return [null, new \DateTime('@' . $occurrenceTs)];
		}
		return [null, null];
	}

	/**
	 * The point the schedule should advance PAST after this spawn.
	 *
	 * Scheduled spawns advance strictly past the occurrence that just fired, so
	 * the cursor walks occurrence-by-occurrence and the cron catches up on every
	 * missed one. Manual create-now instead re-arms to the next occurrence at or
	 * after now (advance from now - 1): it stamps an extra card without bringing
	 * the cadence forward, so a missed/early manual spawn never skips the upcoming
	 * scheduled fire.
	 */
	private function advanceFrom(RecurRule $rule, int $occurrenceTs, bool $manual): int {
		return $manual ? $this->time->getTime() - 1 : $occurrenceTs;
	}

	/**
	 * The COUNT limit an RRULE carries, or null for an open-ended (or
	 * UNTIL-limited) series.
	 *
	 * Deliberately reads the rule the way sabre does rather than with a
	 * hand-rolled regex, so the cap enforced here cannot disagree with the cap
	 * {@see RRuleIterator} enforces: split with sabre's own
	 * {@see Recur::stringToArray} (which also upper-cases the parts), then the
	 * same plain int cast and "must be >= 1" rejection the iterator applies to
	 * COUNT. That equivalence matters - a stricter read (say, digits-only) would
	 * quietly hand back null for values sabre accepts, like `COUNT=+3`, and leave
	 * exactly those rules running away. Anything sabre would not accept as a
	 * positive integer returns null, which just leaves the iterator's own verdict
	 * standing; a genuinely malformed rule already throws out of
	 * {@see self::computeNextOccurrence} and is disabled by the caller.
	 */
	private function countLimitFor(string $rrule): ?int {
		try {
			$parts = Recur::stringToArray($rrule);
		} catch (\Exception $e) {
			return null;
		}
		$count = $parts['COUNT'] ?? null;
		if (!is_scalar($count)) {
			return null;
		}
		$limit = (int)$count;
		return $limit >= 1 ? $limit : null;
	}

	/**
	 * Advances the rule's cached next fire time to the first occurrence strictly
	 * after the occurrence that just fired ($firedOccurrenceTs) - NOT to now.
	 * Walking the cursor occurrence-by-occurrence is what lets a delayed cron
	 * catch up on every missed occurrence instead of skipping to the next future
	 * one. 0 (COUNT/UNTIL exhausted) self-disables the rule. A malformed RRULE
	 * (should be impossible past create/update validation, but a rule could
	 * predate a stricter parser) is treated as exhausted and disables the rule
	 * rather than throwing out of the spawn.
	 *
	 * COUNT gets a second, independent cap from the rule's own durable tally,
	 * because the iterator alone cannot be trusted for it:
	 * {@see self::computeNextOccurrence} builds a FRESH RRuleIterator per call,
	 * whose COUNT window restarts from whatever DTSTART it is handed. In RESET
	 * mode {@see self::spawnReset} rewrites the template card's own dates to each
	 * fired occurrence, so {@see self::anchorFor} hands the next call a DTSTART one
	 * occurrence later and the window restarts every spawn - a "repeat 3 times"
	 * rule repeating forever. Checking occurrences_spawned against the RRULE's
	 * COUNT closes that: the tally is persisted with the rest of the spawn
	 * bookkeeping inside the spawn transaction, so the anchor drifting cannot
	 * touch it. Rules already running away in the wild need no backfill - the
	 * tally has been maintained since the feature shipped, so the next spawn
	 * delivers one last card and then retires the rule.
	 *
	 * The guard is mode-agnostic, and can only ever pull $next DOWN to 0 - it
	 * never extends a series. CLONE keeps a stable DTSTART, so there the iterator
	 * is still the binding constraint and the guard merely agrees with it.
	 *
	 * The tally counts CARDS ACTUALLY PRODUCED, which decides what spends a COUNT
	 * slot as far as THIS guard is concerned:
	 *   - a skip does not bump the tally, so the guard never charges the user for
	 *     a card that was never created (skip_while_open and the trashed-template
	 *     pause both take this path). Note this does not hand the occurrence back:
	 *     a skip still advances the cursor past it, so a skipped occurrence is
	 *     gone from the series either way - that is pre-existing skip behaviour,
	 *     not something the guard changes;
	 *   - a manual "create now" DOES bump it, so it spends one of the N and the
	 *     series ends one scheduled fire earlier. That is the intended reading of
	 *     "ends after N times", and it is the one behaviour this guard changes for
	 *     CLONE, which was otherwise already correct.
	 *
	 * Re-enabling an already-exhausted rule yields one final card before the guard
	 * disables it again (the re-arm in {@see self::update} goes through
	 * {@see self::firstFireFor}, which has no tally to consult). Bounded, and
	 * arguably what "turn it back on" should do, so it is left alone rather than
	 * plumbing the tally into a path that also serves brand-new rules.
	 */
	private function advanceSchedule(RecurRule $rule, int $firedOccurrenceTs, int $anchorTs): void {
		try {
			$next = $this->computeNextOccurrence($rule->getRrule(), $firedOccurrenceTs, $anchorTs, $rule->getTimezone());
		} catch (InvalidInputException $e) {
			$this->logger->error(
				'kanso: recurring rule ' . $rule->getId() . ' has an invalid RRULE, disabling',
				['exception' => $e]
			);
			$next = 0;
		}
		$countLimit = $this->countLimitFor($rule->getRrule());
		if ($countLimit !== null && $rule->getOccurrencesSpawned() >= $countLimit) {
			$next = 0;
		}
		$rule->setNextOccurrenceAt($next);
		if ($next === 0) {
			$rule->setEnabled(false);
		}
	}

	/**
	 * The rule's template card, or null if it is hard-gone. spawn() reads it once
	 * up front for both the soft-trash pause check (#4124) and CLONE enrichment.
	 * A MISSING template returns null (not an exception here) so spawn() falls
	 * through to the mode branch, which raises the usual DoesNotExistException the
	 * cron logs and retries; a purge cascade drops the rule outright (#4123).
	 */
	private function findTemplateOrNull(RecurRule $rule): ?Card {
		try {
			return $this->cardMapper->find($rule->getTemplateCardId());
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Whether the rule's most recently spawned card is still open (exists, not
	 * done, not archived, not deleted). A rule that has never spawned, or whose
	 * last card is gone, counts as not-open.
	 */
	private function previousCardOpen(RecurRule $rule): bool {
		if ($rule->getLastSpawnedAt() === 0) {
			return false;
		}
		try {
			$card = $this->cardMapper->find($rule->getLastSpawnedAt());
		} catch (DoesNotExistException) {
			return false;
		}
		return $card->getDoneAt() === 0
			&& !$card->getArchived()
			&& $card->getDeletedAt() === 0;
	}

	// ---- cron entry -------------------------------------------------------

	/**
	 * Spawns every due rule - the cron entry point. For each due rule this
	 * catches up on ALL missed occurrences (server was down / cron delayed):
	 * spawn() fires the cached occurrence and advances the cursor by exactly one
	 * occurrence, so while the rule stays due (next_occurrence_at <= now) we keep
	 * spawning - one card per missed occurrence, each in its own transaction, so
	 * partial progress survives a crash and is never double-spawned.
	 *
	 * BOUNDED: at most {@see self::MAX_CATCHUP} occurrences per rule per run. A
	 * rule dormant for months cannot flood a board in one pass; when the cap is
	 * hit we log the truncation and leave the rule due, so the remaining
	 * occurrences continue on the next run. Each rule runs in its own try/catch so
	 * one broken rule (deleted template, lost board access) cannot stall the rest;
	 * a rule that throws mid-catch-up keeps the occurrences it already committed
	 * and is retried next run from its durable cursor.
	 *
	 * @return int number of cards successfully spawned this run (across all rules)
	 */
	public function runDueRules(): int {
		$spawned = 0;
		$now = $this->time->getTime();
		foreach ($this->ruleMapper->findDueEnabled($now) as $rule) {
			$count = 0;
			try {
				// Catch up occurrence-by-occurrence while the rule stays due, up to
				// the per-run cap. spawn() advances next_occurrence_at past each
				// fired occurrence; a skip (skip_while_open) advances it too, so the
				// loop still terminates.
				while ($rule->getEnabled()
					&& $rule->getNextOccurrenceAt() > 0
					&& $rule->getNextOccurrenceAt() <= $now
					&& $count < self::MAX_CATCHUP) {
					if ($this->spawn($rule) !== null) {
						$spawned++;
					}
					$count++;
				}
				if ($count >= self::MAX_CATCHUP
					&& $rule->getEnabled()
					&& $rule->getNextOccurrenceAt() > 0
					&& $rule->getNextOccurrenceAt() <= $now) {
					$this->logger->warning(
						'kanso: recurring rule ' . $rule->getId()
						. ' catch-up truncated at ' . self::MAX_CATCHUP
						. ' occurrences; remaining occurrences continue next run'
					);
				}
			} catch (\Throwable $e) {
				$this->logger->warning(
					'kanso: could not spawn recurring rule ' . $rule->getId(),
					['exception' => $e]
				);
			}
		}
		return $spawned;
	}

	// ---- helpers ----------------------------------------------------------

	/**
	 * @throws InvalidInputException on invalid mode/policy/offset/RRULE or cross-board references
	 * @throws DoesNotExistException if the template card or target stack does not exist or is deleted
	 */
	private function validate(
		int $boardId,
		int $templateCardId,
		int $targetStackId,
		int $mode,
		string $rrule,
		int $duedatePolicy,
		int $duedateOffsetSeconds,
		bool $validateRrule = true,
	): void {
		if ($mode !== RecurRule::MODE_CLONE && $mode !== RecurRule::MODE_RESET) {
			throw new InvalidInputException('Invalid recurrence mode');
		}
		if (!in_array($duedatePolicy, [RecurRule::POLICY_AT_OCCURRENCE, RecurRule::POLICY_OFFSET_AFTER, RecurRule::POLICY_NONE], true)) {
			throw new InvalidInputException('Invalid due-date policy');
		}
		if ($duedateOffsetSeconds < 0 || $duedateOffsetSeconds > self::MAX_OFFSET_SECONDS) {
			throw new InvalidInputException('Invalid due-date offset');
		}
		// Parse-validate the RRULE (throws InvalidInputException on garbage).
		// We anchor at "now" purely to construct the iterator; the result is
		// discarded here - the point is to reject unparseable rules. Skipped only
		// when an update is switching an already-stored rule OFF - see update().
		//
		// Asking for the occurrence after `now - 1` makes the target the anchor
		// itself, so the advance loop's `current() < target` is false on its first
		// check and the iterator is never STEPPED - validation runs the guards,
		// builds the iterator, and stops. That is deliberate: create/update are the
		// two paths an anonymous-ish caller reaches most cheaply, and there is no
		// reason for either to spend a single occurrence of iteration budget on a
		// result it throws away. (It also means a future guard regression surfaces
		// as a wrong return value here rather than as a wedged worker.)
		if ($validateRrule) {
			$now = $this->time->getTime();
			$this->computeNextOccurrence($rrule, $now - 1, $now);
		}

		$card = $this->loadCard($templateCardId);
		if ($card->getBoardId() !== $boardId) {
			throw new InvalidInputException('The template card does not belong to the board');
		}
		$stack = $this->loadStack($targetStackId);
		if ($stack->getBoardId() !== $boardId) {
			throw new InvalidInputException('The target stack does not belong to the board');
		}
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
	 * @throws DoesNotExistException if the stack does not exist or is deleted
	 */
	private function loadStack(int $id): Stack {
		$stack = $this->stackMapper->find($id);
		if ($stack->getDeletedAt() > 0) {
			throw new DoesNotExistException('Stack ' . $id . ' is deleted');
		}
		return $stack;
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
