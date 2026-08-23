// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// The all-day ISO the client produces for tomorrow's local calendar day:
// new Date("YYYY-MM-DD").toISOString() → "...T00:00:00.000Z".
function tomorrowAllDayIso() {
	const now = new Date()
	const t = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1)
	const pad = (n) => String(n).padStart(2, '0')
	const ymd = `${t.getFullYear()}-${pad(t.getMonth() + 1)}-${pad(t.getDate())}`
	return new Date(ymd).toISOString()
}

// #3416 — natural due-date tokens: a recognized trailing token in the composer
// sets the card's due date (all-day) and is stripped from the title.
test.describe('Composer due-date tokens', () => {
	const state = { boardId: 0, stackId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Due Tokens E2E' })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To Do' })).id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	async function cards() {
		const board = await api.get(`/boards/${state.boardId}`)
		return board.cards.filter((c) => c.stackId === state.stackId && !c.archived)
	}

	test('"Ship it !tomorrow" strips the token and sets tomorrow as the due date', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })

		const composer = page.locator('.stack-column').first().locator('.card-composer__input')
		await expect(composer).toBeVisible({ timeout: 10_000 })

		await composer.fill('Ship it !tomorrow')
		await composer.press('Enter')

		// The persisted card carries the stripped title + tomorrow's all-day due date.
		await expect.poll(() => cards().then((cs) => cs.map((c) => c.title)), {
			timeout: 10_000,
		}).toEqual(['Ship it'])

		const [card] = await cards()
		expect(new Date(card.duedate).toISOString()).toBe(tomorrowAllDayIso())
		expect(card.allDay).toBe(true)
	})

	test('a mid-title "!" is not treated as a token', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })

		const composer = page.locator('.stack-column').first().locator('.card-composer__input')
		await expect(composer).toBeVisible({ timeout: 10_000 })

		await composer.fill('Fix the !important bug')
		await composer.press('Enter')

		// The title is left intact and no due date is set.
		await expect.poll(() => cards().then((cs) => cs.map((c) => c.title)), {
			timeout: 10_000,
		}).toContain('Fix the !important bug')

		const created = (await cards()).find((c) => c.title === 'Fix the !important bug')
		expect(created).toBeTruthy()
		expect(created.duedate).toBeFalsy()
	})
})
