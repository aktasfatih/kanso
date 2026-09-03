// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10131 — the card's activity feed used to show a relative label only ("5 days
// ago"), which cannot answer "when exactly?". The exact stamp now sits next to
// it, and these cases pin the three things that make that safe:
//
//   1. Length awareness — a today event renders the clock alone, so a 50-row
//      feed does not become a wall of repeated dates; anything older carries
//      the date too.
//   2. Absent timestamps degrade to an empty label. The feed renders whatever
//      the change log hands it, and a 0 / null / unparseable value must NOT
//      become "1 Jan 1970", "NaN", or a thrown error — a throw here would blank
//      the whole modal (and trip the console-error guard in the e2e spec).
//   3. `datetime` is a stable machine-readable UTC instant, independent of the
//      viewer's zone.
//
// Every case builds its inputs from LOCAL date components against a fixed
// reference "now" the test owns, so nothing here depends on the machine's
// timezone — hardcoded wall-clock strings would flip between a developer's box
// and CI.

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { exactTimeLabel, exactTimeTitle, isoTimestamp } from '../../src/utils/dateDisplay.js'

// Local Fri 28 Aug 2026, 14:32 — the reference "now" for every case below.
const NOW = new Date(2026, 7, 28, 14, 32, 0)
const NOW_MS = NOW.getTime()

/** Local date components → the unix SECONDS the API would emit for them. */
const secs = (...parts) => Math.floor(new Date(...parts).getTime() / 1000)

test('an event from today renders the clock only — no date noise', () => {
	const label = exactTimeLabel(secs(2026, 7, 28, 9, 15, 0), NOW_MS)
	assert.match(label, /9:15/, `expected a 9:15 clock, got "${label}"`)
	assert.ok(!label.includes('2026'), `today's stamp must not carry the year, got "${label}"`)
	assert.ok(!label.includes('28'), `today's stamp must not carry the day, got "${label}"`)
})

test('an older event renders the date as well as the clock', () => {
	const label = exactTimeLabel(secs(2026, 7, 20, 9, 15, 0), NOW_MS)
	assert.match(label, /9:15/, `expected a 9:15 clock, got "${label}"`)
	assert.ok(label.includes('2026'), `an older stamp must carry the year, got "${label}"`)
	assert.ok(label.includes('20'), `an older stamp must carry the day, got "${label}"`)
})

test('yesterday counts as older even when it is well under 24h ago', () => {
	// 23:59 the previous local day vs. a 14:32 "now" — under 24h, but a
	// different calendar day, so the date has to be there.
	const label = exactTimeLabel(secs(2026, 7, 27, 23, 59, 0), NOW_MS)
	assert.ok(label.includes('2026'), `yesterday must carry the date, got "${label}"`)
	assert.ok(label.length > exactTimeLabel(secs(2026, 7, 28, 23, 59, 0), NOW_MS).length)
})

test('a missing, zero or unparseable timestamp renders nothing and never throws', () => {
	for (const bad of [0, '0', null, undefined, '', NaN, 'not-a-time', -1, false]) {
		assert.equal(exactTimeLabel(bad, NOW_MS), '', `exactTimeLabel(${String(bad)})`)
		assert.equal(exactTimeTitle(bad), '', `exactTimeTitle(${String(bad)})`)
		assert.equal(isoTimestamp(bad), '', `isoTimestamp(${String(bad)})`)
	}
})

test('datetime is a stable UTC instant, whatever the viewer timezone', () => {
	// 1787927520 = 2026-08-28T14:32:00Z. toISOString() is UTC by definition, so
	// this value is identical on every machine.
	assert.equal(isoTimestamp(1787927520), '2026-08-28T14:32:00.000Z')
	// The API sends an integer, but a delta payload may stringify it.
	assert.equal(isoTimestamp('1787927520'), '2026-08-28T14:32:00.000Z')
})

test('the title spells out the stamp the short label abbreviates', () => {
	const ts = secs(2026, 7, 20, 9, 15, 30)
	const title = exactTimeTitle(ts)
	assert.ok(title.includes('2026'), `expected the year in "${title}"`)
	assert.match(title, /9:15/, `expected the clock in "${title}"`)
	// Full precision: the change log stores whole seconds, and the title is
	// where they surface.
	assert.match(title, /:30\b/, `expected seconds in "${title}"`)
	assert.ok(title.length > exactTimeLabel(ts, NOW_MS).length, 'the title must be the longer form')
})
