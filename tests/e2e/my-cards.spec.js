// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, me } from './helpers.js'

test.describe('My tasks (#3441)', () => {
	const state = { boardId: 0, stackId: 0, assignedCardId: 0, unassignedCardId: 0, title: '', emptyBoardId: 0, emptyBoardTitle: '' }

	test.beforeAll(async () => {
		state.title = 'MyTask ' + Math.floor(Date.now() / 1000)
		const board = await api.post('/boards', { title: 'MyTasks ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To do' })).id
		state.assignedCardId = (await api.post('/cards', { stackId: state.stackId, title: state.title })).id
		state.unassignedCardId = (await api.post('/cards', { stackId: state.stackId, title: 'Not mine ' + Math.floor(Date.now() / 1000) })).id
		// Assign only the first card to the current user.
		await api.put(`/cards/${state.assignedCardId}/assignees/${me}`)

		// A second board where nothing is assigned to me — the hub's board
		// filter pointed at it must not claim I have no tasks anywhere.
		state.emptyBoardTitle = 'MyTasksEmpty ' + Math.floor(Date.now() / 1000)
		state.emptyBoardId = (await api.post('/boards', { title: state.emptyBoardTitle })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		if (state.emptyBoardId) await api.delete(`/boards/${state.emptyBoardId}`).catch(() => {})
	})

	test('my-cards returns only cards assigned to me', async () => {
		const cards = await api.get('/my-cards')
		const ids = cards.map((c) => c.id)
		expect(ids).toContain(state.assignedCardId)
		expect(ids).not.toContain(state.unassignedCardId)
		const mine = cards.find((c) => c.id === state.assignedCardId)
		expect(mine.boardId).toBe(state.boardId)
		expect(mine.boardTitle).toBeTruthy()
	})

	test('a done card drops out of my tasks', async () => {
		const doneCardId = (await api.post('/cards', { stackId: state.stackId, title: 'Finish me ' + Math.floor(Date.now() / 1000) })).id
		await api.put(`/cards/${doneCardId}/assignees/${me}`)
		expect((await api.get('/my-cards')).map((c) => c.id)).toContain(doneCardId)

		await api.patch(`/cards/${doneCardId}`, { done: true })
		expect((await api.get('/my-cards')).map((c) => c.id)).not.toContain(doneCardId)
	})

	test('My tasks panel lists the card and deep-links to it', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)

		const row = page.locator('.my-cards-view__row', { hasText: state.title })
		await expect(row).toBeVisible({ timeout: 15_000 })

		await row.click()
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 10_000 })
		await expect(page).toHaveURL(new RegExp(`/board/${state.boardId}/card/${state.assignedCardId}`))
	})

	// #10068/1 — the rows are tabbable role=button elements, so keyboard focus
	// must be visible (WCAG 2.4.7). The style used to set `outline: none`.
	test('a keyboard-focused row shows a focus ring', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)

		const row = page.locator('.my-cards-view__row', { hasText: state.title })
		await expect(row).toBeVisible({ timeout: 15_000 })

		// Reach the row by keyboard so :focus-visible applies.
		await page.keyboard.press('Tab')
		await row.focus()

		const ring = await row.evaluate((el) => {
			const style = getComputedStyle(el)
			return {
				focusVisible: el.matches(':focus-visible'),
				outlineStyle: style.outlineStyle,
				outlineWidth: parseFloat(style.outlineWidth),
			}
		})
		expect(ring.focusVisible).toBe(true)
		expect(ring.outlineStyle).not.toBe('none')
		expect(ring.outlineWidth).toBeGreaterThan(0)
	})

	// #10068/2 — role="button" must respond to Space, not only Enter.
	test('Space on a focused row opens the card', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)

		const row = page.locator('.my-cards-view__row', { hasText: state.title })
		await expect(row).toBeVisible({ timeout: 15_000 })

		await row.focus()
		await page.keyboard.press('Space')

		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 10_000 })
		await expect(page).toHaveURL(new RegExp(`/board/${state.boardId}/card/${state.assignedCardId}`))
	})

	// #10068/3 — the empty block fires AFTER the board filter, so unfiltered
	// copy ("no tasks assigned to you") would state something false.
	test('filtering to a board with nothing assigned says so, not "no tasks anywhere"', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-work`)
		await expect(page.locator('.my-cards-view')).toBeVisible({ timeout: 15_000 })

		// Filter the hub to the board where nothing is assigned to me.
		await page.locator('#my-work-board-filter').click()
		await page.locator('#my-work-board-filter').fill(state.emptyBoardTitle)
		await page.locator('li[role="option"]', { hasText: state.emptyBoardTitle }).first().click()

		const empty = page.locator('.my-cards-view .empty-content')
		await expect(empty).toBeVisible({ timeout: 10_000 })
		await expect(empty).toContainText('No tasks on this board')
		await expect(empty).not.toContainText('No tasks assigned to you')
	})

	// #10068/4 — the 200-row cap used to be silent, and the nav badge counted
	// the same truncated array (a permanently frozen, wrong "200"). The
	// response is stubbed at the cap so the assertion doesn't need 201 cards.
	test('a capped feed is announced on the page and as a "200+" nav badge', async ({ page }) => {
		const capped = Array.from({ length: 200 }, (_, i) => ({
			id: 900000 + i,
			boardId: state.boardId,
			boardTitle: 'Capped board',
			stackTitle: 'To do',
			title: 'Capped task ' + i,
			duedate: null,
			priority: 0,
			doneAt: 0,
			startedAt: 0,
			parentCardId: null,
		}))
		await page.route('**/apps/kanso/api/my-cards', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				headers: { 'X-Kanso-Limit': '200', 'X-Kanso-Truncated': '1' },
				body: JSON.stringify(capped),
			}),
		)

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)

		await expect(page.locator('.my-cards-view__truncation')).toContainText(
			'Only the first 200 assigned cards are loaded',
			{ timeout: 15_000 },
		)

		const tasksEntry = page.locator('.app-navigation-entry-wrapper', {
			has: page.locator('.app-navigation-entry-link', { hasText: 'My Tasks' }),
		})
		await expect(tasksEntry.locator('.app-navigation-entry__counter-wrapper')).toHaveText('200+', {
			timeout: 10_000,
		})
	})
})
