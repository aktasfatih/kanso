// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// #3570 — board settings sidebar restructured into a vertical section rail with a
// danger group pinned at the bottom and a collapsible Automation pane.
test.describe('Board settings section rail', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Rail E2E ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		await api.post('/stacks', { boardId: board.id, title: 'To Do' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('rail switches panes; Archive/Delete live in the General tab danger zone', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Board settings now lives in the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()

		// The rail exposes a proper vertical tablist (holding only the section tabs).
		const rail = page.locator('.bs-rail .bs-rail__tabs[role="tablist"]')
		await expect(rail).toBeVisible({ timeout: 8_000 })

		// Click through the rail sections and assert the matching pane shows.
		await page.getByRole('tab', { name: 'General' }).click()
		await expect(page.locator('#bs-pane-general')).toBeVisible()
		await expect(page.getByRole('tab', { name: 'General' })).toHaveAttribute('aria-selected', 'true')

		await page.getByRole('tab', { name: /review types/i }).click()
		await expect(page.locator('#bs-pane-review-types')).toBeVisible()
		await expect(page.locator('#bs-pane-general')).toBeHidden()

		await page.getByRole('tab', { name: /workflow/i }).click()
		await expect(page.locator('#bs-pane-workflow')).toBeVisible()

		await page.getByRole('tab', { name: /automation/i }).click()
		await expect(page.locator('#bs-pane-automation')).toBeVisible()

		// Board actions (Export/Duplicate/Archive/Delete) now live in the General
		// tab; Archive + Delete sit in a visually distinct danger zone.
		await page.getByRole('tab', { name: 'General' }).click()
		await expect(page.locator('#bs-pane-general')).toBeVisible()
		const general = page.locator('#bs-pane-general')
		await expect(general.locator('[data-test="board-export"]')).toBeVisible()
		await expect(general.locator('[data-test="board-duplicate"]')).toBeVisible()

		const danger = general.locator('.board-actions__danger')
		await expect(danger.getByRole('button', { name: 'Archive' })).toBeVisible()
		const deleteBtn = danger.getByRole('button', { name: 'Delete' })
		await expect(deleteBtn).toBeVisible()

		// Delete asks for confirmation instead of firing immediately.
		await deleteBtn.click()
		const confirm = page.locator('.bs-delete-confirm')
		await expect(confirm).toBeVisible()
		await confirm.getByRole('button', { name: 'Cancel' }).click()
		await expect(confirm).toBeHidden()
	})

	test('an Automation group collapses and expands', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Board settings now lives in the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /automation/i }).click()

		// Column automations starts expanded → its rule form is visible.
		const header = page.getByRole('button', { name: /Column automations/i })
		await expect(header).toBeVisible({ timeout: 8_000 })
		await expect(header).toHaveAttribute('aria-expanded', 'true')
		const roleSelect = page.locator(`#auto-role-${state.boardId}`)
		await expect(roleSelect).toBeVisible()

		// Collapsing the group hides its body.
		await header.click()
		await expect(header).toHaveAttribute('aria-expanded', 'false')
		await expect(roleSelect).toBeHidden()

		// Expanding again brings it back.
		await header.click()
		await expect(header).toHaveAttribute('aria-expanded', 'true')
		await expect(roleSelect).toBeVisible()
	})
})
