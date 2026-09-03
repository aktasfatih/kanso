// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * dateDisplay - shared, timezone-correct date/time formatting for the UI.
 *
 * Two groups of helpers live here:
 *
 *  1. Card due/start dates (`allDayInputValue`, `timedInputValue`,
 *     `formatCardDate`) - see the all-day storage note below.
 *  2. Event timestamps (`exactTimeLabel`, `exactTimeTitle`, `isoTimestamp`) -
 *     the exact wall-clock stamp shown next to a relative label ("5 days ago")
 *     in the card's activity feed. Those take a unix timestamp in SECONDS, the
 *     shape the API emits (ActivityService returns the raw epoch integer).
 *
 * ── All-day card dates ───────────────────────────────────────────────────────
 * All-day dates are STORED at UTC midnight (`new Date("YYYY-MM-DD").toISOString()`,
 * e.g. `2026-07-22T00:00:00.000Z`) so the stored instant is a stable calendar day
 * that downstream consumers (reminders, overdue calc, the calendar feed's
 * VALUE=DATE) agree on. The storage MUST NOT change.
 *
 * The bug these helpers fix: reading an all-day date back with LOCAL getters
 * (`getFullYear`/`getMonth`/`getDate`, `toLocaleDateString(...)` with no timeZone)
 * renders UTC-midnight in the viewer's zone. West of UTC that lands on the
 * PREVIOUS calendar day (`2026-07-22T00:00:00Z` → "Jul 21" in America/New_York),
 * so an all-day date typed as the 22nd displays as the 21st.
 *
 * Fix: for all-day dates, format in UTC so read == write zero point. Timed dates
 * (datetime-local) keep local formatting - they carry a real time-of-day the
 * viewer should see in their own zone.
 */

/**
 * `YYYY-MM-DD` for a `<input type="date">`, in UTC for the given instant.
 *
 * @param {Date} d parsed all-day date (UTC midnight)
 * @returns {string} e.g. "2026-07-22"
 */
export function allDayInputValue(d) {
	const pad = (n) => String(n).padStart(2, '0')
	return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`
}

/**
 * `YYYY-MM-DDTHH:mm` for a `<input type="datetime-local">`, in LOCAL time.
 *
 * @param {Date} d parsed timed date
 * @returns {string} e.g. "2026-07-22T14:30"
 */
export function timedInputValue(d) {
	const pad = (n) => String(n).padStart(2, '0')
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
		+ `T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/**
 * Human date label for a card date, timezone-correct for all-day cards.
 *
 * All-day → formatted in UTC (matches the stored calendar day); timed → local
 * (with the time-of-day). `allDay` selects which; `opts` is the
 * Intl.DateTimeFormat options for the date part.
 *
 * @param {string} iso the stored ISO datetime
 * @param {boolean} allDay whether the date is all-day (date-only)
 * @param {Intl.DateTimeFormatOptions} opts date-part format options
 * @returns {string}
 */
export function formatCardDate(iso, allDay, opts) {
	const d = new Date(iso)
	if (allDay) {
		return d.toLocaleDateString(undefined, { ...opts, timeZone: 'UTC' })
	}
	return d.toLocaleString(undefined, opts)
}

// ── Event timestamps (activity feed) ─────────────────────────────────────────

/**
 * A unix-seconds timestamp as a Date, or null when there is nothing to show.
 *
 * Callers render this next to user-visible rows, so an absent (`null`,
 * `undefined`), zero ("never") or unparseable timestamp must degrade to an
 * empty label - never to "1 Jan 1970", NaN, or a thrown error.
 *
 * @param {number|string|null|undefined} tsSeconds unix timestamp in seconds
 * @returns {Date|null}
 */
function eventDate(tsSeconds) {
	if (tsSeconds === null || tsSeconds === undefined || tsSeconds === '') return null
	const secs = Number(tsSeconds)
	if (!Number.isFinite(secs) || secs <= 0) return null
	const d = new Date(secs * 1000)
	return Number.isNaN(d.getTime()) ? null : d
}

/**
 * Lazily-built, reused `Intl.DateTimeFormat`s.
 *
 * A feed row asks for its stamp several times per render (the label, the
 * `datetime`, the title), and a long feed is tens of rows — building a fresh
 * formatter for each call is the expensive part of Intl. The locale is always
 * the browser default and cannot change mid-session, so one instance each is
 * enough.
 */
const formatters = {}
function fmt(key, opts) {
	return (formatters[key] ??= new Intl.DateTimeFormat(undefined, opts))
}

/**
 * The exact stamp shown beside a relative label, kept short when it can be.
 *
 * Length-aware so a 50-row feed does not turn into a wall of dates: an event
 * from today needs only the clock ("14:32"); anything older carries the date
 * too ("28 Aug 2026, 14:32"). Formatted in the viewer's own locale and zone.
 *
 * @param {number|string|null|undefined} tsSeconds unix timestamp in seconds
 * @param {number} [nowMs] reference "now" in ms, for testing
 * @returns {string} '' when there is no usable timestamp
 */
export function exactTimeLabel(tsSeconds, nowMs = Date.now()) {
	const d = eventDate(tsSeconds)
	if (!d) return ''
	const now = new Date(nowMs)
	const sameDay = d.getFullYear() === now.getFullYear()
		&& d.getMonth() === now.getMonth()
		&& d.getDate() === now.getDate()
	if (sameDay) {
		return fmt('clock', { hour: '2-digit', minute: '2-digit' }).format(d)
	}
	return fmt('dateClock', {
		day: 'numeric',
		month: 'short',
		year: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	}).format(d)
}

/**
 * The full stamp, for the element's `title` - the shortened label spelled out.
 *
 * @param {number|string|null|undefined} tsSeconds unix timestamp in seconds
 * @returns {string} '' when there is no usable timestamp
 */
export function exactTimeTitle(tsSeconds) {
	const d = eventDate(tsSeconds)
	if (!d) return ''
	return fmt('full', { dateStyle: 'full', timeStyle: 'medium' }).format(d)
}

/**
 * The machine-readable value for a `<time datetime="...">` attribute.
 *
 * @param {number|string|null|undefined} tsSeconds unix timestamp in seconds
 * @returns {string} ISO 8601 in UTC, or '' when there is no usable timestamp
 */
export function isoTimestamp(tsSeconds) {
	const d = eventDate(tsSeconds)
	return d ? d.toISOString() : ''
}
