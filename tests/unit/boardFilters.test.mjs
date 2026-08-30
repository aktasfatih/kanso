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
	filterToQuery,
	queryToFilter,
	makePredicate,
	serializeFilter,
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
test('the golden fixture exercises all 15 filter dimensions', () => {
	const allKeys = Object.keys(createFilterState())
	assert.equal(allKeys.length, 15, 'the filter state should carry exactly 15 dimensions')

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
