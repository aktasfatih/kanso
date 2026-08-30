<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * The server-side half of the View filter (#9862) - a PHP mirror of the client's
 * `makePredicate` (src/composables/useBoardFilters.js).
 *
 * WHY it exists. The cross-board feed ({@see ViewService::findMine()}) is hard-
 * capped at {@see ViewService::MAX_CARDS}. While the filter ran only in the
 * browser, that cap sliced the whole READABLE set before the filter ever saw it,
 * so on an account with more readable cards than the cap a narrow filter searched
 * only the first N rows of the sorted order and silently missed every match past
 * them. Applying the same predicate server-side, BEFORE the cap, makes the cap
 * slice the MATCHING set instead. This is a correctness fix, not a speedup: the
 * per-board enrichment queries are unchanged, only the payload shrinks.
 *
 * HYBRID, not a replacement. The client keeps applying `makePredicate` over
 * whatever rows arrive, so this predicate is a SUPERSET GUARD: its only job is to
 * make the cap slice the right pool. Chip editing therefore stays instant (the
 * cached rows re-filter locally on the same tick; the refetch only widens the
 * pool), and a PHP/JS drift can never be a silent correctness bug - a PHP
 * predicate that is LOOSER than the JS one merely ships harmless extra rows the
 * client drops. So the rule throughout this class is: **when in doubt, pass the
 * row through, never drop it.** An unrecognised dimension key, or an
 * unrecognised value inside a known dimension, is IGNORED - never a rejection,
 * never a drop. That is the same tolerance {@see \OCA\Kanso\Controller\ViewController::normalizeSort()}
 * already gives the sort: a read path an older or newer client must not be able
 * to hard-fail.
 *
 * WIRE FORMAT. Exactly the short keys `filterToQuery()` already emits for the
 * board's shareable filter links - `fl/fa/fp/ft/fe/fo/fr/fd/fs/fw/fb/fk/fsd/fsc/fcm`,
 * multi-value dimensions comma-joined. No new encoding, no query language.
 *
 * The predicate reads ONLY fields already present on a serialized card summary
 * ({@see CardSummaryService::serialize()} + {@see \OCA\Kanso\Db\Card::jsonSerializeSummary()}),
 * so it costs no extra query. Parity with the JS predicate is pinned by a golden
 * fixture asserted from BOTH runners (tests/fixtures/board-filter-parity.json,
 * read by ViewFilterTest.php and tests/unit/boardFilters.test.mjs).
 */
final class ViewFilter {
	/** Sentinel assignee uid meaning "no assignee" (JS: UNASSIGNED). */
	public const UNASSIGNED = '__none__';

	/** Sentinel estimate token meaning "no estimate" (JS: UNESTIMATED). */
	public const UNESTIMATED = '__none__';

	/** Sentinel review value meaning "no review requested" (JS: REVIEW_NONE). */
	public const REVIEW_NONE = 'none';

	/**
	 * The accepted values per single-select / whitelisted dimension, mirroring the
	 * JS option constants. A value outside its list is dropped on decode, which
	 * leaves that dimension unconstrained - exactly what `applyFilter()` does with
	 * a hand-edited URL on the client.
	 */
	private const TYPES = ['bug', 'feature', 'task', 'chore'];
	private const REVIEWS = ['pending', 'approved', 'changes_requested', self::REVIEW_NONE];
	private const DUE = ['overdue', 'week', 'none'];
	private const DONE = ['open', 'done'];
	private const WAITING = ['waiting', 'not_waiting'];
	private const BLOCKED = ['blocked', 'not_blocked'];
	private const CHECKLIST = ['has', 'incomplete', 'complete', 'none'];
	private const START = ['started', 'upcoming', 'none'];
	private const SUBCARD = ['top_level', 'parent', 'child'];
	private const COMMENTS = ['has', 'none'];

	/** "This week" = now .. end of the 7th day ahead, in milliseconds (JS parity). */
	private const WEEK_MS = 7 * 24 * 60 * 60 * 1000;

	/**
	 * Multi-select dimensions are kept as membership MAPS (value => true) so a
	 * match is an O(1) isset() rather than an in_array() scan per row - the
	 * predicate runs once per card over a set that can reach the cap.
	 *
	 * THIS SIGNATURE IS A WIRE CONTRACT, not just an argument list. ViewFilterTest
	 * derives the filter's dimension list from these parameter NAMES and asserts it
	 * against the emit ORDER of the client's `filterToQuery()`, which is what pins
	 * the two predicates to the same set of dimensions. So renaming a param, or
	 * swapping two of them here and in fromQuery() below - behaviour-preserving as
	 * that is - turns the PHP runner red until the client's emit order matches
	 * again. Add a dimension in both places, in the same position, or not at all.
	 *
	 * @param array<int, true> $labels
	 * @param array<string, true> $assignees
	 * @param array<int, true> $priorities
	 * @param array<string, true> $types
	 * @param array<string, true> $estimates
	 * @param array<string, true> $owners
	 * @param array<string, true> $reviews
	 */
	private function __construct(
		private array $labels,
		private array $assignees,
		private array $priorities,
		private array $types,
		private array $estimates,
		private array $owners,
		private array $reviews,
		private ?string $due,
		private ?string $done,
		private ?string $waiting,
		private ?string $blocked,
		private ?string $checklist,
		private ?string $startDate,
		private ?string $subcard,
		private ?string $comments,
	) {
	}

	/**
	 * Decode the flat short-key query params into a filter. NEVER throws and never
	 * rejects: every value is `mixed` (a malformed query string like `?fl[]=1`
	 * hands the dispatcher an array), unknown keys are simply absent from the map
	 * this reads, and an unrecognised value inside a known dimension is dropped so
	 * the dimension imposes no constraint.
	 *
	 * @param array<string, mixed> $query the raw `fl/fa/fp/…` params
	 */
	public static function fromQuery(array $query): self {
		return new self(
			self::intSet($query['fl'] ?? null),
			self::stringSet($query['fa'] ?? null),
			self::prioritySet($query['fp'] ?? null),
			self::stringSet($query['ft'] ?? null, self::TYPES),
			self::stringSet($query['fe'] ?? null),
			self::stringSet($query['fo'] ?? null),
			self::stringSet($query['fr'] ?? null, self::REVIEWS),
			self::oneOf($query['fd'] ?? null, self::DUE),
			self::oneOf($query['fs'] ?? null, self::DONE),
			self::oneOf($query['fw'] ?? null, self::WAITING),
			self::oneOf($query['fb'] ?? null, self::BLOCKED),
			self::oneOf($query['fk'] ?? null, self::CHECKLIST),
			self::oneOf($query['fsd'] ?? null, self::START),
			self::oneOf($query['fsc'] ?? null, self::SUBCARD),
			self::oneOf($query['fcm'] ?? null, self::COMMENTS),
		);
	}

	/**
	 * True when no dimension carries a constraint - i.e. the filter would keep
	 * every row, so the caller can skip the pass entirely. Mirrors the client's
	 * `filterIsEmpty()`.
	 */
	public function isEmpty(): bool {
		return $this->labels === []
			&& $this->assignees === []
			&& $this->priorities === []
			&& $this->types === []
			&& $this->estimates === []
			&& $this->owners === []
			&& $this->reviews === []
			&& $this->due === null
			&& $this->done === null
			&& $this->waiting === null
			&& $this->blocked === null
			&& $this->checklist === null
			&& $this->startDate === null
			&& $this->subcard === null
			&& $this->comments === null;
	}

	/**
	 * Evaluate one serialized card summary. AND across dimensions, OR within each;
	 * an empty dimension imposes no constraint. Line-for-line the JS predicate.
	 *
	 * `$nowMs` is a Unix timestamp in MILLISECONDS, injected so the relative due /
	 * start-date windows are stable across a whole pass (and testable) - the same
	 * reason `makePredicate` takes `now`.
	 *
	 * @param array<string, mixed> $card
	 */
	public function matches(array $card, int $nowMs): bool {
		$weekAhead = $nowMs + self::WEEK_MS;

		// Labels (OR within): card must carry at least one selected label.
		if ($this->labels !== []) {
			$hit = false;
			foreach (self::listOf($card['labelIds'] ?? null) as $id) {
				if (is_int($id) || is_string($id) || is_float($id)) {
					if (isset($this->labels[(int)$id])) {
						$hit = true;
						break;
					}
				}
			}
			if (!$hit) {
				return false;
			}
		}

		// Assignees (OR within): any selected uid, or the UNASSIGNED sentinel for
		// cards with no assignee at all.
		if ($this->assignees !== []) {
			$ids = self::listOf($card['assigneeIds'] ?? null);
			$matchesUid = false;
			foreach ($ids as $uid) {
				if (is_string($uid) && isset($this->assignees[$uid])) {
					$matchesUid = true;
					break;
				}
			}
			$matchesNone = isset($this->assignees[self::UNASSIGNED]) && $ids === [];
			if (!$matchesUid && !$matchesNone) {
				return false;
			}
		}

		// Priorities (OR within): card.priority (0..4) in the selected set.
		if ($this->priorities !== [] && !isset($this->priorities[(int)($card['priority'] ?? 0)])) {
			return false;
		}

		// Types (OR within): the built-in card type. A typeless card ('') never
		// matches a type facet.
		if ($this->types !== []) {
			$type = $card['type'] ?? '';
			if (!is_string($type) || !isset($this->types[$type])) {
				return false;
			}
		}

		// Estimates (OR within): the card's raw scale token, or the UNESTIMATED
		// sentinel for cards with no estimate. An off-scale legacy token still
		// matches if explicitly selected.
		if ($this->estimates !== []) {
			$estimate = $card['estimate'] ?? '';
			$estimate = is_string($estimate) ? $estimate : '';
			$matchesToken = $estimate !== '' && isset($this->estimates[$estimate]);
			$matchesNone = $estimate === '' && isset($this->estimates[self::UNESTIMATED]);
			if (!$matchesToken && !$matchesNone) {
				return false;
			}
		}

		// Owners (OR within): the card's owner uid. Owner is always set, so there
		// is no "none" sentinel here.
		if ($this->owners !== []) {
			$owner = $card['owner'] ?? null;
			if (!is_string($owner) || !isset($this->owners[$owner])) {
				return false;
			}
		}

		// Review state (OR within): the derived review flag, or the 'none' sentinel
		// for a card with no review requested (reviewState == null).
		if ($this->reviews !== []) {
			$review = $card['reviewState'] ?? null;
			$ok = $review === null
				? isset($this->reviews[self::REVIEW_NONE])
				: (is_string($review) && isset($this->reviews[$review]));
			if (!$ok) {
				return false;
			}
		}

		// Due (single-select): overdue / this-week / no-due-date.
		if ($this->due !== null) {
			$raw = self::str($card['duedate'] ?? null);
			if ($this->due === 'none') {
				if ($raw !== '') {
					return false;
				}
			} else {
				if ($raw === '') {
					return false;
				}
				$ts = self::toMillis($raw);
				if ($ts === null) {
					return false;
				}
				if ($this->due === 'overdue' && !($ts < $nowMs)) {
					return false;
				}
				if ($this->due === 'week' && !($ts >= $nowMs && $ts <= $weekAhead)) {
					return false;
				}
			}
		}

		// Done state (tri-state): doneAt > 0 means done.
		if ($this->done !== null) {
			$isDone = ((int)($card['doneAt'] ?? 0)) > 0;
			if ($this->done === 'done' && !$isDone) {
				return false;
			}
			if ($this->done === 'open' && $isDone) {
				return false;
			}
		}

		// Waiting on client (#3746, tri-state): the derived summary flag.
		if ($this->waiting !== null) {
			$isWaiting = !empty($card['waitingOnExternal']);
			if ($this->waiting === 'waiting' && !$isWaiting) {
				return false;
			}
			if ($this->waiting === 'not_waiting' && $isWaiting) {
				return false;
			}
		}

		// Blocked (single-select): the derived summary flag.
		if ($this->blocked !== null) {
			$isBlocked = !empty($card['blocked']);
			if ($this->blocked === 'blocked' && !$isBlocked) {
				return false;
			}
			if ($this->blocked === 'not_blocked' && $isBlocked) {
				return false;
			}
		}

		// Checklist (single-select), off the derived {total,done} progress.
		if ($this->checklist !== null) {
			$progress = is_array($card['checklist'] ?? null) ? $card['checklist'] : [];
			$total = (int)($progress['total'] ?? 0);
			$cdone = (int)($progress['done'] ?? 0);
			if ($this->checklist === 'has' && !($total > 0)) {
				return false;
			}
			if ($this->checklist === 'incomplete' && !($total > 0 && $cdone < $total)) {
				return false;
			}
			if ($this->checklist === 'complete' && !($total > 0 && $cdone === $total)) {
				return false;
			}
			if ($this->checklist === 'none' && $total !== 0) {
				return false;
			}
		}

		// Start date (single-select): none / started (<= now) / upcoming (> now),
		// mirroring the due-date now handling.
		if ($this->startDate !== null) {
			$raw = self::str($card['startDate'] ?? null);
			if ($this->startDate === 'none') {
				if ($raw !== '') {
					return false;
				}
			} else {
				if ($raw === '') {
					return false;
				}
				$ts = self::toMillis($raw);
				if ($ts === null) {
					return false;
				}
				if ($this->startDate === 'started' && !($ts <= $nowMs)) {
					return false;
				}
				if ($this->startDate === 'upcoming' && !($ts > $nowMs)) {
					return false;
				}
			}
		}

		// Sub-card relationship, off parentCardId + the derived childProgress.
		if ($this->subcard !== null) {
			$parentId = $card['parentCardId'] ?? null;
			$childProgress = is_array($card['childProgress'] ?? null) ? $card['childProgress'] : [];
			$childTotal = (int)($childProgress['total'] ?? 0);
			if ($this->subcard === 'top_level' && $parentId !== null) {
				return false;
			}
			if ($this->subcard === 'parent' && !($childTotal > 0)) {
				return false;
			}
			if ($this->subcard === 'child' && $parentId === null) {
				return false;
			}
		}

		// Comments (single-select): has / none, off the derived count.
		if ($this->comments !== null) {
			$n = (int)($card['commentCount'] ?? 0);
			if ($this->comments === 'has' && !($n > 0)) {
				return false;
			}
			if ($this->comments === 'none' && $n !== 0) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Split one comma-joined query value into trimmed, non-empty tokens. Mirrors
	 * the client's `csv()` in `queryToFilter()`, including taking the FIRST value
	 * when the dispatcher hands us an array (`?fl[]=1&fl[]=2`).
	 *
	 * @return list<string>
	 */
	private static function tokens(mixed $raw): array {
		if (is_array($raw)) {
			$raw = $raw === [] ? null : reset($raw);
		}
		if ($raw === null || is_array($raw) || is_object($raw) || is_bool($raw)) {
			return [];
		}
		$parts = explode(',', (string)$raw);
		$out = [];
		foreach ($parts as $part) {
			$part = trim($part);
			if ($part !== '') {
				$out[] = $part;
			}
		}
		return $out;
	}

	/**
	 * Numeric-id membership map (labels). A non-numeric token is dropped, matching
	 * the client's `Number.isFinite` guard.
	 *
	 * @return array<int, true>
	 */
	private static function intSet(mixed $raw): array {
		$out = [];
		foreach (self::tokens($raw) as $token) {
			if (is_numeric($token)) {
				$out[(int)$token] = true;
			}
		}
		return $out;
	}

	/**
	 * String membership map, optionally whitelisted against the dimension's known
	 * values (an unknown value is dropped, leaving the dimension unconstrained).
	 *
	 * @param list<string>|null $allowed
	 * @return array<string, true>
	 */
	private static function stringSet(mixed $raw, ?array $allowed = null): array {
		$out = [];
		foreach (self::tokens($raw) as $token) {
			if ($allowed !== null && !in_array($token, $allowed, true)) {
				continue;
			}
			$out[$token] = true;
		}
		return $out;
	}

	/**
	 * Priority membership map: integers 0..4 only, matching the client's range
	 * guard. Anything else is dropped.
	 *
	 * @return array<int, true>
	 */
	private static function prioritySet(mixed $raw): array {
		$out = [];
		foreach (self::tokens($raw) as $token) {
			if (!is_numeric($token)) {
				continue;
			}
			// Integer-VALUED (not integer-spelled), so '03' and '3.0' are accepted
			// exactly as the client's Number()/Number.isInteger() pair accepts them.
			$n = (float)$token;
			if ($n === floor($n) && $n >= 0 && $n <= 4) {
				$out[(int)$n] = true;
			}
		}
		return $out;
	}

	/**
	 * A single-select value, or null when it is missing or not one this version
	 * knows - so an older/newer client's value silently imposes no constraint
	 * rather than dropping every row.
	 *
	 * @param list<string> $allowed
	 */
	private static function oneOf(mixed $raw, array $allowed): ?string {
		$tokens = self::tokens($raw);
		if ($tokens === []) {
			return null;
		}
		return in_array($tokens[0], $allowed, true) ? $tokens[0] : null;
	}

	/** A scalar card field as a string; anything else reads as absent. */
	private static function str(mixed $value): string {
		return is_string($value) ? $value : '';
	}

	/**
	 * A card list field as a genuine list; anything else reads as empty.
	 *
	 * @return list<mixed>
	 */
	private static function listOf(mixed $value): array {
		return is_array($value) ? array_values($value) : [];
	}

	/**
	 * Parse a summary date field (always ATOM in the payload) to Unix
	 * MILLISECONDS, matching the client's `new Date(raw).getTime()`. Unparseable
	 * reads as null - the same NaN branch the JS predicate rejects on.
	 */
	private static function toMillis(string $raw): ?int {
		$ts = strtotime($raw);
		return $ts === false ? null : $ts * 1000;
	}
}
