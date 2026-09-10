// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, currentAuth, ncLogin, BASE } from './helpers.js'

// #10297 — the Deck import used to navigate straight to the new board, throwing
// its result summary away. A user then saw a board full of cards, concluded the
// migration was complete, and deleted the Deck board — never learning that
// attachments had been dropped. The modal must now REPORT what landed and let
// the user click through.
//
// The source board is created with `currentAuth` (not a hardcoded admin) so the
// Deck board belongs to whoever the browser session is under E2E_ISOLATE.
const DECK = BASE + '/index.php/apps/deck/api/v1.0'

async function deck(method, path, body) {
	const r = await fetch(DECK + path, {
		method,
		headers: {
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
			Authorization: currentAuth,
		},
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
	const text = await r.text()
	return text ? JSON.parse(text) : null
}

test.describe('Import from Deck — result summary', () => {
	const title = 'E2E Deck Summary ' + Math.floor(Date.now() / 1000)
	const state = { deckBoardId: 0, kansoBoardId: 0 }

	test.beforeAll(async () => {
		const board = await deck('POST', '/boards', { title, color: '0082c9' })
		state.deckBoardId = board.id
		const stack = await deck('POST', `/boards/${board.id}/stacks`, { title: 'To do', order: 1 })
		await deck('POST', `/boards/${board.id}/stacks/${stack.id}/cards`,
			{ title: 'Alpha', type: 'plain', order: 1 })
		await deck('POST', `/boards/${board.id}/stacks/${stack.id}/cards`,
			{ title: 'Beta', type: 'plain', order: 2 })
	})

	test.afterAll(async () => {
		if (state.kansoBoardId) await api.delete(`/boards/${state.kansoBoardId}`).catch(() => {})
		if (state.deckBoardId) await deck('DELETE', `/boards/${state.deckBoardId}`).catch(() => {})
	})

	test('shows what was imported instead of navigating away silently', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })

		// Import ▸ Nextcloud Deck, then import the board seeded above.
		await page.getByRole('button', { name: 'Import' }).click()
		await page.getByText('Nextcloud Deck', { exact: true }).click()
		const row = page.locator('.deck-import__row', { hasText: title })
		await expect(row).toBeVisible({ timeout: 20_000 })
		await row.getByRole('button', { name: 'Import', exact: true }).click()

		// The modal stays open and reports the counts that actually landed.
		const summary = page.locator('[data-test="deck-import-summary"]')
		await expect(summary).toBeVisible({ timeout: 30_000 })
		await expect(summary).toContainText('1 column')
		await expect(summary).toContainText('2 cards')
		await expect(summary).toContainText('0 attachments')
		// Nothing was dropped for this board, so no loss warning is shown.
		await expect(page.locator('[data-test="deck-import-skipped"]')).toHaveCount(0)

		// Clicking through from the summary is what navigates to the new board.
		await page.locator('[data-test="deck-import-open"]').click()
		await page.waitForURL(/#\/board\/\d+/, { timeout: 20_000 })
		state.kansoBoardId = Number(page.url().match(/#\/board\/(\d+)/)[1])

		const payload = await api.get(`/boards/${state.kansoBoardId}`)
		expect(payload.board.title).toBe(title)
		expect(payload.cards.map((c) => c.title).sort()).toEqual(['Alpha', 'Beta'])
	})
})
