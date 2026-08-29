<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Service\ViewFilter;
use PHPUnit\Framework\TestCase;

/**
 * The PHP half of the View filter's PHP<->JS parity contract (#9862).
 *
 * {@see ViewFilter} exists so the cross-board feed's hard cap slices the MATCHING
 * set rather than the first window of the readable set. Because the CLIENT still
 * re-filters the rows it receives, the two predicates must agree - and two
 * predicates that must agree are two predicates that WILL drift. So the same
 * golden fixture (tests/fixtures/board-filter-parity.json) is asserted from both
 * CI runners: here under PHPUnit, and from tests/unit/boardFilters.test.mjs under
 * `npm run test:unit`. Edit one predicate without the other and exactly one of the
 * two jobs goes red.
 *
 * Both sides read the fixture's SERIALIZED filter, encode it with the same short
 * keys `filterToQuery()` emits, and decode it back - so this pins the wire format
 * and the decode, not just the matching.
 */
class ViewFilterTest extends TestCase {
	/** @var array{now: int, cards: list<array<string, mixed>>, cases: list<array{name: string, filter: array<string, mixed>, expected: list<int>}>} */
	private static array $fixture;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$raw = file_get_contents(__DIR__ . '/../../fixtures/board-filter-parity.json');
		self::assertIsString($raw, 'the golden parity fixture must be readable');
		$decoded = json_decode($raw, true);
		self::assertIsArray($decoded, 'the golden parity fixture must be valid JSON');
		/** @var array{now: int, cards: list<array<string, mixed>>, cases: list<array{name: string, filter: array<string, mixed>, expected: list<int>}>} $decoded */
		self::$fixture = $decoded;
	}

	/**
	 * Encode a serialized filter into the flat short-key query the client's
	 * `filterToQuery()` produces - the exact wire format the feed endpoint reads.
	 *
	 * @param array<string, mixed> $serialized
	 * @return array<string, string>
	 */
	private static function toQuery(array $serialized): array {
		$multi = [
			'labels' => 'fl', 'assignees' => 'fa', 'priorities' => 'fp',
			'types' => 'ft', 'estimates' => 'fe', 'owners' => 'fo', 'reviews' => 'fr',
		];
		$single = [
			'due' => 'fd', 'done' => 'fs', 'waiting' => 'fw', 'blocked' => 'fb',
			'checklist' => 'fk', 'startDate' => 'fsd', 'subcard' => 'fsc', 'comments' => 'fcm',
		];

		$query = [];
		foreach ($multi as $dimension => $key) {
			$values = $serialized[$dimension] ?? [];
			if (is_array($values) && $values !== []) {
				$query[$key] = implode(',', array_map(static fn ($v): string => (string)$v, $values));
			}
		}
		foreach ($single as $dimension => $key) {
			$value = $serialized[$dimension] ?? null;
			if (is_string($value) && $value !== '') {
				$query[$key] = $value;
			}
		}
		return $query;
	}

	/**
	 * Run one filter over the fixture's cards and collect the surviving ids.
	 *
	 * @return list<int>
	 */
	private static function survivors(ViewFilter $filter): array {
		$out = [];
		foreach (self::$fixture['cards'] as $card) {
			if ($filter->matches($card, (int)self::$fixture['now'])) {
				$out[] = (int)$card['id'];
			}
		}
		return $out;
	}

	/**
	 * Every golden case, run through the real wire path: serialized filter ->
	 * short-key query -> ViewFilter::fromQuery() -> matches(). The JS runner
	 * asserts the identical expectations over the identical cards, so a drift in
	 * either predicate turns exactly one of the two CI jobs red.
	 */
	public function testGoldenFixtureParity(): void {
		self::assertNotEmpty(self::$fixture['cases'], 'the fixture must carry cases');
		foreach (self::$fixture['cases'] as $case) {
			$filter = ViewFilter::fromQuery(self::toQuery($case['filter']));
			self::assertSame($case['expected'], self::survivors($filter), 'golden case: ' . $case['name']);
		}
	}

	/**
	 * The fixture is only worth what it covers: a dimension that loses its last
	 * case could drift silently while both runners stay green. The JS runner
	 * asserts the mirror image of this.
	 */
	public function testTheGoldenFixtureExercisesAllFifteenDimensions(): void {
		$dimensions = [
			'labels', 'assignees', 'priorities', 'types', 'estimates', 'owners',
			'reviews', 'due', 'done', 'waiting', 'blocked', 'checklist',
			'startDate', 'subcard', 'comments',
		];
		self::assertCount(15, $dimensions);

		$covered = [];
		foreach (self::$fixture['cases'] as $case) {
			foreach (array_keys($case['filter']) as $key) {
				$covered[$key] = true;
			}
		}
		self::assertSame([], array_values(array_diff($dimensions, array_keys($covered))));
	}

	/**
	 * An empty filter constrains nothing, so ViewService::findMine() can skip the
	 * pass entirely rather than walking a set that can reach the cap.
	 */
	public function testAnEmptyQueryIsRecognisedAsNoConstraint(): void {
		self::assertTrue(ViewFilter::fromQuery([])->isEmpty());
		// Present-but-blank params (a cleared filter still spelling out its keys)
		// are the same thing.
		self::assertTrue(ViewFilter::fromQuery(['fl' => '', 'fa' => ' , ', 'fd' => null])->isEmpty());
		self::assertFalse(ViewFilter::fromQuery(['fl' => '7'])->isEmpty());
	}

	/**
	 * The tolerance contract (#9862): an unknown key - an older or newer client, a
	 * hand-edited URL - is IGNORED. Never a rejection, and never a reason to drop a
	 * row. The client re-filters what it receives, so the server predicate is a
	 * superset guard: erring towards keeping a row is harmless, dropping one is a
	 * silent correctness bug.
	 */
	public function testAnUnknownQueryKeyIsIgnoredAndNeverDropsARow(): void {
		$filter = ViewFilter::fromQuery(['fzz' => 'from-a-newer-client', 'sortMode' => 'due']);
		self::assertTrue($filter->isEmpty(), 'an unknown key must impose no constraint');
		self::assertSame([1, 2, 3, 4, 5], self::survivors($filter), 'an unknown key must never drop a row');

		// And an unknown key alongside a known one leaves the known one intact.
		$mixed = ViewFilter::fromQuery(['fzz' => 'nonsense', 'fl' => '11']);
		self::assertFalse($mixed->isEmpty());
		self::assertSame([1, 3], self::survivors($mixed));
	}

	/**
	 * An unrecognised VALUE inside a known dimension is dropped, leaving that
	 * dimension unconstrained - exactly what the client's `applyFilter()` does with
	 * a hand-edited URL. Rejecting instead would drop every row, which is the one
	 * failure mode the superset rule forbids.
	 */
	public function testAnUnknownValueInAKnownDimensionLeavesItUnconstrained(): void {
		$filter = ViewFilter::fromQuery([
			'fd' => 'someday',        // not a due option
			'fs' => 'maybe',          // not a done option
			'ft' => 'epic',           // not a filterable type
			'fr' => 'rubber_stamped', // not a review state
			'fsc' => 'sibling',       // not a sub-card relation
		]);
		self::assertTrue($filter->isEmpty());
		self::assertSame([1, 2, 3, 4, 5], self::survivors($filter));
	}

	/**
	 * The params are typed `mixed` all the way down because a malformed query string
	 * hands the dispatcher arrays and other junk (`?fl[]=1`). Decoding must absorb
	 * that rather than throw - this is a read path an older or newer client must not
	 * be able to hard-fail.
	 */
	public function testMalformedParamTypesDecodeInsteadOfThrowing(): void {
		// An array param: the client's csv() takes the FIRST value; so do we.
		$array = ViewFilter::fromQuery(['fl' => ['11', '12']]);
		self::assertFalse($array->isEmpty());
		self::assertTrue($array->matches(self::$fixture['cards'][2], (int)self::$fixture['now']), 'card 3 carries label 11');

		// Nested arrays, booleans, objects and an empty array all read as absent.
		$junk = ViewFilter::fromQuery([
			'fl' => [[]],
			'fa' => true,
			'fp' => new \stdClass(),
			'fd' => [],
		]);
		self::assertTrue($junk->isEmpty());
	}

	/**
	 * The relative windows read the INJECTED `now`, not the wall clock - which is
	 * what makes them testable and stable across a whole feed pass.
	 */
	public function testRelativeWindowsFollowTheInjectedNow(): void {
		$card = ['id' => 1, 'duedate' => '2026-01-03T00:00:00+00:00'];
		$overdue = ViewFilter::fromQuery(['fd' => 'overdue']);
		$week = ViewFilter::fromQuery(['fd' => 'week']);

		// now = 2026-01-01 → the card is due in two days: in the week window.
		$before = 1767225600000;
		self::assertFalse($overdue->matches($card, $before));
		self::assertTrue($week->matches($card, $before));

		// now = 2026-02-01 → the same card is now overdue, and out of the window.
		$after = 1769904000000;
		self::assertTrue($overdue->matches($card, $after));
		self::assertFalse($week->matches($card, $after));
	}

	/**
	 * Priority parity detail: the client accepts any integer-VALUED token in 0..4
	 * (`Number()` + `Number.isInteger()`), so '03' and '3.0' are the same selection
	 * as '3'. A fractional or out-of-range token is dropped, leaving the dimension
	 * unconstrained rather than matching nothing.
	 */
	public function testPriorityTokensAreParsedLikeTheClientDoes(): void {
		$card = ['id' => 1, 'priority' => 3];
		foreach (['3', '03', '3.0'] as $token) {
			self::assertTrue(ViewFilter::fromQuery(['fp' => $token])->matches($card, 0), "token {$token}");
		}
		// Out of range / fractional / non-numeric are all dropped.
		self::assertTrue(ViewFilter::fromQuery(['fp' => '9,3.5,high'])->isEmpty());
	}

	/**
	 * A card missing an enrichment field entirely (an older cached row, a partial
	 * summary) must not blow up the predicate - the absent field reads as its empty
	 * value, exactly as `card.x ?? default` does on the client.
	 */
	public function testASparseCardRowIsTreatedAsAllDefaultsNotAnError(): void {
		$sparse = ['id' => 99];
		self::assertTrue(ViewFilter::fromQuery(['fa' => ViewFilter::UNASSIGNED])->matches($sparse, 0));
		self::assertTrue(ViewFilter::fromQuery(['fe' => ViewFilter::UNESTIMATED])->matches($sparse, 0));
		self::assertTrue(ViewFilter::fromQuery(['fr' => ViewFilter::REVIEW_NONE])->matches($sparse, 0));
		self::assertTrue(ViewFilter::fromQuery(['fp' => '0'])->matches($sparse, 0));
		self::assertTrue(ViewFilter::fromQuery(['fd' => 'none'])->matches($sparse, 0));
		self::assertTrue(ViewFilter::fromQuery(['fs' => 'open'])->matches($sparse, 0));
		self::assertTrue(ViewFilter::fromQuery(['fk' => 'none'])->matches($sparse, 0));
		self::assertTrue(ViewFilter::fromQuery(['fsd' => 'none'])->matches($sparse, 0));
		self::assertTrue(ViewFilter::fromQuery(['fsc' => 'top_level'])->matches($sparse, 0));
		self::assertTrue(ViewFilter::fromQuery(['fcm' => 'none'])->matches($sparse, 0));
		// …and it does NOT masquerade as a match for a positive constraint.
		self::assertFalse(ViewFilter::fromQuery(['fl' => '1'])->matches($sparse, 0));
		self::assertFalse(ViewFilter::fromQuery(['fo' => 'alice'])->matches($sparse, 0));
	}
}
