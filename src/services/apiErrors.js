// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Did KANSO answer this request, or did something in front of Kanso fail to
 * route it at all?
 *
 * #155 is the whole reason this module exists. A reporter watched boards and
 * cards close themselves mid-use with "This board no longer exists." while
 * nothing had been deleted; their devtools log showed every Kanso endpoint
 * 404ing in the same burst - `/boards/2`, `/boards/2/changes`, and also
 * `/my-cards`, `/reviews/mine` and `/inbox`, which are not board-scoped at all.
 * Nextcloud had transiently stopped routing `/apps/kanso/api/*`: a cache
 * eviction, an upgrade in flight, a second backend, a proxy. Waiting brought it
 * back; nextcloud.log had nothing to say.
 *
 * A 404 from Kanso and a 404 from a Nextcloud that never reached Kanso mean
 * opposite things, and the client acted on only the first: the board was
 * dropped and the user was told it no longer exists. The two are distinguishable
 * from the body, which is what this module does. Kanso's own API 404 is JSON -
 * `{"error":"Not found"}` out of ApiErrorTrait::respond, on every plain API
 * controller. Nextcloud's router 404 is its HTML error page.
 *
 * So a foreign 404 belongs in the same class as a 500 or a dropped connection:
 * the server failed to answer, the cached payload is the best thing the client
 * has, and the right copy is the retryable one. The poll/refetch then recovers
 * it on its own once routing comes back - which is exactly what the reporter
 * saw happen, minus the false "deleted" verdict in between.
 */

/**
 * Read one header off an axios response, whatever shape it arrived in.
 *
 * Axios hands back an `AxiosHeaders` instance in the browser (case-insensitive
 * `get`), but a plain lowercase-keyed object under some adapters and in the
 * unit rig, so both are handled rather than assumed.
 *
 * @param {object|undefined} response - an axios response
 * @param {string} name - header name, lowercase
 * @return {string} the header value, or '' when absent
 */
function header(response, name) {
	const headers = response?.headers
	if (!headers) {
		return ''
	}
	if (typeof headers.get === 'function') {
		return String(headers.get(name) ?? '')
	}
	for (const key of Object.keys(headers)) {
		if (key.toLowerCase() === name) {
			return String(headers[key] ?? '')
		}
	}
	return ''
}

/**
 * Is this response body Kanso's own JSON, rather than somebody else's error page?
 *
 * Content-type decides it when there is one. When there is not - a proxy that
 * stripped it, a stub that never set one - the parsed body is the fallback:
 * axios parses a JSON body into an object and leaves anything else (an HTML
 * error page, an empty body) as a string.
 *
 * @param {object|undefined} response - an axios response
 * @return {boolean} true when the body is JSON
 */
function isJsonBody(response) {
	const contentType = header(response, 'content-type')
	if (contentType) {
		return contentType.toLowerCase().includes('json')
	}
	const data = response?.data
	return typeof data === 'object' && data !== null
}

/**
 * The HTTP status of a failed request - but only when the server ANSWERED.
 *
 * Every caller that branches on a status (is this board gone? is this card
 * forbidden?) must read the status through here rather than off
 * `error.response.status`, because a status that Kanso did not produce is not
 * an answer to branch on.
 *
 * - No `response` at all (a transport failure, an abort): `null`.
 * - A 404 whose body is not JSON: `null`. Kanso never sent it; see the note at
 *   the top of this file.
 * - Everything else, 403 included: the status as-is.
 *
 * 403 is deliberately NOT body-checked. It is the answer a revoked share gives
 * (#10385) and it must stay terminal exactly as it is today; a 403 in front of
 * Kanso means the request was refused on the way in, which is not a case this
 * app improves by retrying either.
 *
 * @param {unknown} error - a rejected axios error, or null
 * @return {number|null} the answered status, or null when nobody answered
 */
export function apiAnswerStatus(error) {
	const response = error?.response
	const status = response?.status
	if (typeof status !== 'number') {
		return null
	}
	if (status === 404 && !isJsonBody(response)) {
		return null
	}
	return status
}
