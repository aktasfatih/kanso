// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// #3521 — default board on start.
test.describe('Default board on start', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		state.boardId = (await api.post('/boards', { title: 'Default-Board E2E' })).id
		await api.post('/stacks', { boardId: state.boardId, title: 'To Do' })
	})

	test.afterAll(async () => {
		await api.put('/settings', { defaultBoardId: null }).catch(() => {})
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('setting persists and the app opens directly to the chosen board', async ({ page }) => {
		// Set the preference via the API (the UI toggle lives in board settings).
		const res = await api.put('/settings', { defaultBoardId: state.boardId })
		expect(res.defaultBoardId).toBe(state.boardId)
		expect((await api.get('/settings')).defaultBoardId).toBe(state.boardId)

		// Opening the app root redirects to the default board.
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso`)
		await page.waitForURL(
			(url) => url.hash.includes(`/board/${state.boardId}`),
			{ timeout: 15_000 },
		)
		await expect(page.locator('.board-view__header')).toBeVisible({ timeout: 10_000 })

		// Clearing the preference restores the board-list landing.
		expect((await api.put('/settings', { defaultBoardId: null })).defaultBoardId).toBeNull()
	})
})
