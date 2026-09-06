// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #9862 — the JS half of the View filter's PHP↔JS parity contract.
//
// The View filter now runs on BOTH sides: the client keeps applying
// `makePredicate` over the rows it receives (that is what keeps chip editing
// instant), and the server applies the mirrored OCA\Kanso\Service\ViewFilter
// BEFORE the cross-board feed's 5000-row cap, so the cap slices the MATCHING set
// instead of the first window of the readable set.
//
// Two predicates that must agree are two predicates that WILL drift. So the same
// golden fixture — cards × filter states × expected surviving ids — is asserted
// from both CI runners: this file under `npm run test:unit` (node --test), and
// tests/unit/Service/ViewFilterTest.php under PHPUnit. If someone edits one
// predicate and not the other, exactly one of the two jobs goes red.
//
// Both sides consume the filter through the SAME wire encoding the board's
// shareable filter links already use, so this pins the decode too:
//   serialized filter → filterToQuery() short keys → decode → predicate.

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import {
	createFilterState,
	applyFilter,
	filterIsEmpty,
	filterToQuery,
	queryToFilter,
	makePredicate,
	serializeFilter,
	useFilterCount,
} from '../../src/composables/useBoardFilters.js'

const fixture = JSON.parse(
	readFileSync(new URL('../fixtures/board-filter-parity.json', import.meta.url), 'utf8'),
)

/** The full round trip a real request makes: serialized → query → state. */
function stateFor(serialized) {
	const state = createFilterState()
	applyFilter(state, queryToFilter(filterToQuery(serialized)))
	return state
}

for (const testCase of fixture.cases) {
	test(`golden filter parity — ${testCase.name}`, () => {
		const predicate = makePredicate(stateFor(testCase.filter), fixture.now)
		const survivors = fixture.cards.filter(predicate).map((card) => card.id)
		assert.deepEqual(survivors, testCase.expected)
	})
}

// The fixture is only worth what it covers: if a dimension loses its last case,
// PHP and JS could silently diverge on it and both runners would still be green.
test('the golden fixture exercises all 16 filter dimensions', () => {
	const allKeys = Object.keys(createFilterState())
	assert.equal(allKeys.length, 16, 'the filter state should carry exactly 16 dimensions')

	const covered = new Set()
	for (const testCase of fixture.cases) {
		for (const key of Object.keys(testCase.filter)) covered.add(key)
	}
	const missing = allKeys.filter((key) => !covered.has(key))
	assert.deepEqual(missing, [], `dimensions with no golden case: ${missing.join(', ')}`)
})

// The round trip must be lossless for every dimension, otherwise a "parity"
// pass could just be both sides receiving the same EMPTY filter.
test('the short-key wire encoding round-trips every dimension', () => {
	for (const testCase of fixture.cases) {
		const back = serializeFilter(stateFor(testCase.filter))
		assert.deepEqual(back, testCase.filter, `lost data round-tripping "${testCase.name}"`)
	}
})

// #10012 — `filterIsEmpty()` and `useFilterCount()` are the two per-dimension
// lists in useBoardFilters.js that NOTHING else pins: the golden fixture above
// covers createFilterState / serializeFilter / applyFilter / filterToQuery /
// queryToFilter / makePredicate (and, through the shared fixture,
// ViewFilter.php), but these two are read only by BoardView.vue and
// BoardFilterBar.vue.
//
// A dimension missing from `filterIsEmpty` is not a cosmetic bug: a filter that
// constrains ONLY that dimension then looks empty, which trips the server-side
// short-circuit documented at lib/Service/ViewFilter.php and returns UNFILTERED
// rows. So assert both, per dimension, off a state that carries exactly one
// dimension — reusing the fixture's single-dimension cases so the values stay
// the ones both runners already agree on.
//
// The dimension list is DERIVED from createFilterState(), so a 17th dimension is
// covered the moment it is added (and fails loudly if it has no single-dimension
// golden case to build from).
const singleDimensionCases = new Map()
for (const testCase of fixture.cases) {
	const keys = Object.keys(testCase.filter)
	if (keys.length === 1 && !singleDimensionCases.has(keys[0])) {
		singleDimensionCases.set(keys[0], testCase)
	}
}

/** How many constraints `useFilterCount` should report for a serialised filter. */
function expectedCount(serialized) {
	return Object.values(serialized)
		.reduce((n, v) => n + (Array.isArray(v) ? v.length : 1), 0)
}

test('every dimension has a single-dimension golden case to pin it with', () => {
	const missing = Object.keys(createFilterState())
		.filter((key) => !singleDimensionCases.has(key))
	assert.deepEqual(missing, [], `dimensions with no single-dimension golden case: ${missing.join(', ')}`)
})

test('filterIsEmpty() sees a constraint on every dimension', () => {
	// Control: a fresh state really is empty, so the assertions below cannot
	// pass just because filterIsEmpty always returns false.
	assert.equal(filterIsEmpty(serializeFilter(createFilterState())), true,
		'a fresh filter state should be empty')

	for (const key of Object.keys(createFilterState())) {
		const testCase = singleDimensionCases.get(key)
		const serialized = serializeFilter(stateFor(testCase.filter))
		assert.equal(filterIsEmpty(serialized), false,
			`filterIsEmpty() ignores the "${key}" dimension — a filter constraining only `
			+ 'it would look empty and the server would return unfiltered rows')
	}
})

test('useFilterCount() counts every dimension', () => {
	// Control: nothing set counts as nothing.
	assert.equal(useFilterCount(createFilterState()).value, 0,
		'a fresh filter state should count zero constraints')

	for (const key of Object.keys(createFilterState())) {
		const testCase = singleDimensionCases.get(key)
		const state = stateFor(testCase.filter)
		assert.equal(useFilterCount(state).value, expectedCount(testCase.filter),
			`useFilterCount() does not count the "${key}" dimension`)
	}
})
