// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// Regression guard: the public-link (#3531) and calendar-feed (#3541) enable
// switches used the wrong @nextcloud/vue binding (:checked / @update:checked),
// so NcCheckboxRadioSwitch's update:modelValue never fired and clicking the
// switch did nothing. The API worked (so the API-level specs passed), but the
// UI toggle was dead. This drives the actual switches from the UI.
test.describe('Board settings enable switches (public link + calendar feed)', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Switches E2E ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		await api.post('/stacks', { boardId: board.id, title: 'To Do' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('the calendar-feed and public-link switches enable from the UI', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Board settings now lives in the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /automation/i }).click()
		await expect(page.locator('#bs-pane-automation')).toBeVisible({ timeout: 8_000 })

		// --- Calendar feed (#3541) ---
		await page.getByRole('button', { name: /Calendar feed/i }).click() // expand the group
		const calBody = page.locator('#bs-automation-calendar-feed')
		await expect(calBody).toBeVisible()
		// Flip the switch — before the fix this was a no-op.
		await calBody.getByText('Enable calendar feed').click()
		// Enabled → the "Feed active" badge appears (only rendered when enabled).
		await expect(page.getByText('Feed active')).toBeVisible({ timeout: 8_000 })

		// --- Public link (#3531) ---
		// The public-link control moved out of Automation and into the
		// Sharing pane (220f7d7), so switch tabs before toggling it.
		await page.getByRole('tab', { name: /sharing/i }).click()
		await expect(page.locator('#bs-pane-sharing')).toBeVisible({ timeout: 8_000 })
		await page.getByRole('button', { name: /Public link/i }).click() // expand the group
		const pubBody = page.locator('#bs-sharing-public-link')
		await expect(pubBody).toBeVisible()
		await pubBody.getByText('Enable public link').click()
		await expect(page.getByText('Link active')).toBeVisible({ timeout: 8_000 })

		// --- Link expiry (#10466) ---
		// The expiry column was persisted and ENFORCED from day one, but nothing
		// could ever set it — exactly the dead-UI shape this spec exists to catch,
		// one step further back (here the control did not exist at all). Drive the
		// real picker and read the result back off the API.
		const expiry = pubBody.locator('input[type="date"]')
		await expect(expiry).toBeVisible()

		await expiry.fill('2030-12-31')
		await expect.poll(async () => (await api.get(`/boards/${state.boardId}/public-share`)).expiresAt)
			.toBeTruthy()
		let cfg = await api.get(`/boards/${state.boardId}/public-share`)
		// The stored instant is the END of the picked day in THIS browser's
		// timezone — the boundary belongs to whoever set it, so an owner who types
		// "31 Dec" gets every second of their own 31st. The test runner and the
		// browser share a host clock, so local components are the right comparison.
		const stored = new Date(cfg.expiresAt * 1000)
		expect([stored.getFullYear(), stored.getMonth() + 1, stored.getDate()]).toEqual([2030, 12, 31])
		expect([stored.getHours(), stored.getMinutes(), stored.getSeconds()]).toEqual([23, 59, 59])
		// And the link is still the same link — an expiry is not a rotate.
		expect(cfg.enabled).toBe(true)

		// Change it.
		await expiry.fill('2031-01-15')
		await expect.poll(async () => {
			const d = new Date((await api.get(`/boards/${state.boardId}/public-share`)).expiresAt * 1000)
			return [d.getFullYear(), d.getMonth() + 1, d.getDate()].join('-')
		}).toBe('2031-1-15')

		// Clear it.
		await pubBody.getByRole('button', { name: /^Clear$/ }).click()
		await expect.poll(async () => (await api.get(`/boards/${state.boardId}/public-share`)).expiresAt)
			.toBeFalsy()
		cfg = await api.get(`/boards/${state.boardId}/public-share`)
		// Clearing the expiry must never disturb the link itself.
		expect(cfg.enabled).toBe(true)
		await expect(expiry).toHaveValue('')
	})
})
