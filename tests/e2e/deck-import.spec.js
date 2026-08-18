// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const KAN = BASE + '/index.php/apps/kanso/api'
const DECK = BASE + '/index.php/apps/deck/api/v1.0'
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')

async function call(base, method, path, body) {
	const r = await fetch(base + path, {
		method,
		headers: { ...HEADERS, Authorization: AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
	const text = await r.text()
	return text ? JSON.parse(text) : null
}
const deck = (m, p, b) => call(DECK, m, p, b)
const kanso = (m, p, b) => call(KAN, m, p, b)

// One-click Deck import (#3374): seed a real Deck board, import it through the
// Kanso API, and assert the new Kanso board mirrors the source.
test.describe('Import from Deck', () => {
	let deckBoardId = 0
	let kansoBoardId = 0
	const title = 'E2E Import ' + Math.floor(Date.now() / 1000)
	// A Deck title longer than Kanso's STRING(100) title column (#3906): the
	// import must truncate it rather than fail on the varchar overflow.
	const longTitle = 'LONG ' + 'x'.repeat(200)

	test.beforeAll(async () => {
		const board = await deck('POST', '/boards', { title, color: '0082c9' })
		deckBoardId = board.id
		const labelId = board.labels[0].id // a default Deck label
		const stack = await deck('POST', `/boards/${deckBoardId}/stacks`, { title: 'To do', order: 1 })
		await deck('POST', `/boards/${deckBoardId}/stacks`, { title: 'Done', order: 2 })
		const card = await deck('POST', `/boards/${deckBoardId}/stacks/${stack.id}/cards`,
			{ title: 'Alpha', type: 'plain', order: 1, description: 'first card' })
		await deck('POST', `/boards/${deckBoardId}/stacks/${stack.id}/cards`,
			{ title: 'Beta', type: 'plain', order: 2 })
		// A card whose title exceeds Kanso's 100-char title column (#3906).
		await deck('POST', `/boards/${deckBoardId}/stacks/${stack.id}/cards`,
			{ title: longTitle, type: 'plain', order: 3, description: 'has a very long title' })
		await deck('PUT', `/boards/${deckBoardId}/stacks/${stack.id}/cards/${card.id}/assignLabel`, { labelId })
	})

	test.afterAll(async () => {
		if (kansoBoardId) await kanso('DELETE', `/boards/${kansoBoardId}`).catch(() => {})
		if (deckBoardId) await deck('DELETE', `/boards/${deckBoardId}`).catch(() => {})
	})

	test('lists the Deck board and imports it into a mirrored Kanso board', async () => {
		// The Deck board shows up in the importable list with its card count.
		const list = await kanso('GET', '/deck-import/boards')
		expect(list.available).toBe(true)
		const entry = list.boards.find((b) => b.id === deckBoardId)
		expect(entry).toBeTruthy()
		expect(entry.title).toBe(title)
		expect(entry.cardCount).toBe(3)

		// Import it - the >100-char card title must NOT abort the import.
		const res = await kanso('POST', `/deck-import/boards/${deckBoardId}`)
		kansoBoardId = res.boardId
		expect(res.stacks).toBe(2)
		expect(res.cards).toBe(3)
		expect(res.labels).toBeGreaterThan(0)

		// The imported Kanso board mirrors the source.
		const payload = await kanso('GET', `/boards/${kansoBoardId}`)
		expect(payload.board.title).toBe(title)
		const stacks = payload.stacks.slice().sort((a, b) => (a.sortKey < b.sortKey ? -1 : 1))
		expect(stacks.map((s) => s.title)).toEqual(['To do', 'Done'])

		const cards = payload.cards.slice().sort((a, b) => (a.sortKey < b.sortKey ? -1 : 1))
		expect(cards.map((c) => c.title)).toEqual(['Alpha', 'Beta', longTitle.slice(0, 100)])
		// All three cards landed on the first (To do) stack, in order.
		expect(cards[0].stackId).toBe(stacks[0].id)
		expect(cards[1].stackId).toBe(stacks[0].id)
		expect(cards[2].stackId).toBe(stacks[0].id)
		// The label assignment carried across to the first card.
		expect(cards[0].labelIds.length).toBeGreaterThan(0)

		// The long-titled card exists with a title truncated to the 100-char
		// column limit, and its full original title is preserved in the body.
		const longCard = cards[2]
		expect(longCard.title.length).toBe(100)
		const detail = await kanso('GET', `/cards/${longCard.id}`)
		expect(detail.description).toContain('Full title: ' + longTitle)
	})
})
