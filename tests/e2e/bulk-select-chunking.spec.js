// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10435 — a shift-range selection larger than the server's per-request cap
// (BulkCardService::MAX_CARDS = 100) used to fail EVERY bulk action wholesale
// with a 400, because the selection was posted as one oversized list. The fix
// chunks the selection in useBulkSelect.apply(); this spec proves it end to end.
//
// The selection deliberately goes through the REAL shift-click path rather than
// being stuffed into the store: `selectRange` has no upper limit (on purpose —
// silently refusing to select what the user dragged over would be a worse bug),
// so "a >100-card selection is reachable by a normal gesture" is half of what is
// under test. Only the 105 cards themselves are seeded over the API, since
// typing them into the composer would cost minutes for no extra coverage.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// One chunk boundary plus a remainder: 100 + 5. Two requests, sequentially.
const CARD_COUNT = 105

/** Zero-padded title so the LAST card is addressable by name. */
const titleFor = (i) => `Chunk Card ${String(i).padStart(3, '0')}`

/**
 * Scroll the (virtualized) column to the bottom until the given card tile is
 * rendered, then return it. TanStack Virtual only mounts the visible window, so
 * the last of 105 cards does not exist in the DOM until we get there — and the
 * measured total size grows as rows are measured, so one jump is not always
 * enough.
 *
 * @param {import('@playwright/test').Page} page - the page under test
 * @param {string} title - the card title to reveal
 * @return {Promise<import('@playwright/test').Locator>} the revealed tile
 */
async function scrollToCard(page, title) {
	const tile = page.locator('.card-tile', { hasText: title })
	await expect.poll(async () => {
		await page.locator('.stack-column__cards').first()
			.evaluate((el) => { el.scrollTop = el.scrollHeight })
		await page.waitForTimeout(150)
		return await tile.count()
	}, { timeout: 20_000 }).toBe(1)
	return tile
}

test.describe('Bulk action over a >100-card selection (#10435)', () => {
	const state = { boardId: 0, backlogId: 0, doneId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: `Bulk Chunk E2E ${Date.now()}` })
		state.boardId = board.id
		state.backlogId = (await api.post('/stacks', { boardId: board.id, title: 'Backlog' })).id
		state.doneId = (await api.post('/stacks', { boardId: board.id, title: 'Target' })).id
		// Sequential on purpose: each create appends after the current last card,
		// so this is the order the board will show them in.
		for (let i = 1; i <= CARD_COUNT; i++) {
			await api.post('/cards', { stackId: state.backlogId, title: titleFor(i) })
		}
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('shift-selecting 105 cards and moving them applies to every one', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 20_000 })
		await expect(page.locator('.card-tile', { hasText: titleFor(1) }))
			.toBeVisible({ timeout: 15_000 })

		// Enter multi-select mode from the ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: 'Select multiple cards' }).click()

		// Anchor on the first card, then shift-click the last one: the range covers
		// all 105, including the ~90 that are not even mounted (selectRange works
		// off the full ordered visible list, not off the DOM).
		await page.locator('.card-tile', { hasText: titleFor(1) }).click()
		await expect(page.locator('.bulk-action-bar')).toContainText('1 selected', { timeout: 10_000 })

		const lastTile = await scrollToCard(page, titleFor(CARD_COUNT))
		await lastTile.click({ modifiers: ['Shift'] })
		await expect(page.locator('.bulk-action-bar'))
			.toContainText(`${CARD_COUNT} selected`, { timeout: 10_000 })

		// Move the whole selection. Before the fix this was a single request of 105
		// ids → 400 "Too many cards selected", and nothing moved at all.
		await page.getByRole('button', { name: 'Move to…' }).click()
		await page.getByRole('menuitem', { name: 'Target' }).click()

		// The summary is the MERGED one across both chunks, so the count is the
		// whole selection — not 100, and not a failure banner.
		await expect(page.locator('.toastify.toast-success'))
			.toContainText(`${CARD_COUNT} cards updated`, { timeout: 60_000 })
		await expect(page.getByText('Bulk action failed.')).toHaveCount(0)

		// Every card lands in the target column — both chunks committed.
		await expect
			.poll(async () => {
				const board = await api.get(`/boards/${state.boardId}`)
				return board.cards.filter((c) => c.stackId === state.doneId && !c.archived).length
			}, { timeout: 30_000 })
			.toBe(CARD_COUNT)
		await expect
			.poll(async () => {
				const board = await api.get(`/boards/${state.boardId}`)
				return board.cards.filter((c) => c.stackId === state.backlogId && !c.archived).length
			}, { timeout: 15_000 })
			.toBe(0)

		// The selection itself is emptied (selection MODE stays on, as it always
		// has) — apply() still clear()s exactly like it did before chunking.
		await expect(page.locator('.bulk-action-bar')).toContainText('0 selected')
	})
})
