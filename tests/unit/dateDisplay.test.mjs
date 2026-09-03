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
import { execFileSync } from 'node:child_process'
import { exactTimeLabel, exactTimeTitle, isoTimestamp } from '../../src/utils/dateDisplay.js'

// Local Fri 28 Aug 2026, 14:32 — the reference "now" for every case below.
const NOW = new Date(2026, 7, 28, 14, 32, 0)
const NOW_MS = NOW.getTime()

/** Local date components → the unix SECONDS the API would emit for them. */
const secs = (...parts) => Math.floor(new Date(...parts).getTime() / 1000)

// ── Locale-pinned rendering ──────────────────────────────────────────────────
//
// The helpers format with locale `undefined` on purpose — every viewer sees
// their OWN locale, which is the whole point and also why a locale bug hides
// from a suite that only ever runs in the host's locale. So these cases run the
// REAL helper in a child `node` whose default locale is forced via LANG/LC_ALL,
// which is what Node maps to ICU. The timezone is pinned to the parent's so the
// `secs(...)` inputs above still mean the same wall clock in the child.
//
// Node resolves these locale IDs out of its own bundled ICU data, so no glibc
// locale has to be generated on the machine — verified on the Node the CI job
// pins as well as locally.

const MODULE_URL = new URL('../../src/utils/dateDisplay.js', import.meta.url).href
const TZ = Intl.DateTimeFormat().resolvedOptions().timeZone

/**
 * `exactTimeLabel(tsSeconds, nowMs)` as rendered under a forced default locale.
 *
 * @param {string} posixLocale e.g. 'en_US.UTF-8'
 * @param {number} tsSeconds unix timestamp in seconds
 * @param {number} nowMs reference "now"
 * @returns {string} the label the helper produces in that locale
 */
function labelIn(posixLocale, tsSeconds, nowMs = NOW_MS) {
	const src = `import { exactTimeLabel } from ${JSON.stringify(MODULE_URL)}\n`
		+ `process.stdout.write(exactTimeLabel(${tsSeconds}, ${nowMs}))`
	return execFileSync(process.execPath, ['--input-type=module', '--eval', src], {
		encoding: 'utf8',
		env: { ...process.env, LANG: posixLocale, LC_ALL: posixLocale, TZ },
	})
}

// Same local day as NOW, so these render as the clock alone.
const AT_2144 = secs(2026, 7, 28, 21, 44, 0)
const AT_0904 = secs(2026, 7, 28, 9, 4, 0)

test('a 12-hour locale renders the hour without a leading zero', () => {
	// The defect: this used to render "09:44 PM". A padded hour is simply not a
	// thing in a 12-hour locale — nobody writes "09:44 PM".
	const label = labelIn('en_US.UTF-8', AT_2144)
	assert.match(label, /(^|\s)9:44\s?(?:PM|pm)/, `expected an unpadded "9:44 PM", got "${label}"`)
	assert.ok(!/0\s*9:44/.test(label), `a 12-hour locale must not pad the hour, got "${label}"`)
})

test('a 12-hour locale still zero-pads the MINUTE', () => {
	// The other half of the trap: unpadding the hour must not unpad the minute
	// as well — "9:4 AM" would be worse than the bug being fixed.
	const label = labelIn('en_US.UTF-8', AT_0904)
	assert.match(label, /(^|\s)9:04\s?(?:AM|am)/, `expected "9:04 AM" with a padded minute, got "${label}"`)
})

test('24-hour locales keep the padding their own convention wants', () => {
	// The regression this guards is the tempting one-line "fix": pairing
	// `hour: 'numeric'` with `minute: '2-digit'` fixes en-US but silently
	// rewrites en-GB/de-DE "09:04" into "9:04", breaking a second set of users
	// to fix the first.
	for (const locale of ['en_GB.UTF-8', 'de_DE.UTF-8']) {
		const early = labelIn(locale, AT_0904)
		assert.match(early, /^0?9[:.]04$/, `${locale} must keep its padded "09:04", got "${early}"`)
		assert.ok(early.startsWith('09'), `${locale} pads the hour in its own convention, got "${early}"`)

		const evening = labelIn(locale, AT_2144)
		assert.equal(evening.replace(/\./, ':'), '21:44', `${locale} renders a 24-hour clock, got "${evening}"`)
	}
})

test('a 24-hour locale whose own pattern is unpadded stays unpadded', () => {
	// ja-JP writes H:mm natively, so "9:04" is correct there and "09:04" was
	// what the old options wrongly forced on it.
	const label = labelIn('ja_JP.UTF-8', AT_0904)
	assert.equal(label, '9:04', `ja-JP renders its native unpadded hour, got "${label}"`)
})

test('the long date+clock form drops the leading zero too', () => {
	// The label has two formatters; the older-event one must not keep the bug.
	const label = labelIn('en_US.UTF-8', secs(2026, 7, 20, 21, 44, 0))
	assert.ok(label.includes('2026'), `expected the date on an older stamp, got "${label}"`)
	assert.match(label, /(^|\s)9:44\s?(?:PM|pm)/, `expected an unpadded "9:44 PM", got "${label}"`)
	assert.ok(!/0\s*9:44/.test(label), `the date+clock form must not pad the hour, got "${label}"`)
})

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
