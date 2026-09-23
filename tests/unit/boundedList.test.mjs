// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'

import {
	LIMIT_HEADER,
	TRUNCATED_HEADER,
	headerValue,
	toBoundedPage,
} from '../../src/services/boundedList.js'

/**
 * Stand-in for axios 1.x's AxiosHeaders: the own property keeps the header's
 * ORIGINAL casing and only `.get()` is case-insensitive. Reading such a bag with
 * `headers['x-kanso-truncated']` yields undefined - which is exactly how the
 * assignee picker came to present a capped list as if it were the whole board.
 *
 * @param {Record<string, string>} pairs header name -> value, original casing
 * @return {object} an AxiosHeaders-shaped bag
 */
function axiosHeaders(pairs) {
	const bag = { ...pairs }
	bag.get = (name) => {
		const lower = String(name).toLowerCase()
		for (const key of Object.keys(pairs)) {
			if (key.toLowerCase() === lower) return pairs[key]
		}
		return null
	}
	return bag
}

test('reads a header whose stored key keeps the wire casing', () => {
	const headers = axiosHeaders({ 'X-Kanso-Truncated': '1', 'X-Kanso-Limit': '25' })
	assert.equal(headerValue(headers, TRUNCATED_HEADER), '1')
	assert.equal(headerValue(headers, LIMIT_HEADER), '25')
})

test('reads a plain object bag whatever case its keys carry', () => {
	assert.equal(headerValue({ 'x-kanso-truncated': '1' }, TRUNCATED_HEADER), '1')
	assert.equal(headerValue({ 'X-Kanso-Truncated': '1' }, TRUNCATED_HEADER), '1')
})

test('reads a fetch-style Headers/Map bag', () => {
	const headers = new Map([[TRUNCATED_HEADER, '1'], [LIMIT_HEADER, '25']])
	assert.equal(headerValue(headers, TRUNCATED_HEADER), '1')
})

test('missing headers read as undefined rather than throwing', () => {
	assert.equal(headerValue(undefined, TRUNCATED_HEADER), undefined)
	assert.equal(headerValue({}, TRUNCATED_HEADER), undefined)
})

test('a truncated page is reported as truncated, with its cap', () => {
	const page = toBoundedPage(
		[{ uid: 'alice' }],
		axiosHeaders({ 'X-Kanso-Truncated': '1', 'X-Kanso-Limit': '25' }),
	)
	assert.deepEqual(page, { items: [{ uid: 'alice' }], truncated: true, limit: 25 })
})

test('a complete page is reported as complete', () => {
	const page = toBoundedPage([], axiosHeaders({ 'X-Kanso-Truncated': '0', 'X-Kanso-Limit': '25' }))
	assert.equal(page.truncated, false)
})

test('a server that says nothing makes no claim about completeness', () => {
	// Silence must not become "this is everyone" - that is the claim the picker
	// is not allowed to make on its own.
	const page = toBoundedPage([{ uid: 'alice' }], {})
	assert.deepEqual(page, { items: [{ uid: 'alice' }], truncated: false, limit: 0 })
})

test('a non-array body degrades to an empty list', () => {
	assert.deepEqual(toBoundedPage(undefined, {}).items, [])
	assert.deepEqual(toBoundedPage({ error: 'nope' }, {}).items, [])
})
