// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// #3517 — new cards on top (per-board toggle).
test.describe('New cards on top', () => {
	const state = { boardId: 0, stackId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'New-On-Top E2E' })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To Do' })).id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	async function stackOrder() {
		const board = await api.get(`/boards/${state.boardId}`)
		return board.cards
			.filter((c) => c.stackId === state.stackId && !c.archived)
			.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0))
			.map((c) => c.title)
	}

	test('the board toggle makes new cards land at the top', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Open board settings → General tab → turn on "Add new cards to the top".
		// Board settings now lives in the consolidated ⋯ More overflow menu. The
		// settings dialog is a teleported modal; on the slow runner it takes a beat
		// to mount, so wait for each control to be actionable before clicking.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		const generalTab = page.getByRole('tab', { name: 'General' })
		await expect(generalTab).toBeVisible({ timeout: 10_000 })
		await generalTab.click()

		const toggle = page.getByText('Add new cards to the top of a column')
		await expect(toggle).toBeVisible({ timeout: 10_000 })

		// Click the toggle and wait for the board-settings PATCH so the flag is
		// actually persisted server-side before we create cards (the ordering is
		// decided server-side from newCardsOnTop, so we must not race it).
		await Promise.all([
			page.waitForResponse(
				(r) => new RegExp(`/api/boards/${state.boardId}(\\?|$)`).test(r.url()) && r.request().method() === 'PATCH',
				{ timeout: 15_000 },
			),
			toggle.click(),
		])

		// Flag persisted (board fields are nested under `.board`).
		await expect
			.poll(async () => (await api.get(`/boards/${state.boardId}`)).board.newCardsOnTop, { timeout: 10_000 })
			.toBe(true)

		// New cards (via the real create path) now land at the top: B above A.
		// Await each create fully before the next so their sort keys are assigned
		// in a deterministic order (B created after A → B on top when the toggle is on).
		await api.post('/cards', { stackId: state.stackId, title: 'Card A' })
		await api.post('/cards', { stackId: state.stackId, title: 'Card B' })

		// Assert the persisted server-side order (source of truth), polling so the
		// second create's row is visible before we compare on slow infra.
		await expect.poll(() => stackOrder(), { timeout: 10_000 }).toEqual(['Card B', 'Card A'])
	})
})
