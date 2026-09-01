// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// The "My tasks" feed is capped server-side and reports the cap in response
// headers (OCA\Kanso\Controller\MyCardsController::HEADER_TRUNCATED). These
// tests pin the client half of that contract: reading the signal off the
// response, and rendering the nav badge as "200+" instead of a frozen "200"
// that reads as an exact count.

import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
	toMyCardsFeed,
	myCardsFeed,
	formatTaskBadge,
	MY_CARDS_TRUNCATED_HEADER,
	MY_CARDS_LIMIT_HEADER,
} from '../../src/services/myCardsFeed.js'

const cards = [{ id: 1 }, { id: 2 }]

test('a capped response is read as truncated, with the cap', () => {
	const feed = toMyCardsFeed(cards, {
		[MY_CARDS_TRUNCATED_HEADER]: '1',
		[MY_CARDS_LIMIT_HEADER]: '200',
	})
	assert.equal(feed.truncated, true)
	assert.equal(feed.limit, 200)
	assert.deepEqual(feed.cards, cards)
})

test('a complete response is not truncated', () => {
	const feed = toMyCardsFeed(cards, {
		[MY_CARDS_TRUNCATED_HEADER]: '0',
		[MY_CARDS_LIMIT_HEADER]: '200',
	})
	assert.equal(feed.truncated, false)
	assert.equal(feed.limit, 200)
})

test('headers exposed through a getter (AxiosHeaders / fetch Headers) are read too', () => {
	const headers = new Map([[MY_CARDS_TRUNCATED_HEADER, '1'], [MY_CARDS_LIMIT_HEADER, '200']])
	const feed = toMyCardsFeed(cards, headers)
	assert.equal(feed.truncated, true)
	assert.equal(feed.limit, 200)
})

test('a response without the headers degrades to "not truncated"', () => {
	// A proxy that strips unknown headers must not make the page claim a cap.
	const feed = toMyCardsFeed(cards, {})
	assert.equal(feed.truncated, false)
	assert.equal(feed.limit, 0)
	assert.deepEqual(feed.cards, cards)
})

test('an unset cache entry reads as an empty, untruncated feed', () => {
	assert.deepEqual(myCardsFeed(undefined), { cards: [], truncated: false, limit: 0 })
	assert.deepEqual(myCardsFeed({ cards, truncated: true, limit: 200 }),
		{ cards, truncated: true, limit: 200 })
})

test('the nav badge says "200+" for a capped feed, and the exact count otherwise', () => {
	// The badge counts the SAME truncated array, so a capped feed must not
	// render a bare "200": it would be wrong and would never move again.
	assert.equal(formatTaskBadge(200, true), '200+')
	assert.equal(formatTaskBadge(3, false), '3')
	assert.equal(formatTaskBadge(0, false), '0')
})
