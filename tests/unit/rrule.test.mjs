// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10045 — the recurrence editor used to re-serialize every rule it saved from
// the five fields it parses (FREQ / INTERVAL / BYDAY / COUNT / UNTIL), so a rule
// authored through the API with, say, BYMONTHDAY=1,15 came back as a bare
// FREQ=MONTHLY. The editor now asks isCustomRrule() first and leaves such a rule
// alone. These cases pin the classifier and the parse/serialize round trip.

import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
	rruleParts,
	isCustomRrule,
	parseRecurRrule,
	buildRecurRrule,
} from '../../src/utils/rrule.js'

test('rruleParts splits into an uppercase-keyed map and ignores junk segments', () => {
	assert.deepEqual(rruleParts('freq=WEEKLY;INTERVAL=2'), { FREQ: 'WEEKLY', INTERVAL: '2' })
	assert.deepEqual(rruleParts('FREQ=DAILY;;GARBAGE'), { FREQ: 'DAILY' })
	assert.deepEqual(rruleParts(''), {})
	assert.deepEqual(rruleParts(null), {})
})

test('a rule the editor fully models is NOT custom', () => {
	assert.equal(isCustomRrule('FREQ=WEEKLY;BYDAY=MO,WE'), false)
	assert.equal(isCustomRrule('FREQ=DAILY'), false)
	assert.equal(isCustomRrule('FREQ=MONTHLY;INTERVAL=3'), false)
	assert.equal(isCustomRrule('FREQ=YEARLY;COUNT=5'), false)
	assert.equal(isCustomRrule('FREQ=WEEKLY;UNTIL=20270101T000000Z'), false)
})

test('a part outside the modelled set makes the rule custom', () => {
	// The card's headline case: twice a month, authored through the API.
	assert.equal(isCustomRrule('FREQ=MONTHLY;BYMONTHDAY=1,15'), true)
	assert.equal(isCustomRrule('FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=29'), true)
	assert.equal(isCustomRrule('FREQ=MONTHLY;BYSETPOS=-1;BYDAY=FR'), true)
	assert.equal(isCustomRrule('FREQ=WEEKLY;WKST=SU'), true)
})

test('BYDAY is only round-trippable on a weekly rule, and never with an ordinal', () => {
	// "First Monday of the month" — the builder emits BYDAY for WEEKLY only, so
	// re-serializing this dropped it entirely.
	assert.equal(isCustomRrule('FREQ=MONTHLY;BYDAY=1MO'), true)
	assert.equal(isCustomRrule('FREQ=MONTHLY;BYDAY=MO'), true)
	// An ordinal the weekday checkboxes would mangle back into a bare "1MO".
	assert.equal(isCustomRrule('FREQ=WEEKLY;BYDAY=1MO'), true)
	assert.equal(isCustomRrule('FREQ=WEEKLY;BYDAY=-1FR'), true)
})

test('an end condition the parser cannot read back is custom, not silently dropped', () => {
	// Parsing these loses the end condition entirely, so re-serializing would
	// turn a bounded rule into an endless one.
	assert.equal(isCustomRrule('FREQ=WEEKLY;UNTIL=2027-03-01T00:00:00Z'), true)
	assert.equal(isCustomRrule('FREQ=WEEKLY;COUNT=many'), true)
	// Likewise an empty BYDAY, which would serialize back to nothing at all.
	assert.equal(isCustomRrule('FREQ=WEEKLY;BYDAY='), true)
})

test('an unrepresentable or missing FREQ is custom', () => {
	assert.equal(isCustomRrule('FREQ=HOURLY'), true)
	assert.equal(isCustomRrule('COUNT=3'), true)
	assert.equal(isCustomRrule(''), true)
	assert.equal(isCustomRrule(null), true)
	// Two end conditions at once: the single "Ends" select expresses neither.
	assert.equal(isCustomRrule('FREQ=WEEKLY;COUNT=3;UNTIL=20270101T000000Z'), true)
})

