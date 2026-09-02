// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * rrule - parse / serialize / custom-detect for the recurrence editor.
 *
 * The Board settings → Automation recurrence editor models a deliberately small
 * slice of RFC 5545: FREQ, INTERVAL, a weekly BYDAY, and one end condition
 * (COUNT or UNTIL). A rule authored elsewhere (the API, the MCP server, an
 * import) can carry parts outside that slice - BYMONTHDAY, BYSETPOS, a
 * positional BYDAY like `1MO`, WKST … - and re-serializing such a rule from the
 * five fields the editor knows silently rewrites the user's schedule.
 *
 * So the editor asks `isCustomRrule()` first and, when the answer is yes, shows
 * the schedule read-only and omits `rrule` from the PATCH entirely (the API
 * treats a missing rrule as "leave it alone", which also keeps the
 * occurrences-spawned tally intact).
 *
 * Pure and side-effect free, so it unit-tests without a DOM or a Vue runtime.
 */

/** The four frequencies the editor's unit `<select>` can represent. */
const RRULE_FREQS = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY']

/** RRULE parts the editor round-trips. Anything else makes a rule "custom". */
const KNOWN_PARTS = ['FREQ', 'INTERVAL', 'BYDAY', 'COUNT', 'UNTIL']

/** A BYDAY entry carrying an ordinal ("1MO", "-1FR") - not representable here. */
const POSITIONAL_BYDAY = /^[+-]?\d/

/**
 * Split an RRULE string into an uppercase-keyed parts map. Empty segments and
 * segments without an `=` are ignored, matching what the display helper does.
 *
 * @param {string} rrule RFC 5545 RRULE body, e.g. `FREQ=WEEKLY;BYDAY=MO,WE`
 * @return {Record<string, string>} uppercase key → raw value
 */
export function rruleParts(rrule) {
	const parts = {}
	for (const seg of String(rrule || '').split(';')) {
		const eq = seg.indexOf('=')
		if (eq === -1) continue
		parts[seg.slice(0, eq).trim().toUpperCase()] = seg.slice(eq + 1).trim()
	}
	return parts
}

/**
 * Does this RRULE carry anything the editor cannot round-trip?
 *
 * True for any part outside FREQ/INTERVAL/COUNT/UNTIL/weekly-BYDAY, for a BYDAY
 * on a non-weekly FREQ, for a positional BYDAY, and for a FREQ the unit select
 * has no option for (including a missing one).
 *
 * @param {string} rrule the stored rule
 * @return {boolean} true when the schedule must be left untouched
 */
export function isCustomRrule(rrule) {
	const parts = rruleParts(rrule)
	const freq = (parts.FREQ || '').toUpperCase()
	if (!RRULE_FREQS.includes(freq)) return true
	for (const key of Object.keys(parts)) {
		if (!KNOWN_PARTS.includes(key)) return true
	}
	if (parts.BYDAY !== undefined) {
		if (freq !== 'WEEKLY') return true
		// An empty BYDAY would serialize back to nothing at all.
		if (parts.BYDAY.trim() === '') return true
		if (parts.BYDAY.split(',').some((d) => POSITIONAL_BYDAY.test(d.trim()))) return true
	}
	// COUNT and UNTIL together is not a schedule this editor's single "Ends"
	// select can express either.
	if (parts.COUNT !== undefined && parts.UNTIL !== undefined) return true
	// An end condition we cannot read back is an end condition we would drop,
	// turning a bounded rule into an endless one. Only the basic UNTIL format
	// (YYYYMMDD…) and a plain integer COUNT survive the round trip.
	if (parts.COUNT !== undefined && !/^\d+$/.test(parts.COUNT.trim())) return true
	if (parts.UNTIL !== undefined && !/^\d{8}/.test(parts.UNTIL.trim())) return true
	return false
}

/**
 * Parse an RRULE into the fields the editor binds to.
 *
 * `untilSuffix` carries the time-of-day part of UNTIL verbatim (`T235959Z`, or
 * `''` for a DATE-valued UNTIL) so re-serializing a rule whose end time is not
 * midnight does not quietly move it to midnight.
 *
 * @param {string} rrule the stored rule
 * @return {{freq: string, interval: number, weekdays: string[], endType: string, count: number, until: string, untilSuffix: string}} editor fields
 */
export function parseRecurRrule(rrule) {
	const parts = rruleParts(rrule)
	const freq = (parts.FREQ || '').toUpperCase()

	const out = {
		freq: RRULE_FREQS.includes(freq) ? freq : 'WEEKLY',
		interval: Math.max(1, parseInt(parts.INTERVAL || '1', 10) || 1),
		weekdays: [],
		endType: 'forever',
		count: 10,
		until: '',
		untilSuffix: 'T000000Z',
	}

	if (parts.BYDAY) {
		out.weekdays = parts.BYDAY.split(',').map((d) => d.trim().toUpperCase()).filter(Boolean)
	}

	if (parts.COUNT) {
		out.endType = 'count'
		out.count = Math.max(1, parseInt(parts.COUNT, 10) || 1)
	} else if (parts.UNTIL) {
		const raw = parts.UNTIL.trim()
		const day = raw.slice(0, 8)
		if (/^\d{8}$/.test(day)) {
			out.endType = 'until'
			out.until = `${day.slice(0, 4)}-${day.slice(4, 6)}-${day.slice(6, 8)}`
			out.untilSuffix = raw.slice(8)
		}
	}

	return out
}

/**
 * Serialize the editor fields back into an RFC 5545 RRULE.
 *
 * Only ever called for a rule `isCustomRrule()` said no to, so the five parts
 * below are the whole rule by construction.
 *
 * @param {object} fields the editor state
 * @param {string} fields.freq DAILY/WEEKLY/MONTHLY/YEARLY
 * @param {number} fields.interval repeat interval, 1 is omitted
 * @param {string[]} [fields.weekdays] weekly BYDAY entries
 * @param {string} [fields.endType] 'forever' | 'count' | 'until'
 * @param {number} [fields.count] occurrences for endType 'count'
 * @param {string} [fields.until] YYYY-MM-DD for endType 'until'
 * @param {string} [fields.untilSuffix] time-of-day part to preserve on UNTIL
 * @return {string} the RRULE body
 */
export function buildRecurRrule({ freq, interval, weekdays, endType, count, until, untilSuffix }) {
	const parts = [`FREQ=${freq}`]
	const every = Number(interval)
	if (every > 1) parts.push(`INTERVAL=${every}`)
	if (freq === 'WEEKLY' && Array.isArray(weekdays) && weekdays.length > 0) {
		parts.push(`BYDAY=${weekdays.join(',')}`)
	}
	if (endType === 'count') {
		parts.push(`COUNT=${count}`)
	} else if (endType === 'until' && until) {
		// YYYY-MM-DD → YYYYMMDD + whatever time-of-day the rule already carried
		// (T000000Z for anything the editor itself created).
		parts.push(`UNTIL=${String(until).replace(/-/g, '')}${untilSuffix ?? 'T000000Z'}`)
	}
	return parts.join(';')
}
