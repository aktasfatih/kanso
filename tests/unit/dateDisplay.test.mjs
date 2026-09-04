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
import { exactTimeLabel, exactTimeTitle, hasRelativeLabel, isoTimestamp } from '../../src/utils/dateDisplay.js'

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

/**
 * The activity row's time text for an entry PAST the 7-day mark, rendered under
 * a forced default locale — the case where `relativeTime()` has already fallen
 * back to an absolute date, so the row has a date on both sides to reconcile.
 *
 * @param {string} posixLocale e.g. 'de_DE.UTF-8'
 * @param {number} tsSeconds unix timestamp in seconds (must be ≥7 days old)
 * @param {number} nowMs reference "now"
 * @returns {string} the row's visible time text in that locale
 */
function oldRowIn(posixLocale, tsSeconds, nowMs = NOW_MS) {
	const src = `import { exactTimeLabel, hasRelativeLabel } from ${JSON.stringify(MODULE_URL)}\n`
		+ `const ts = ${tsSeconds}, now = ${nowMs}\n`
		// What relativeTime() renders for an entry this old.
		+ 'const fallback = new Date(ts * 1000).toLocaleDateString(undefined, { day: \'numeric\', month: \'short\', year: \'numeric\' })\n'
		+ 'const relative = hasRelativeLabel(ts, now) ? fallback : \'\'\n'
		+ 'const exact = exactTimeLabel(ts, now)\n'
		+ 'process.stdout.write(relative && exact ? `${relative} · ${exact}` : (relative || exact))'
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

// ── The activity row's PAIR of labels (#10177) ───────────────────────────────
//
// The row renders the relative label and the exact stamp side by side. That is
// only safe while the relative label is actually relative: `relativeTime()` in
// CardDetail.vue counts minutes/hours/days and then, past 7 days, falls back to
// an absolute date — the very date the exact stamp already carries. Shipped in
// v0.23.0, that printed the date twice: "26. Aug. 2026 · 26. Aug. 2026, 06:21"
// (issue #108). The cases below cover both sides of the 7-day boundary.
//
// The earlier suite passed straight through this bug because every case it had
// was a recent timestamp. So each case here composes the row exactly as the
// template composes it, rather than checking a helper in isolation.

/** What `relativeTime()` renders past 7 days: its absolute-date fallback. */
const relativeFallback = (tsSeconds) => new Date(tsSeconds * 1000)
	.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })

/**
 * The `.card-modal__activity-time` text, composed as the template composes it.
 *
 * `relativeLabel` stands in for `relativeTime()`, which lives in the component
 * because it needs the l10n runtime — pass what it would render for that age.
 *
 * @param {number} tsSeconds unix timestamp in seconds
 * @param {string} relativeLabel what `relativeTime()` renders for this entry
 * @param {number} nowMs reference "now"
 * @returns {string} the row's visible time text
 */
function activityTimeText(tsSeconds, relativeLabel, nowMs = NOW_MS) {
	const exact = exactTimeLabel(tsSeconds, nowMs)
	const relative = hasRelativeLabel(tsSeconds, nowMs) ? relativeLabel : ''
	if (relative && exact) return `${relative} · ${exact}`
	return relative || exact
}

/** How many times a year/date token appears in a rendered row. */
const occurrences = (haystack, needle) => haystack.split(needle).length - 1

test('an entry older than a week shows its date once, not twice (#108)', () => {
	// 7 days + 8h before NOW, so relativeTime() is past its fallback.
	const ts = secs(2026, 7, 21, 6, 21, 0)
	assert.equal(hasRelativeLabel(ts, NOW_MS), false, 'a >7-day entry has no relative label left')

	const text = activityTimeText(ts, relativeFallback(ts))
	assert.equal(occurrences(text, '2026'), 1, `the date must appear once, got "${text}"`)
	assert.ok(!text.includes(' · '), `nothing left to pair the stamp with, got "${text}"`)
	// Still an exact stamp, and still the full one: date AND time.
	assert.equal(text, exactTimeLabel(ts, NOW_MS))
	assert.match(text, /6[:.]21/, `the row must still carry a clock, got "${text}"`)
})

test('the reporter\'s own example reads right in German', () => {
	// de-DE is the locale in issue #108, and 06:21 is the reporter's own time —
	// which doubles as a guard on the padded-hour trap 9714d87 fixed: a de-DE
	// clock is "06:21", never "6:21".
	const ts = secs(2026, 7, 21, 6, 21, 0)
	const text = oldRowIn('de_DE.UTF-8', ts)
	assert.equal(occurrences(text, '2026'), 1, `de-DE must print the year once, got "${text}"`)
	assert.ok(!text.includes(' · '), `de-DE must not pair a date with a date, got "${text}"`)
	assert.match(text, /(^|\s)06[:.]21$/, `de-DE keeps its padded "06:21", got "${text}"`)
	assert.ok(text.includes('21'), `the day must survive, got "${text}"`)
})

test('the boundary sits at exactly 7 days, asserted from both sides', () => {
	const SEVEN_DAYS = 7 * 86400
	const nowSecs = Math.floor(NOW_MS / 1000)
	// One second under: still counting days, so the pair is still correct.
	const under = nowSecs - SEVEN_DAYS + 1
	assert.equal(hasRelativeLabel(under, NOW_MS), true, 'just under 7 days keeps "6 days ago"')
	// Exactly 7 days: relativeTime() has switched to a date, so the row must not
	// keep it. This is the instant the regression started printing it twice.
	const exact = nowSecs - SEVEN_DAYS
	assert.equal(hasRelativeLabel(exact, NOW_MS), false, 'exactly 7 days is already the fallback')
	assert.equal(occurrences(activityTimeText(exact, relativeFallback(exact)), '2026'), 1)
})

test('every age still shows an exact time — under a day, days, and weeks', () => {
	const cases = [
		['2 hours ago', secs(2026, 7, 28, 12, 32, 0)],
		['3 days ago', secs(2026, 7, 25, 9, 15, 0)],
		[relativeFallback(secs(2026, 7, 14, 9, 15, 0)), secs(2026, 7, 14, 9, 15, 0)],
	]
	for (const [relative, ts] of cases) {
		const text = activityTimeText(ts, relative)
		assert.match(text, /\d{1,2}[:.]\d{2}/, `every row keeps a clock, got "${text}"`)
	}
	// And under 7 days the pair itself survives — this fix is a narrowing, not a
	// removal of the stamp or of the relative label.
	const recent = activityTimeText(secs(2026, 7, 25, 9, 15, 0), '3 days ago')
	assert.ok(recent.startsWith('3 days ago · '), `the pair must survive, got "${recent}"`)
})

test('a missing, zero or unparseable timestamp renders nothing and never throws', () => {
	for (const bad of [0, '0', null, undefined, '', NaN, 'not-a-time', -1, false]) {
		assert.equal(exactTimeLabel(bad, NOW_MS), '', `exactTimeLabel(${String(bad)})`)
		assert.equal(exactTimeTitle(bad), '', `exactTimeTitle(${String(bad)})`)
		assert.equal(isoTimestamp(bad), '', `isoTimestamp(${String(bad)})`)
		assert.equal(hasRelativeLabel(bad, NOW_MS), false, `hasRelativeLabel(${String(bad)})`)
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
