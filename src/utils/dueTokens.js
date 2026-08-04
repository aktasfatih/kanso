// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * dueTokens - natural due-date tokens for the card composer (#3416).
 *
 * A FIXED, small token set (no NLP date library): a trailing, whitespace-
 * delimited token like `!today`, `!tomorrow` or a weekday (`!mon`..`!sun`,
 * resolving to the NEXT occurrence of that weekday) sets the new card's due
 * date and is stripped from the title. `!tom` is a trivial alias for tomorrow.
 *
 * Pure and side-effect free (aside from reading "now") so it can be unit-tested
 * and reused. The resolved date is an ALL-DAY due date: a UTC-midnight ISO
 * datetime plus an all-day flag, matching how the card modal stores an all-day
 * due date (new Date("YYYY-MM-DD").toISOString() + allDay: true).
 */

// Weekday tokens → JS getDay() index (0 = Sunday .. 6 = Saturday).
const WEEKDAYS = {
	sun: 0,
	mon: 1,
	tue: 2,
	wed: 3,
	thu: 4,
	fri: 5,
	sat: 6,
}

/**
 * Format a Date as an all-day, UTC-midnight ISO datetime for the given local
 * calendar day. Mirrors the card modal: an all-day date is stored at UTC
 * midnight (`new Date("YYYY-MM-DD").toISOString()`), so the pill shows the date
 * with no time-of-day.
 *
 * @param {Date} d a Date whose local Y/M/D is the target calendar day
 * @returns {string} e.g. "2026-08-05T00:00:00.000Z"
 */
function toAllDayIso(d) {
	const pad = (n) => String(n).padStart(2, '0')
	const ymd = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
	return new Date(ymd).toISOString()
}

/**
 * Resolve a recognized token (lowercase, no leading "!") to an all-day ISO
 * datetime string, relative to `now`. Returns null for an unknown token.
 *
 * @param {string} token e.g. "today", "tomorrow", "tom", "mon"
 * @param {Date} [now] injectable clock for tests; defaults to new Date()
 * @returns {string|null}
 */
function resolveToken(token, now = new Date()) {
	const base = new Date(now.getFullYear(), now.getMonth(), now.getDate())

	if (token === 'today') {
		return toAllDayIso(base)
	}
	if (token === 'tomorrow' || token === 'tom') {
		base.setDate(base.getDate() + 1)
		return toAllDayIso(base)
	}
	if (token in WEEKDAYS) {
		// Next occurrence of the weekday: today counts only if it lands on a
		// FUTURE day, so "!mon" on a Monday resolves to next Monday (1..7 ahead),
		// never today - a date token should never mean "already overdue today".
		const target = WEEKDAYS[token]
		let delta = (target - base.getDay() + 7) % 7
		if (delta === 0) delta = 7
		base.setDate(base.getDate() + delta)
		return toAllDayIso(base)
	}
	return null
}

/**
 * Strip a recognized trailing due-date token from a title and resolve it to an
 * all-day due date. Only a whitespace-delimited, recognized token at the very
 * end is consumed - a "!" that appears mid-title, or a trailing "!" that is not
 * a known token, is left untouched (the title is never mangled).
 *
 * @param {string} rawTitle the composer input line
 * @param {Date} [now] injectable clock for tests; defaults to new Date()
 * @returns {{title: string, duedate: string|null, allDay: boolean}}
 *          title with the token removed (trimmed); duedate is the resolved
 *          ISO string or null when there is no recognized trailing token.
 */
export function parseDueToken(rawTitle, now = new Date()) {
	const title = String(rawTitle ?? '')
	// Match a trailing "!<word>" preceded by whitespace (so a token is only
	// consumed when it is a separate trailing word, never part of another word
	// like "hi!there" or a bare "!" with no following letters).
	const match = title.match(/\s+!([a-zA-Z]+)$/)
	if (!match) {
		return { title: title.trim(), duedate: null, allDay: false }
	}

	const duedate = resolveToken(match[1].toLowerCase(), now)
	if (duedate === null) {
		// Trailing "!word" that is not a known token: leave the title as-is.
		return { title: title.trim(), duedate: null, allDay: false }
	}

	// Consume the token: drop everything from the leading whitespace onward.
	const stripped = title.slice(0, match.index).trim()
	// A token that is the ENTIRE input (e.g. "  !today") would strip to an empty
	// title; don't consume it then - leave the line as typed so the composer's
	// normal empty-title handling applies instead of a token with no card.
	if (stripped === '') {
		return { title: title.trim(), duedate: null, allDay: false }
	}
	return {
		title: stripped,
		duedate,
		allDay: true,
	}
}
