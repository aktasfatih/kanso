// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Leave the card composer with Escape', () => {
	const state = { boardId: 0, url: '' }

	test.beforeAll(async ({ peer }) => {
		const board = await api.post('/boards', { title: `Composer escape ${Date.now()}` })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		await api.post('/cards', { stackId: stack.id, title: 'First card' })
		await api.post('/cards', { stackId: stack.id, title: 'Second card' })
		await api.post('/stacks', { boardId: board.id, title: 'Empty' })
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user, participantType: 'user', permission: 1,
		})
		state.url = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('returns to the card, keeps the draft and leaves selection active', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.url)
		const tiles = page.locator('.stack-column').first().locator('.card-tile')
		const composer = page.locator('.stack-column').first().locator('.card-composer__input')
		await expect(tiles).toHaveCount(2)
		await page.keyboard.press('j')
		await expect(tiles.first()).toBeFocused()
		await page.keyboard.press('x')
		await expect(page.locator('.bulk-action-bar')).toBeVisible()
		await page.keyboard.press('n')
		await expect(composer).toBeFocused()
		await composer.fill('Unfinished draft')

		const composingHandled = await composer.evaluate((el) => !el.dispatchEvent(new KeyboardEvent('keydown', {
			key: 'Escape', bubbles: true, cancelable: true, isComposing: true,
		})))
		expect(composingHandled).toBe(false)
		await expect(composer).toBeFocused()
		await composer.press('Escape')
		await expect(tiles.first()).toBeFocused()
		await expect(composer).toHaveValue('Unfinished draft')
		await expect(page.locator('.bulk-action-bar')).toBeVisible()
		await page.keyboard.press('j')
		await expect(tiles.nth(1)).toBeFocused()
		await page.keyboard.press('n')
		await expect(composer).toBeFocused()
		await expect(composer).toHaveValue('Unfinished draft')
	})

	test('leaves an empty column composer without discarding a draft', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.url)
		const composer = page.locator('.stack-column').nth(1).locator('.card-composer__input')
		await composer.fill('Draft in empty column')
		await composer.press('Escape')
		await expect(composer).not.toBeFocused()
		await expect(composer).toHaveValue('Draft in empty column')
		await page.keyboard.press('j')
		await expect(page.locator('.stack-column').first().locator('.card-tile').first()).toBeFocused()
	})

	test('opening the composer cancels an unfinished navigation focus', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.url)
		const composer = page.locator('.stack-column').first().locator('.card-composer__input')
		await expect(page.locator('.card-tile')).toHaveCount(2)
		await page.evaluate(() => {
			for (const key of ['j', 'n']) {
				document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }))
			}
		})
		await expect(composer).toBeFocused()
		await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))))
		await expect(composer).toBeFocused()
	})

	test('brings the originating card back into view when the column was scrolled', async ({ page }) => {
		const board = await api.post('/boards', { title: `Composer scroll ${Date.now()}` })
		try {
			const stack = await api.post('/stacks', { boardId: board.id, title: 'Long column' })
			for (let i = 0; i < 18; i++) {
				await api.post('/cards', { stackId: stack.id, title: `Card ${i}` })
			}
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}`)
			const tile = page.locator('.card-tile').first()
			const list = page.locator('.stack-column__cards').first()
			const composer = page.locator('.card-composer__input').first()
			await expect(tile).toBeVisible()
			await page.keyboard.press('j')
			await expect(tile).toBeFocused()
			await page.keyboard.press('n')
			await expect(composer).toBeFocused()
			await list.evaluate((el) => { el.scrollTop = 180 })
			await expect.poll(() => tile.evaluate((el) => {
				const listRect = el.closest('.stack-column__cards').getBoundingClientRect()
				return el.getBoundingClientRect().bottom <= listRect.top
			})).toBe(true)
			await composer.press('Escape')
			await expect(tile).toBeFocused()
			await expect.poll(() => tile.evaluate((el) => {
				const listRect = el.closest('.stack-column__cards').getBoundingClientRect()
				return el.getBoundingClientRect().bottom > listRect.top
			})).toBe(true)
		} finally {
			await api.delete(`/boards/${board.id}`).catch(() => {})
		}
	})

	test('a viewer without a composer keeps a pending card navigation', async ({ browser, peer }) => {
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		try {
			const page = await context.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(state.url)
			const tiles = page.locator('.card-tile')
			await expect(tiles).toHaveCount(2)
			await expect(page.locator('.card-composer__input')).toHaveCount(0)
			await page.evaluate(() => {
				for (const key of ['j', 'n']) {
					document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }))
				}
			})
			await expect(tiles.first()).toBeFocused()
		} finally {
			await context.close()
		}
	})
})
