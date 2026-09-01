// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The "My tasks" feed is capped server-side (OCA\Kanso\Service\MyCardsService::LIMIT).
 * The cap is reported in response HEADERS rather than in the body, so
 * `GET /api/my-cards` keeps returning the plain card list every API client
 * (including the MCP server) already consumes.
 *
 * This module is the one place that knows about that wire detail. It is
 * deliberately free of Vue / Nextcloud imports so it can be unit-tested under
 * plain `node --test` (tests/unit/myCardsFeed.test.mjs).
 */

/** '1' when more assigned cards exist beyond the cap, '0' when the feed is complete. */
export const MY_CARDS_TRUNCATED_HEADER = 'x-kanso-truncated'

/** The row cap the server built the feed with. */
export const MY_CARDS_LIMIT_HEADER = 'x-kanso-limit'

/** What the feed looks like before the first response lands. */
const EMPTY_FEED = { cards: [], truncated: false, limit: 0 }

/**
 * Read one header out of whatever shape the caller has: an AxiosHeaders / fetch
 * Headers instance (`.get()`) or a plain lower-cased object.
 *
 * @param {object|undefined} headers - response headers
 * @param {string} name - lower-cased header name
 * @return {string|undefined} the raw header value
 */
function headerValue(headers, name) {
	if (!headers) return undefined
	if (typeof headers.get === 'function') return headers.get(name) ?? undefined
	return headers[name]
}

/**
 * Build the feed object the query cache stores from a raw API response.
 *
 * @param {Array|undefined} data - the response body (the card list)
 * @param {object|undefined} headers - the response headers
 * @return {{cards: Array, truncated: boolean, limit: number}} the feed
 */
export function toMyCardsFeed(data, headers) {
	return {
		cards: Array.isArray(data) ? data : [],
		truncated: String(headerValue(headers, MY_CARDS_TRUNCATED_HEADER) ?? '') === '1',
		limit: Number(headerValue(headers, MY_CARDS_LIMIT_HEADER)) || 0,
	}
}

/**
 * Normalise whatever the query cache currently holds: `undefined` while the
 * first fetch is in flight, or a bare array from an older cached payload.
 *
 * @param {object|Array|undefined} data - cached query data
 * @return {{cards: Array, truncated: boolean, limit: number}} the feed
 */
export function myCardsFeed(data) {
	if (Array.isArray(data)) return { ...EMPTY_FEED, cards: data }
	if (!data || !Array.isArray(data.cards)) return EMPTY_FEED
	return data
}

/**
 * Nav-badge label. A capped feed reads "200+" — the count is a floor, not an
 * exact figure, and rendering a bare "200" would freeze there and be wrong.
 *
 * @param {number} count - cards currently in the feed
 * @param {boolean} truncated - whether the server capped the feed
 * @return {string} the badge text
 */
export function formatTaskBadge(count, truncated) {
	return truncated ? `${count}+` : String(count)
}
