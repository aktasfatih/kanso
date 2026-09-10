// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, currentAuth, ncLogin, BASE } from './helpers.js'

// #10300 — re-running a Deck import used to duplicate the board AND every
// attachment's bytes. The picker offered the same unconditional Import button
// forever, and a request that had actually succeeded but lost its response was
// indistinguishable from a failure, so retrying was the obvious move.
//
// Now the picker remembers, per importing user: a board that has been imported
// says "Imported <date>" and its button becomes "Import again", which has to be
// confirmed. Re-importing stays possible — cleaning up a bad first attempt and
// retrying is real — it just cannot happen by accident any more.
//
// The Deck board is created with `currentAuth` (not a hardcoded admin) so it
// belongs to whoever the browser session is under E2E_ISOLATE.
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

test.describe('Import from Deck — repeat import', () => {
	const title = 'E2E Deck Repeat ' + Math.floor(Date.now() / 1000)
	const state = { deckBoardId: 0, kansoBoardIds: [] }

	/** How many Kanso boards this user has with the seeded title. */
	async function kansoCopies() {
		const boards = await api.get('/boards')
		return boards.filter((b) => b.title === title)
	}

	test.beforeAll(async () => {
		const board = await deck('POST', '/boards', { title, color: '0082c9' })
		state.deckBoardId = board.id
		const stack = await deck('POST', `/boards/${board.id}/stacks`, { title: 'To do', order: 1 })
		await deck('POST', `/boards/${board.id}/stacks/${stack.id}/cards`,
			{ title: 'Alpha', type: 'plain', order: 1 })
	})

	test.afterAll(async () => {
		for (const id of state.kansoBoardIds) await api.delete(`/boards/${id}`).catch(() => {})
		if (state.deckBoardId) await deck('DELETE', `/boards/${state.deckBoardId}`).catch(() => {})
	})

	test('marks an imported board and puts a second copy behind a confirmation', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })

		// ── First import: an ordinary, unmarked board with a plain Import button.
		await page.getByRole('button', { name: 'Import', exact: true }).click()
		await page.getByText('Nextcloud Deck', { exact: true }).click()
		let row = page.locator('.deck-import__row', { hasText: title })
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(row.locator('[data-test="deck-import-imported"]')).toHaveCount(0)
		await row.locator('[data-test="deck-import-start"]').click()
		await expect(page.locator('[data-test="deck-import-summary"]')).toBeVisible({ timeout: 30_000 })
		await page.locator('[data-test="deck-import-open"]').click()
		await page.waitForURL(/#\/board\/\d+/, { timeout: 20_000 })
		const firstBoardId = Number(page.url().match(/#\/board\/(\d+)/)[1])
		state.kansoBoardIds.push(firstBoardId)
		expect(await kansoCopies()).toHaveLength(1)

		// ── The API refuses a bare repeat. This is the double-submit itself: the
		// same request the client would send after losing the first response. It
		// must not produce a second board — the guard is server-side, because the
		// client is exactly the party that has lost track of what happened.
		const repeat = await api.raw('POST', `/deck-import/boards/${state.deckBoardId}`, {})
		expect(repeat.status).toBe(409)
		expect((await repeat.json()).error).toBe('already_imported')
		expect(await kansoCopies()).toHaveLength(1)

		// ── Re-open the picker: the row now reports the import instead of
		// offering the button that produced it.
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })
		await page.getByRole('button', { name: 'Import', exact: true }).click()
		await page.getByText('Nextcloud Deck', { exact: true }).click()
		row = page.locator('.deck-import__row', { hasText: title })
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(row.locator('[data-test="deck-import-imported"]')).toContainText('Imported')
		await expect(row.locator('[data-test="deck-import-start"]')).toHaveCount(0)
		await expect(row.locator('[data-test="deck-import-again"]')).toBeVisible()

		// ── "Import again" asks first, and backing out imports nothing.
		await row.locator('[data-test="deck-import-again"]').click()
		await expect(page.locator('[data-test="deck-import-confirm"]')).toBeVisible()
		await page.locator('[data-test="deck-import-confirm-cancel"]').click()
		await expect(page.locator('[data-test="deck-import-confirm"]')).toHaveCount(0)
		expect(await kansoCopies()).toHaveLength(1)

		// ── Confirming goes through: re-importing after cleaning up a bad first
		// attempt is a legitimate thing to want, so it stays possible.
		await page.locator('.deck-import__row', { hasText: title })
			.locator('[data-test="deck-import-again"]').click()
		await expect(page.locator('[data-test="deck-import-confirm"]')).toBeVisible()
		await page.locator('[data-test="deck-import-confirm-yes"]').click()
		await expect(page.locator('[data-test="deck-import-summary"]')).toBeVisible({ timeout: 30_000 })
		await page.locator('[data-test="deck-import-open"]').click()
		await page.waitForURL(/#\/board\/\d+/, { timeout: 20_000 })
		const secondBoardId = Number(page.url().match(/#\/board\/(\d+)/)[1])
		state.kansoBoardIds.push(secondBoardId)

		expect(secondBoardId).not.toBe(firstBoardId)
		expect(await kansoCopies()).toHaveLength(2)
	})
})
