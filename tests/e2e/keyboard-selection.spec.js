// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Keyboard card selection', () => {
	const state = { boardId: 0, url: '' }

	test.beforeAll(async ({ peer }) => {
		const board = await api.post('/boards', { title: `Keyboard selection ${Date.now()}` })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		await api.post('/cards', { stackId: stack.id, title: 'First card' })
		await api.post('/cards', { stackId: stack.id, title: 'Second card' })
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 1,
		})
		state.url = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('x toggles focused cards while navigating and Escape clears selection', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.url)
		const tiles = page.locator('.card-tile')
		await expect(tiles).toHaveCount(2)
		await page.keyboard.press('j')
		await expect(tiles.nth(0)).toBeFocused()
		await page.keyboard.press('x')
		await expect(tiles.nth(0)).toHaveClass(/card-tile--selected/)
		await expect(page.locator('.bulk-action-bar')).toContainText('1')

		await page.keyboard.press('j')
		await expect(tiles.nth(1)).toBeFocused()
		await page.keyboard.press('x')
		await expect(tiles.nth(1)).toHaveClass(/card-tile--selected/)
		await expect(page.locator('.bulk-action-bar')).toContainText('2')
		await page.keyboard.press('k')
		await expect(tiles.nth(0)).toBeFocused()
		await page.keyboard.press('x')
		await expect(tiles.nth(0)).not.toHaveClass(/card-tile--selected/)
		await expect(tiles.nth(1)).toHaveClass(/card-tile--selected/)
		await expect(page.locator('.bulk-action-bar')).toContainText('1')
		await page.getByRole('button', { name: 'More actions' }).click()
		const menu = page.getByRole('dialog', { name: 'More actions' })
		await expect(menu).toBeVisible()
		await page.keyboard.press('Escape')
		await expect(menu).toBeHidden()
		await expect(page.locator('.bulk-action-bar')).toBeVisible()
		await page.keyboard.press('Escape')
		await expect(page.locator('.bulk-action-bar')).toHaveCount(0)
		await expect(tiles.locator('.card-tile__select')).toHaveCount(0)
	})

	test('selects a directly focused tile and honors rapid navigation followed by x', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.url)
		const tiles = page.locator('.card-tile')
		await expect(tiles).toHaveCount(2)
		await tiles.first().focus()
		await page.keyboard.press('x')
		await expect(tiles.first()).toHaveClass(/card-tile--selected/)
		await page.evaluate(() => {
			for (const key of ['j', 'x']) {
				document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }))
			}
		})
		await expect(tiles.nth(1)).toHaveClass(/card-tile--selected/)
		await expect(page.locator('.bulk-action-bar')).toContainText('2')
	})

	test('typing, previews, dialogs and grouped boards do not select a card', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.url)
		const tiles = page.locator('.card-tile')
		await expect(tiles).toHaveCount(2)
		await page.keyboard.press('j')
		await expect(tiles.first()).toBeFocused()
		await page.keyboard.press('n')
		const composer = page.locator('.stack-column input').filter({ visible: true }).first()
		await expect(composer).toBeFocused()
		await page.keyboard.type('x')
		await expect(composer).toHaveValue('x')
		await expect(page.locator('.bulk-action-bar')).toHaveCount(0)
		await page.keyboard.press('Escape')
		await tiles.last().focus()
		await expect(tiles.last()).toBeFocused()
		await page.keyboard.press('Space')
		await expect(page.locator('.card-preview')).toBeVisible()
		await page.keyboard.press('x')
		await expect(page.locator('.bulk-action-bar')).toHaveCount(0)
		await page.keyboard.press('Escape')
		await page.keyboard.press('?')
		const help = page.locator('.modal-container, [role="dialog"]').filter({ hasText: 'Keyboard shortcuts' })
		await expect(help).toBeVisible()
		await expect(help.getByText('Select or deselect the focused card')).toBeVisible()
		await page.keyboard.press('x')
		await expect(page.locator('.bulk-action-bar')).toHaveCount(0)
		await page.keyboard.press('Escape')
		await expect(help).not.toBeVisible()
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByRole('menuitemradio', { name: 'Assignee', exact: true }).click()
		await page.keyboard.press('Escape')
		await expect(page.locator('.swimlane .card-tile')).toHaveCount(2)
		await page.locator('.swimlane .card-tile').first().focus()
		await expect(page.locator('.swimlane .card-tile').first()).toBeFocused()
		await page.keyboard.press('x')
		await expect(page.locator('.bulk-action-bar')).toHaveCount(0)
	})

	test('viewers cannot start selection with x', async ({ browser, peer }) => {
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		try {
			const page = await context.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(state.url)
			const tile = page.locator('.card-tile').first()
			await expect(tile).toBeVisible()
			await page.keyboard.press('j')
			await expect(tile).toBeFocused()
			await page.keyboard.press('x')
			await expect(page.locator('.bulk-action-bar')).toHaveCount(0)
			await page.keyboard.press('?')
			const help = page.locator('.modal-container, [role="dialog"]').filter({ hasText: 'Keyboard shortcuts' })
			await expect(help).toBeVisible()
			await expect(help.getByText('Select or deselect the focused card')).toHaveCount(0)
		} finally {
			await context.close()
		}
	})
})
