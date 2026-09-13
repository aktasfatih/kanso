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
		// The other preferences this spec flips are per USER, not per board, so
		// they would follow the worker into every later spec. Hand the account
		// back the way a fresh user finds it.
		await api.put('/settings', { cardDiscussionPosition: 'side' }).catch(() => {})
		await api.put('/settings', { editorToolbarHidden: false }).catch(() => {})
		await api.put('/settings', { hiddenNavSections: [] }).catch(() => {})
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

	/**
	 * The user-visible symptom of the "every save wrote every key" bug: you turn
	 * on "Open this board when Kanso starts", then flip any other switch, and the
	 * app quietly stops opening on your board. Nothing warns you, and the two
	 * actions look unrelated — so the thing asserted here is the landing page
	 * after a reload, not the shape of the API response.
	 *
	 * Driven entirely through the real UI: the settings dialog is what sends
	 * one-key request bodies, so an API-only version of this test would not
	 * exercise the code path that broke.
	 */
	test('the default board survives toggling unrelated settings', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// 1. Turn on "open this board on start" from board settings.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /general/i }).click()
		const startHere = page.getByText('Open this board when Kanso starts', { exact: true })
		await expect(startHere).toBeVisible({ timeout: 10_000 })
		await startHere.click()
		await expect
			.poll(async () => (await api.get('/settings')).defaultBoardId, { timeout: 10_000 })
			.toBe(state.boardId)
		await page.locator('.bs-modal__close').click()
		await expect(page.locator('.bs-modal')).toBeHidden({ timeout: 10_000 })

		// 2. Flip three unrelated switches in the Kanso settings dialog — one per
		//    settings key it owns. Each sends only its own key, which is exactly
		//    the request shape that used to wipe the board on the way through.
		await page.locator('[data-test="open-settings"]').click()
		const dialog = page.getByRole('dialog', { name: /Kanso settings/i })
		await expect(dialog).toBeVisible({ timeout: 10_000 })
		await dialog.getByText('Show the discussion below the card', { exact: true }).click()
		await expect(page.locator('input[data-test="setting-discussion-bottom"]')).toBeChecked()
		await dialog.getByText('Show formatting toolbar', { exact: true }).click()
		await dialog.getByText('Inbox', { exact: true }).click()
		await expect
			.poll(async () => (await api.get('/settings')).cardDiscussionPosition, { timeout: 10_000 })
			.toBe('bottom')
		await expect
			.poll(async () => (await api.get('/settings')).editorToolbarHidden, { timeout: 10_000 })
			.toBe(true)
		await expect
			.poll(async () => (await api.get('/settings')).hiddenNavSections, { timeout: 10_000 })
			.toEqual(['inbox'])
		await page.keyboard.press('Escape')

		// 3. The thing the user actually notices: reopening Kanso still lands on
		//    their board. Asserted BEFORE any API check on purpose — the symptom
		//    is the landing page, not the response shape, so that is what this
		//    test must fail on. Leave Kanso entirely first: the app is a hash
		//    route, so navigating from #/board/N to the bare app URL is a
		//    same-document no-op and the landing logic would never re-run.
		await page.goto(`${BASE}/index.php/apps/dashboard`)
		await page.goto(`${BASE}/index.php/apps/kanso`)
		await page.waitForURL(
			(url) => url.hash.includes(`/board/${state.boardId}`),
			{ timeout: 15_000 },
		)
		await expect(page.locator('.board-view__header')).toBeVisible({ timeout: 10_000 })

		// 4. …and the stored preference really is intact, not merely re-derived.
		expect((await api.get('/settings')).defaultBoardId).toBe(state.boardId)
	})
})
