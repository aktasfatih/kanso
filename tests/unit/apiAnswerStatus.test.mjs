// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #155 — "This board no longer exists." for a board nothing had deleted.
//
// The reporter's devtools log had every Kanso endpoint 404ing in one burst:
// /boards/2, /boards/2/changes, and also /my-cards, /reviews/mine and /inbox,
// which are not board-scoped at all. Nextcloud had stopped routing
// /apps/kanso/api/* for a few seconds. The client read 404 as "it's gone",
// dropped the board and said so.
//
// The two 404s are distinguishable and this is where that is decided: Kanso's
// own API 404 is JSON out of ApiErrorTrait, anybody else's is an HTML error
// page. The asymmetry with 403 is deliberate and load-bearing — a revoked share
// (#10385) must stay terminal whatever the body looks like.

import test from 'node:test'
import assert from 'node:assert/strict'

// The module reaches for no browser globals, but it is imported by files that
// do, so keep the rig honest about what it does and does not provide.
const { apiAnswerStatus } = await import('../../src/services/apiErrors.js')

/**
 * An axios rejection the way the adapter builds one.
 *
 * @param {number} status - HTTP status
 * @param {unknown} data - parsed body
 * @param {object} headers - response headers
 * @return {Error} the rejection
 */
function answer(status, data, headers = {}) {
	const error = new Error(`Request failed with status code ${status}`)
	error.isAxiosError = true
	error.response = { status, data, headers }
	return error
}

// Nextcloud's router 404: an HTML page, served as text/html. Axios leaves a
// non-JSON body as a string.
const NC_404_HTML = '<!DOCTYPE html><html><head><title>Nextcloud</title></head>'
	+ '<body><p>The page could not be found on the server.</p></body></html>'

test('Kanso\'s own JSON 404 is an answer', () => {
	assert.equal(
		apiAnswerStatus(answer(404, { error: 'Not found' }, { 'content-type': 'application/json; charset=utf-8' })),
		404,
		'ApiErrorTrait::respond answers a missing board/card with {"error":"Not found"} — '
		+ 'that is the server saying it is gone, and it must stay terminal',
	)
})

test('Nextcloud\'s HTML 404 is not an answer', () => {
	assert.equal(
		apiAnswerStatus(answer(404, NC_404_HTML, { 'content-type': 'text/html; charset=UTF-8' })),
		null,
		'a 404 Kanso never sent is a server failing to answer, not an answer — the '
		+ 'caller must fall through to its transient/retryable branch',
	)
})

test('content-type decides it, not the shape of the body', () => {
	// A proxy that renders its error page as JSON-ish nonsense, and an HTML body
	// that arrived (via a stub or an interceptor) as something other than a string.
	// Whichever way these two disagree, the declared type wins.
	assert.equal(
		apiAnswerStatus(answer(404, '<html>nope</html>', { 'Content-Type': 'application/json' })),
		404,
		'header lookup is case-insensitive and content-type is authoritative',
	)
	assert.equal(
		apiAnswerStatus(answer(404, { page: 'not found' }, { 'content-type': 'text/html' })),
		null,
		'an object body does not make a text/html 404 ours',
	)
})

test('with no content-type at all, the parsed body decides', () => {
	// Some proxies strip it; the unit rigs in this directory do not set one.
	assert.equal(apiAnswerStatus(answer(404, { error: 'Not found' })), 404)
	assert.equal(apiAnswerStatus(answer(404, NC_404_HTML)), null)
	assert.equal(apiAnswerStatus(answer(404, '')), null,
		'an empty body is not JSON either — nothing identifies it as Kanso\'s')
})

test('403 stays an answer whatever the body is', () => {
	// The regression guard for #10385. A revoked share answers 403, and blanking
	// the board on it is the fix that card shipped; body-checking 403 the way 404
	// is body-checked would quietly undo it for any deployment whose 403 does not
	// come back as JSON.
	assert.equal(apiAnswerStatus(answer(403, { error: 'Access denied' }, { 'content-type': 'application/json' })), 403)
	assert.equal(apiAnswerStatus(answer(403, NC_404_HTML, { 'content-type': 'text/html' })), 403,
		'a 403 is terminal even when it did not come from Kanso — the request was '
		+ 'refused on the way in, which is not something retrying fixes either')
})

test('every other status passes through untouched', () => {
	assert.equal(apiAnswerStatus(answer(500, '')), 500)
	assert.equal(apiAnswerStatus(answer(503, NC_404_HTML, { 'content-type': 'text/html' })), 503)
	assert.equal(apiAnswerStatus(answer(409, { error: 'rebalance_required' })), 409)
	assert.equal(apiAnswerStatus(answer(429, { error: 'too many' })), 429)
})

test('a failure with no response at all is not an answer', () => {
	assert.equal(apiAnswerStatus(new Error('Network Error')), null,
		'a dropped connection carries no response — the case a status check must '
		+ 'not mistake for an answer')
	assert.equal(apiAnswerStatus(null), null)
	assert.equal(apiAnswerStatus(undefined), null)
})

test('AxiosHeaders-style headers are read through get()', () => {
	// In the browser axios hands back an AxiosHeaders instance, not a plain
	// object, and a plain-object-only lookup would find nothing on it — making
	// every real 404 fall back to the body check. Kanso's would still be an
	// object and still classify correctly, so this would never redden anything
	// else in this file; it is asserted directly for that reason.
	const headers = {
		get(name) {
			return name.toLowerCase() === 'content-type' ? 'text/html; charset=UTF-8' : null
		},
	}
	assert.equal(apiAnswerStatus(answer(404, NC_404_HTML, headers)), null)
	assert.equal(
		apiAnswerStatus(answer(404, { error: 'Not found' }, {
			get: (name) => (name.toLowerCase() === 'content-type' ? 'application/json' : null),
		})),
		404,
	)
})
