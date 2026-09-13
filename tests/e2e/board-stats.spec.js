// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Board analytics e2e (#3448): a board with a few cards across two stacks and
// mixed priorities. The header analytics button opens the CSS-bar stats page,
// which shows the "Cards by stack" distribution and the at-a-glance counters.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

/** Escape a fixture title for use inside a RegExp. */
function esc(s) {
	return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

test.describe('Board analytics', () => {
	const state = { boardId: 0, boardTitle: '', todoStackId: 0 }

	test.beforeAll(async () => {
		state.boardTitle = `Analytics E2E ${Date.now()}`
		const board = await api.post('/boards', { title: state.boardTitle })
		state.boardId = board.id
		const todo = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		state.todoStackId = todo.id
		const doing = await api.post('/stacks', { boardId: board.id, title: 'Doing' })
		const c1 = await api.post('/cards', { stackId: todo.id, title: 'Card one' })
		await api.post('/cards', { stackId: todo.id, title: 'Card two' })
		await api.post('/cards', { stackId: doing.id, title: 'Card three' })
		await api.patch(`/cards/${c1.id}`, { priority: 4 })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('the header analytics button opens the stats page with distributions', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 10_000 })

		// Board analytics now lives in the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: 'Board analytics' }).click()

		await expect(page).toHaveURL(new RegExp(`#/board/${state.boardId}/stats`))
		const view = page.locator('.board-stats__body')
		await expect(view).toBeVisible({ timeout: 10_000 })

		// The "Cards by stack" distribution renders with humanized stack titles.
		await expect(page.getByText('Cards by stack')).toBeVisible()
		await expect(view).toContainText('To Do')
		await expect(view).toContainText('Doing')

		// At-a-glance counters render (Overdue is always present).
		await expect(page.getByText('Overdue', { exact: true })).toBeVisible()

		// At least one distribution bar rendered.
		expect(await page.locator('.board-stats__bar-row').count()).toBeGreaterThanOrEqual(2)

		// Velocity + cycle-time flow panels render (present even with no
		// completions — velocity shows the 0/week rolling average, cycle time
		// shows its neutral no-data state).
		await expect(page.getByText('Velocity — completed per week')).toBeVisible()
		await expect(page.getByText('Cards / week (avg)')).toBeVisible()
		// Flow window is week-aligned (28d), rendered from the DTO's windowDays.
		await expect(page.getByText(/Cycle time — creation to done \(28d\)/)).toBeVisible()
	})

	test('a hard load of the stats URL names the board and its columns', async ({ page }) => {
		// The HARD path — a bookmark, a refresh, a pasted link — with nothing warm
		// in the query cache. Everything named on this page is resolved against the
		// board (the stats payload carries stack and label IDs only), so while this
		// view peeked at the board cache instead of querying it, a cold load showed
		// "Stack <id>" bars under the bare app name. The click-through above proves
		// none of that: it arrives with the board already cached.
		//
		// A fresh context per test is what makes this cold — a `page.goto` to a URL
		// that differs only in its hash is a same-document navigation and would keep
		// whatever the previous route had already fetched.
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/stats`)

		const view = page.locator('.board-stats__body')
		await expect(view).toBeVisible({ timeout: 15_000 })

		// Real column names on the bars, not the "Stack <id>" fallback.
		const stackLabels = page
			.locator('.board-stats__section', { hasText: 'Cards by stack' })
			.locator('.board-stats__bar-label')
		await expect(stackLabels.filter({ hasText: 'To Do' })).toHaveCount(1)
		await expect(stackLabels.filter({ hasText: 'Doing' })).toHaveCount(1)
		await expect(view).not.toContainText(`Stack ${state.todoStackId}`)

		// …and the board's own name in the tab title (#125), which has the same
		// root cause and fixes itself once the board query resolves.
		await expect(page).toHaveTitle(new RegExp('^' + esc(state.boardTitle) + ' · Analytics'))
	})

	test('the stats API returns board-scoped aggregates', async () => {
		const stats = await api.get(`/boards/${state.boardId}/stats`)
		// Three cards across two stacks.
		const total = stats.byStack.reduce((n, r) => n + r.count, 0)
		expect(total).toBe(3)
		expect(stats.byStack.length).toBe(2)
		// One urgent card by priority.
		expect(stats.byPriority.some((r) => r.priority === 4 && r.count === 1)).toBe(true)
		// Estimate panels null on a board with no estimate scale.
		expect(stats.estimateByStack).toBeNull()
		// Velocity + cycle-time flow metrics are always present. No cards done ⇒
		// zero rolling average, flat trend, null points (no numeric scale), and
		// an empty cycle-time sample.
		expect(stats.velocity.cardsPerWeek).toBe(0)
		expect(stats.velocity.cardsTrend).toBe('flat')
		expect(stats.velocity.pointsPerWeek).toBeNull()
		expect(Array.isArray(stats.velocity.weekly)).toBe(true)
		// Velocity and cycle time share one week-aligned window.
		expect(stats.velocity.windowDays).toBe(stats.cycleTime.windowDays)
		expect(stats.cycleTime.sampleSize).toBe(0)
		expect(stats.cycleTime.medianDays).toBeNull()
	})
})
