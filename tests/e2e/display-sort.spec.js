// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Display sort (#3442)', () => {
	const state = { boardId: 0 }
	// Created in this order → manual (fractional) order is Charlie, Alpha, Bravo.
	const created = ['Charlie', 'Alpha', 'Bravo']

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Sort ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		for (const title of created) {
			await api.post('/cards', { stackId: stack.id, title })
		}
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('sorting by Title reorders the rows; Manual restores fractional order', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// View + Sort now live in one Display popover. Pick an option then Escape to
		// close, so the next open() is deterministic (radios don't close the menu).
		const pick = async (name) => {
			await page.locator('.board-view__display-menu button').first().click()
			// Let the teleported popover settle before clicking (a click landing mid
			// open/re-render can miss the freshly-mounted radio).
			await page.waitForTimeout(400)
			await page.locator('.action-radio__text', { hasText: new RegExp('^' + name + '$') }).click()
			await page.waitForTimeout(150)
			await page.keyboard.press('Escape')
			await page.waitForTimeout(200)
		}

		// Switch to List view for a deterministic row order.
		await pick('List')
		await expect(page.locator('.board-list-row').first()).toBeVisible()

		const titles = () => page.locator('.board-list-row__title').allTextContents()

		// Manual order = creation (fractional) order.
		expect((await titles()).map((s) => s.trim())).toEqual(created)

		// Sort by Title → alphabetical.
		await pick('Title')
		await expect
			.poll(async () => (await titles()).map((s) => s.trim()))
			.toEqual(['Alpha', 'Bravo', 'Charlie'])

		// Back to Manual → fractional order restored (view-only sort, keys intact).
		await pick('Manual')
		await expect
			.poll(async () => (await titles()).map((s) => s.trim()))
			.toEqual(created)
	})
})

test('Last updated sorts newest first, persists direction, and follows remote updates without changing manual keys', async ({ page }) => {
	const board = await api.post('/boards', { title: `Recently updated ${Date.now()}` })
	try {
		await api.patch(`/boards/${board.id}`, { newCardsOnTop: false })
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		const cards = []
		for (const title of ['Original first', 'Original second', 'Original third']) {
			cards.push(await api.post('/cards', { stackId: stack.id, title }))
		}
		const originalKeys = cards.map(({ id, sortKey }) => ({ id, sortKey }))
		await expect.poll(() => Math.floor(Date.now() / 1000)).toBeGreaterThan(Math.max(...cards.map((card) => card.lastModified)))
		const updated = await api.patch(`/cards/${cards[1].id}`, { title: 'Newest second' })
		cards[1] = { ...cards[1], ...updated }
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}`)
		const pick = async (name) => {
			await page.locator('.board-view__display-menu button').first().click()
			const option = page.getByRole('menuitemradio', { name, exact: true })
			await expect(option).toBeVisible()
			await option.click()
			await page.keyboard.press('Escape')
		}
		const titles = () => page.locator('.board-list-row__title').allTextContents().then((rows) => rows.map((title) => title.trim()))
		const expected = (direction) => [...cards].sort((a, b) => direction * (a.lastModified - b.lastModified)
			|| (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0)).map((card) => card.title)
		await pick('List')
		await pick('Last updated')
		await expect.poll(titles).toEqual(expected(-1))
		expect(await page.evaluate((id) => localStorage.getItem(`kanso.sortDir.${id}`), board.id)).toBe('desc')
		await pick('Ascending')
		await expect.poll(titles).toEqual(expected(1))
		await page.reload()
		await expect.poll(titles).toEqual(expected(1))
		expect(await page.evaluate((id) => localStorage.getItem(`kanso.sortDir.${id}`), board.id)).toBe('asc')
		await pick('Descending')
		await expect.poll(titles).toEqual(expected(-1))
		await expect.poll(() => Math.floor(Date.now() / 1000)).toBeGreaterThan(Math.max(...cards.map((card) => card.lastModified)))
		const remote = await api.patch(`/cards/${cards[0].id}`, { title: 'Newest remote first' })
		cards[0] = { ...cards[0], ...remote }
		await expect.poll(titles, { timeout: 45_000 }).toEqual(expected(-1))
		await pick('Manual')
		await expect.poll(titles).toEqual(cards.map((card) => card.title))
		const current = await api.get(`/boards/${board.id}`)
		expect(current.cards.map(({ id, sortKey }) => ({ id, sortKey })).sort((a, b) => a.id - b.id)).toEqual(originalKeys.sort((a, b) => a.id - b.id))
	} finally {
		await api.delete(`/boards/${board.id}`).catch(() => {})
	}
})
