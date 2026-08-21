// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * dateDisplay - timezone-correct formatting for card due/start dates.
 *
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
