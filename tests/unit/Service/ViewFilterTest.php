<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Controller\ViewController;
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

	/** @var array<string, string>|null memoized {@see self::wireKeys()} */
	private static ?array $wireKeys = null;

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
	 * The dimension -> short-key map, PARSED FROM THE ONE REAL SOURCE: the body of
	 * `filterToQuery()` in src/composables/useBoardFilters.js.
	 *
	 * It used to be a hand-copied literal here, and that copy was the hole. Rename a
	 * short key symmetrically in `filterToQuery()` + `queryToFilter()` and the JS
	 * runner stays green (its round trip is self-consistent) - while this file kept
	 * encoding the OLD key, so it stayed green too, and the server silently stopped
	 * reading that dimension. Deriving the map means the encoder here now emits
	 * whatever the client emits: rename `fl` to `fll` and PHP sends `fll`,
	 * {@see ViewFilter::fromQuery()} still reads `fl`, and the first labels case in
	 * testGoldenFixtureParity() goes red. No extra assertion needed - deleting the
	 * duplicate copy of the truth is the guard.
	 *
	 * @return array<string, string> dimension name => short query key, in emit order
	 */
	private static function wireKeys(): array {
		if (self::$wireKeys !== null) {
			return self::$wireKeys;
		}
		$src = file_get_contents(__DIR__ . '/../../../src/composables/useBoardFilters.js');
		self::assertIsString($src, 'the client filter composable must be readable');

		$start = strpos($src, 'export function filterToQuery(');
		$end = strpos($src, 'export function queryToFilter(');
		self::assertIsInt($start, 'filterToQuery() must still exist in useBoardFilters.js');
		self::assertIsInt($end, 'queryToFilter() must still exist in useBoardFilters.js');
		self::assertGreaterThan($start, $end, 'filterToQuery() must precede queryToFilter()');
		$body = substr($src, $start, $end - $start);

		// Each emit line reads `if (ser.<dimension>…) q.<key> = …`.
		$count = preg_match_all('/ser\.(\w+)[^\n]*?q\.(\w+) =/', $body, $m, PREG_SET_ORDER);
		self::assertNotFalse($count, 'the filterToQuery() emit-line pattern must compile');
		self::assertGreaterThan(0, $count, 'no emit lines parsed out of filterToQuery()');

		$map = [];
		foreach ($m as $match) {
			$map[$match[1]] = $match[2];
		}
		return self::$wireKeys = $map;
	}

	/**
	 * Encode a serialized filter into the flat short-key query the client's
	 * `filterToQuery()` produces - the exact wire format the feed endpoint reads.
	 *
	 * @param array<string, mixed> $serialized
	 * @return array<string, string>
	 */
	private static function toQuery(array $serialized): array {
		$query = [];
		foreach (self::wireKeys() as $dimension => $key) {
			$value = $serialized[$dimension] ?? null;
			if (is_array($value)) {
				if ($value !== []) {
					$query[$key] = implode(',', array_map(static fn ($v): string => (string)$v, $value));
				}
			} elseif (is_string($value) && $value !== '') {
				$query[$key] = $value;
			}
		}
		return $query;
	}

	/**
	 * The short keys `ViewController::cards()` actually FORWARDS, parsed out of the
	 * hand-written `$query` literal in its body, in literal order.
	 *
	 * The controller binds the wire in two independent places - the param list and
	 * this literal that copies each param into the array handed to
	 * {@see ViewFilter::fromQuery()} - and only the second one decides what the
	 * filter sees. Reflection over the signature cannot see it: drop
	 * `'fsc' => $fsc,` from the literal while keeping `mixed $fsc = null` and the
	 * dispatcher still binds the param, the filter still never receives it, and the
	 * server silently stops constraining `subcard`. That is the #9862 symptom with
	 * the param half left intact, so the guard has to read this half too.
	 *
	 * Each entry must also forward its OWN param: `'fsd' => $fsc` would compile,
	 * type-check and quietly cross two dimensions.
	 *
	 * @return list<string> the literal's keys, in the order they appear
	 */
	private static function feedQueryLiteralKeys(): array {
		$src = file_get_contents(__DIR__ . '/../../../lib/Controller/ViewController.php');
		self::assertIsString($src, 'the feed controller must be readable');

		$method = strpos($src, 'public function cards(');
		self::assertIsInt($method, 'ViewController::cards() must still exist');
		$start = strpos($src, '$query = [', $method);
		self::assertIsInt($start, 'cards() must still build a $query array literal');
		$end = strpos($src, '];', $start);
		self::assertIsInt($end, 'the $query literal must be terminated');
		$literal = substr($src, $start, $end - $start);

		$count = preg_match_all('/\'(\w+)\'\s*=>\s*\$(\w+)/', $literal, $m, PREG_SET_ORDER);
		self::assertNotFalse($count, 'the $query literal pattern must compile');
		self::assertGreaterThan(0, $count, 'no entries parsed out of the $query literal');

		$keys = [];
		foreach ($m as $match) {
			self::assertSame(
				$match[1],
				$match[2],
				"the '{$match[1]}' entry of cards()'s \$query forwards \${$match[2]} - "
				. 'each key must carry its own param, or two dimensions are crossed',
			);
			$keys[] = $match[1];
		}
		return $keys;
	}

	/**
	 * Every card id in the fixture, in fixture order - what a filter that constrains
	 * nothing must return. Derived so adding a card to the fixture does not silently
	 * weaken the tolerance assertions below into a stale subset.
	 *
	 * @return list<int>
	 */
	private static function allCardIds(): array {
		return array_map(static fn (array $card): int => (int)$card['id'], self::$fixture['cards']);
	}

	/**
	 * The filter's dimensions, taken from {@see ViewFilter}'s own constructor
	 * signature rather than a literal - so a 17th dimension added to the class
	 * without a golden case, a wire key, or a controller param turns this red.
	 * The JS half already derives its list from `createFilterState()`.
	 *
	 * @return list<string>
	 */
	private static function filterDimensions(): array {
		$ctor = (new \ReflectionClass(ViewFilter::class))->getConstructor();
		self::assertNotNull($ctor, 'ViewFilter must declare a constructor');
		return array_map(
			static fn (\ReflectionParameter $p): string => $p->getName(),
			$ctor->getParameters(),
		);
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
	public function testTheGoldenFixtureExercisesAllSixteenDimensions(): void {
		$dimensions = self::filterDimensions();
		self::assertCount(16, $dimensions);

		$covered = [];
		foreach (self::$fixture['cases'] as $case) {
			foreach (array_keys($case['filter']) as $key) {
				$covered[$key] = true;
			}
		}
		self::assertSame([], array_values(array_diff($dimensions, array_keys($covered))));
	}

	/**
	 * The golden fixture pins the two PREDICATES against each other, but it cannot
	 * see the wire: it feeds `ViewFilter::fromQuery()` an array built in this file.
	 * The feed endpoint builds its array in TWO steps, and a key has to survive
	 * both: the dispatcher fills `cards()`'s DECLARED PARAMETERS, and the body then
	 * copies each of them into the `$query` literal it hands the filter. Lose the
	 * key on either side - no `mixed $fXX = null` param, or no `'fXX' => $fXX,`
	 * entry - and the filter never receives that dimension. The server silently
	 * stops constraining it, and because the client re-filters whatever it
	 * receives, nothing looks wrong until an account has more readable cards than
	 * the feed's cap: the exact regression #9862 fixed.
	 *
	 * So this asserts BOTH halves - the signature by reflection, the literal by
	 * parsing the method body ({@see self::feedQueryLiteralKeys()}) - which is the
	 * cheap honest guard for the whole binding, without spending a round trip per
	 * key on the shared e2e backend.
	 */
	public function testEveryWireKeyIsBoundOnTheFeedController(): void {
		$map = self::wireKeys();

		$params = array_map(
			static fn (\ReflectionParameter $p): string => $p->getName(),
			(new \ReflectionMethod(ViewController::class, 'cards'))->getParameters(),
		);
		foreach ($map as $dimension => $key) {
			self::assertContains(
				$key,
				$params,
				"the client emits '{$key}' for the '{$dimension}' filter, but ViewController::cards() "
				. "declares no \${$key} param - the dispatcher will never pass it through",
			);
		}

		// A bound param that the body never copies into $query is just as invisible
		// to the filter as a missing param, so the forwarding literal is pinned too
		// - exactly, and in the same order the client emits.
		self::assertSame(
			array_values($map),
			self::feedQueryLiteralKeys(),
			'the $query literal in ViewController::cards() must forward every short key the client '
			. 'emits, and nothing else - a param bound but not forwarded leaves that dimension '
			. 'unconstrained on the server',
		);

		// …and the map covers exactly the dimensions ViewFilter itself carries, in
		// the same order. A 17th dimension added on one side only turns this red.
		self::assertSame(self::filterDimensions(), array_keys($map));
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
		self::assertSame(self::allCardIds(), self::survivors($filter), 'an unknown key must never drop a row');

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
			'far' => 'not_archived', // deliberately NOT an archived option
		]);
		self::assertTrue($filter->isEmpty());
		self::assertSame(self::allCardIds(), self::survivors($filter));
		// …and specifically: an unrecognised archived value must not read as an
		// opt-in, or an old client's typo would un-hide every archived card.
		self::assertFalse($filter->includesArchived());
	}

	/**
	 * The archived dimension is the ONE inverted facet, so its contract is pinned
	 * separately from the golden cases.
	 *
	 * `null` (the default) must stay EMPTY. If "hide archived" were spelled as a
	 * filter value, a fresh filter would no longer be empty: ViewService::findMine()
	 * would stop short-circuiting the pass, and every View saved before this shipped
	 * would silently change meaning. So the baseline exclusion lives in the caller,
	 * and this class only reports whether the caller must stop applying it.
	 */
	public function testTheArchivedFacetIsAnOptInThatLeavesAnEmptyFilterEmpty(): void {
		$live = ['id' => 1];
		$archived = ['id' => 2, 'archived' => true];

		// Default: no constraint here at all - the predicate keeps BOTH rows, and
		// the caller is told to keep excluding.
		$default = ViewFilter::fromQuery([]);
		self::assertTrue($default->isEmpty());
		self::assertFalse($default->includesArchived());
		self::assertTrue($default->matches($live, 0));
		self::assertTrue($default->matches($archived, 0));

		// include: opts the caller out of excluding, but constrains nothing.
		$include = ViewFilter::fromQuery(['far' => 'include']);
		self::assertFalse($include->isEmpty());
		self::assertTrue($include->includesArchived());
		self::assertTrue($include->matches($live, 0));
		self::assertTrue($include->matches($archived, 0));

		// only: opts out of excluding AND narrows to the archived rows.
		$only = ViewFilter::fromQuery(['far' => 'only']);
		self::assertFalse($only->isEmpty());
		self::assertTrue($only->includesArchived());
		self::assertFalse($only->matches($live, 0));
		self::assertTrue($only->matches($archived, 0));

		// A row with an explicitly false flag is live, and so is a sparse row with
		// no flag at all (an older cached summary).
		self::assertFalse($only->matches(['id' => 3, 'archived' => false], 0));
		self::assertFalse($only->matches(['id' => 4], 0));
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
