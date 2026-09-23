// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Kanso's convention for a list endpoint whose result is capped server-side:
 * the BODY stays the plain array every API client already parses, and the two
 * facts about the bound ride in response headers. "My tasks" (MyCardsController)
 * and the board participants list (BoardController) both use it.
 *
 * This module is the one place that knows that wire detail. It is deliberately
 * free of Vue / Nextcloud / axios imports so it can be unit-tested under plain
 * `node --test` (tests/unit/boundedList.test.mjs).
 */

/** '1' when the server held rows back, '0' when the response is the whole set. */
export const TRUNCATED_HEADER = 'x-kanso-truncated'

/** The cap the response was built with. */
export const LIMIT_HEADER = 'x-kanso-limit'

/**
 * Read one header out of whatever shape the caller has.
 *
 * The case-insensitive walk is not decoration: axios 1.x hands back an
 * `AxiosHeaders` whose OWN PROPERTY keeps the header's original casing
 * (`X-Kanso-Truncated`), so a plain `headers['x-kanso-truncated']` reads
 * undefined and every bounded list silently looks complete. `.get()` is the
 * case-insensitive accessor; the walk covers the plain-object shapes (fetch
 * Headers spread, a test fixture) that have no `.get()` at all.
 *
 * @param {object|undefined} headers - response headers (AxiosHeaders, Headers, Map or plain object)
 * @param {string} name - lower-cased header name
 * @return {string|undefined} the raw header value
 */
export function headerValue(headers, name) {
	if (!headers) return undefined
	if (typeof headers.get === 'function') return headers.get(name) ?? undefined
	const lower = name.toLowerCase()
	for (const key of Object.keys(headers)) {
		if (key.toLowerCase() === lower) return headers[key]
	}
	return undefined
}

/**
 * The page object a bounded list response becomes.
 *
 * `truncated` is false unless the server positively said otherwise, so a server
 * that does not send the headers degrades to "no claim made" rather than to the
 * false claim that the page is everything.
 *
 * @param {Array|undefined} data - the response body (the list)
 * @param {object|undefined} headers - the response headers
 * @return {{items: Array, truncated: boolean, limit: number}} the page
 */
export function toBoundedPage(data, headers) {
	return {
		items: Array.isArray(data) ? data : [],
		truncated: String(headerValue(headers, TRUNCATED_HEADER) ?? '') === '1',
		limit: Number(headerValue(headers, LIMIT_HEADER)) || 0,
	}
}
