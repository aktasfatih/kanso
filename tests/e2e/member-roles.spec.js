// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// #3742 — board members carry a role: internal (provider side) or external
// (client side). The sharing panel gains a MANAGE-gated per-member selector;
// new shares default to internal; the API persists role flips.
test.describe('Board member roles (internal/external)', () => {
	const state = { boardId: 0, aclId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Roles E2E ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		// Share with the stock dev-stack test user; no role sent → internal.
		const acl = await api.post(`/boards/${board.id}/acl`, {
			participant: 'tester',
			participantType: 'user',
			permission: 3, // READ | EDIT
		})
		state.aclId = acl.id
		expect(acl.role).toBe('internal')
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('manager flips a member to external in the sharing panel; the role persists', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Open Board settings → Sharing.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.locator('#bs-rail-tab-sharing').click()

		// The tester entry renders with the role selector, defaulted internal.
		const entry = page.locator('.sharing__entry', { hasText: 'tester' })
		await expect(entry).toBeVisible({ timeout: 8_000 })
		const select = entry.locator('[data-test="acl-role-select"]')
		await expect(select).toBeVisible()
		await expect(select).toHaveValue('internal')

		// Flip to External and wait for the PATCH to land.
		const patched = page.waitForResponse(
			(r) => r.url().includes(`/acl/${state.aclId}`) && r.request().method() === 'PATCH' && r.ok(),
		)
		await select.selectOption('external')
		await patched

		// Server-side truth: the stored role flipped (and survives a re-read).
		const board = await api.get(`/boards/${state.boardId}`)
		const acl = board.acl.find((a) => a.participant === 'tester')
		expect(acl.role).toBe('external')

		// The selector reflects the persisted value after the cache refresh.
		await expect(select).toHaveValue('external', { timeout: 8_000 })
	})
})
