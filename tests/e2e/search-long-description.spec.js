// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api } from './helpers.js'

// #10173 — Search reads a BOUNDED slice of a card description.
//
// A description written through the API is capped
// (CardService::MAX_DESCRIPTION_LENGTH = 64 KiB), but the paths that carry an
// EXISTING description across — copy, template, cross-board move, recurrence and
// the CSV/Deck/Trello importers — are deliberately uncapped, so a legitimately
// long imported document must keep importing and keep reading back whole. What
// is bounded instead is the READ that amplifies it: CardMapper::searchInBoards
// truncates the description in SQL, because one search fetches up to 100 rows
// and snippets every one of them.
//
// These are the two halves that must both hold, exercised against a live
// instance and a real database:
//   1. an over-cap imported description still imports and still reads back in
//      FULL from the card detail endpoint (the non-regression that matters), and
//   2. searching it returns a short snippet that still starts at the head of the
//      description.
//
// What this spec deliberately does NOT claim: it cannot tell a SQL-side clip
// from a PHP-side one — SearchService truncates to 160 characters either way, so
// the response is identical. The guard that the clip actually reaches the SQL is
// the mapper unit test (`testSearchInBoardsTruncatesTheDescriptionInSql`).
test.describe('Search over an over-cap imported description', () => {
	const stamp = Math.floor(Date.now() / 1000)
	const term = 'zqxlongdesc' + stamp
	const tail = 'zqxtailmarker' + stamp
	// Comfortably past MAX_DESCRIPTION_LENGTH (64 KiB), i.e. a document no
	// API write could have produced — only an import or a copy.
	const description = term + ' ' + 'y'.repeat(70_000) + ' ' + tail

	const state = {}

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Long Description ' + stamp })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Imported' })
		state.stackId = stack.id

		const title = 'Imported spec ' + stamp
		state.cardTitle = title
		await api.post('/csv-import', {
			document: 'title,description\n' + `"${title}","${description}"\n`,
			boardId: board.id,
			stackId: stack.id,
			mapping: { title: 0, description: 1 },
			hasHeader: true,
		})

		const payload = await api.get(`/boards/${board.id}`)
		state.cardId = payload.cards.find((c) => c.title === title)?.id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('the imported description still reads back in full on the card detail', async () => {
		expect(state.cardId).toBeTruthy()

		const detail = await api.get(`/cards/${state.cardId}`)
		// Byte-for-byte, including the marker at the very end: the detail read is
		// NOT bounded, and clipping it here would be silent data loss.
		expect(detail.description).toHaveLength(description.length)
		expect(detail.description.endsWith(tail)).toBe(true)
		expect(detail.description).toBe(description)
	})

	test('searching it returns a short snippet, not the whole document', async () => {
		expect(state.cardId).toBeTruthy()

		const found = await api.get(`/search?q=${term}`)
		const hit = found.results.find((r) => r.type === 'card' && r.cardId === state.cardId)
		expect(hit).toBeTruthy()

		// 160 characters plus the ellipsis — orders of magnitude below the row.
		expect(hit.snippet.length).toBeLessThanOrEqual(161)
		// And still a USEFUL snippet: the head of the description survives the clip.
		expect(hit.snippet.startsWith(term)).toBe(true)
	})
})
