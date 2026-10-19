// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, toast } from './helpers.js'

test.describe('Card-created toast', () => {
	const state = { boardId: 0, stackId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Created Toast E2E' })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To Do' })).id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	async function cardIdByTitle(title) {
		const board = await api.get(`/boards/${state.boardId}`)
		return board.cards.find((c) => c.title === title)?.id
	}

	test('board composer: toast keeps focus in the composer and Open opens the new card', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })

		const composer = page.locator('.stack-column').first().locator('.card-composer__input')
		await composer.fill('Toast board card')
		await composer.press('Enter')

		// short-budget-ok: toast auto-dismisses at 7s
		await expect(toast(page, 'Card created')).toBeVisible({ timeout: 6_000 })
		await expect(composer).toBeFocused()

		await toast(page, 'Card created').getByRole('button', { name: 'Open' }).click()

		await expect.poll(() => cardIdByTitle('Toast board card')).toBeTruthy()
		const newId = await cardIdByTitle('Toast board card')
		await expect(page).toHaveURL(new RegExp(`/board/${state.boardId}/card/${newId}$`))
		await expect(page.locator('.card-modal')).toBeVisible()
	})

	test('multi-line paste creates the cards without a toast', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })

		const composer = page.locator('.stack-column').first().locator('.card-composer__input')
		await composer.focus()
		await composer.evaluate((el, value) => {
			const dt = new DataTransfer()
			dt.setData('text', value)
			el.dispatchEvent(new ClipboardEvent('paste', {
				clipboardData: dt,
				bubbles: true,
				cancelable: true,
			}))
		}, 'Toast paste A\nToast paste B')

		await expect.poll(() => cardIdByTitle('Toast paste B')).toBeTruthy()
		expect(await cardIdByTitle('Toast paste A')).toBeTruthy()
		await expect(toast(page, 'Card created')).toHaveCount(0)
	})

	test('list view composer: Open opens the new card', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		await page.locator('.board-view__display-menu button').first().click()
		await page.getByRole('menuitemradio', { name: 'List', exact: true }).click()
		await page.keyboard.press('Escape')
		await page.waitForSelector('.board-list-group', { timeout: 15_000 })

		// The hidden kanban composer stays mounted after the view switch.
		const composer = page.locator('.board-list-table .card-composer__input').first()
		await composer.fill('Toast list card')
		await composer.press('Enter')

		// short-budget-ok: toast auto-dismisses at 7s
		await expect(toast(page, 'Card created')).toBeVisible({ timeout: 6_000 })
		await expect(composer).toBeFocused()
		await toast(page, 'Card created').getByRole('button', { name: 'Open' }).click()

		await expect.poll(() => cardIdByTitle('Toast list card')).toBeTruthy()
		const newId = await cardIdByTitle('Toast list card')
		await expect(page).toHaveURL(new RegExp(`/board/${state.boardId}/card/${newId}$`))
		await expect(page.locator('.card-modal')).toBeVisible()
	})
})