test('parse → build round-trips every rule the editor models, byte for byte', () => {
	for (const rrule of [
		'FREQ=DAILY',
		'FREQ=WEEKLY;INTERVAL=2',
		'FREQ=WEEKLY;BYDAY=MO,WE',
		'FREQ=WEEKLY;INTERVAL=3;BYDAY=TU,TH,SA',
		'FREQ=MONTHLY;COUNT=12',
		'FREQ=YEARLY;UNTIL=20301231T000000Z',
	]) {
		assert.equal(buildRecurRrule(parseRecurRrule(rrule)), rrule, rrule)
	}
})

// The editor decides "did the user touch the schedule?" by comparing what the
// controls build against a REBUILD of the pristine parse, not against the stored
// string — because these normalize. Comparing against the stored string would
// call every one of them a change, and the backend restarts the occurrences
// tally on any change to the rule text, semantically identical or not.
test('a semantically identical rule normalizes, so the pristine rebuild is the baseline', () => {
	const cases = {
		'FREQ=WEEKLY;INTERVAL=1;COUNT=5': 'FREQ=WEEKLY;COUNT=5',
		'BYDAY=MO;FREQ=WEEKLY': 'FREQ=WEEKLY;BYDAY=MO',
		'freq=weekly;byday=mo': 'FREQ=WEEKLY;BYDAY=MO',
		'FREQ=WEEKLY;COUNT=05': 'FREQ=WEEKLY;COUNT=5',
	}
	for (const [stored, canonical] of Object.entries(cases)) {
		assert.equal(isCustomRrule(stored), false, stored)
		assert.equal(buildRecurRrule(parseRecurRrule(stored)), canonical, stored)
		// Idempotent: a second pass through parse → build is a fixed point, so
		// the comparison the editor makes is stable.
		assert.equal(buildRecurRrule(parseRecurRrule(canonical)), canonical, canonical)
	}
})

test('UNTIL keeps its time of day instead of collapsing to midnight', () => {
	const parsed = parseRecurRrule('FREQ=WEEKLY;UNTIL=20270301T235959Z')
	assert.equal(parsed.endType, 'until')
	assert.equal(parsed.until, '2027-03-01')
	assert.equal(parsed.untilSuffix, 'T235959Z')
	assert.equal(buildRecurRrule(parsed), 'FREQ=WEEKLY;UNTIL=20270301T235959Z')

	// A DATE-valued UNTIL stays date-valued.
	const dateOnly = parseRecurRrule('FREQ=WEEKLY;UNTIL=20270301')
	assert.equal(dateOnly.untilSuffix, '')
	assert.equal(buildRecurRrule(dateOnly), 'FREQ=WEEKLY;UNTIL=20270301')

	// Changing only the date in the editor keeps the original time of day.
	assert.equal(
		buildRecurRrule({ ...parsed, until: '2027-04-02' }),
		'FREQ=WEEKLY;UNTIL=20270402T235959Z',
	)
})

test('parse falls back to safe defaults for a rule it will not be used on', () => {
	const parsed = parseRecurRrule('FREQ=HOURLY;BYHOUR=9')
	assert.equal(parsed.freq, 'WEEKLY')
	assert.equal(parsed.interval, 1)
	assert.equal(parsed.endType, 'forever')
})

test('build omits INTERVAL=1 and drops BYDAY on a non-weekly frequency', () => {
	assert.equal(
		buildRecurRrule({ freq: 'MONTHLY', interval: 1, weekdays: ['MO'], endType: 'forever' }),
		'FREQ=MONTHLY',
	)
	assert.equal(
		buildRecurRrule({ freq: 'WEEKLY', interval: 1, weekdays: [], endType: 'count', count: 4 }),
		'FREQ=WEEKLY;COUNT=4',
	)
	// endType 'until' with no date picked yet emits no end condition.
	assert.equal(
		buildRecurRrule({ freq: 'DAILY', interval: 2, endType: 'until', until: '' }),
		'FREQ=DAILY;INTERVAL=2',
	)
})
