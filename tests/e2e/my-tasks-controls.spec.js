// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, ncLogin, BASE } from './helpers.js'

function due(days, now = Date.now()) {
	const date = new Date(now)
	date.setHours(12, 0, 0, 0)
	date.setDate(date.getDate() + days)
	return date.toISOString()
}

function cards(now) {
	const common = { stackTitle: 'To do', doneAt: 0, parentCardId: null }
	return [
		{ ...common, id: 900001, boardId: 501, boardTitle: 'Alpha board', title: 'Cedar task', duedate: due(-1, now), priority: 4, startedAt: 0 },
		{ ...common, id: 900002, boardId: 502, boardTitle: 'Beta board', title: 'Alpha task', duedate: due(0, now), priority: 1, startedAt: 123 },
		{ ...common, id: 900003, boardId: 501, boardTitle: 'Alpha board', title: 'Bravo task', duedate: null, priority: 2, startedAt: 0 },
	]
}

async function openTasks(page, { truncated = false, now } = {}) {
	await page.route('**/apps/kanso/api/boards', (route) => route.fulfill({
		status: 200,
		contentType: 'application/json',
		body: JSON.stringify([
			{ id: 501, title: 'Alpha board', color: '0082c9', archived: false },
			{ id: 502, title: 'Beta board', color: '0082c9', archived: false },
			{ id: 503, title: 'Gamma board', color: '0082c9', archived: false },
		]),
	}))
	await page.route('**/apps/kanso/api/my-cards', (route) => route.fulfill({
		status: 200,
		contentType: 'application/json',
		headers: { 'X-Kanso-Limit': '200', 'X-Kanso-Truncated': truncated ? '1' : '0' },
		body: JSON.stringify(cards(now)),
	}))
	await ncLogin(page)
	await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)
	await expect(page.locator('.my-cards-view__row', { hasText: 'Cedar task' })).toBeVisible()
}

test('search and board, status and due filters narrow the loaded tasks', async ({ page }) => {
	await openTasks(page)
	const rows = page.locator('.my-cards-view__row:not(.my-cards-view__row--done)')
	await expect(rows).toHaveCount(3)

	await page.getByLabel('Search tasks').pressSequentially('brAvO task')
	await expect(page.getByLabel('Search tasks')).toHaveValue('brAvO task')
	await expect(rows).toHaveCount(1)
	await expect(rows.first()).toContainText('Bravo task')
	await page.getByLabel('Search tasks').clear()

	await page.getByLabel('Board', { exact: true }).selectOption({ label: 'Alpha board' })
	await expect(rows).toHaveCount(2)
	await expect(rows.filter({ hasText: 'Alpha task' })).toHaveCount(0)

	await page.getByLabel('Due date', { exact: true }).selectOption({ label: 'Overdue' })
	await expect(rows).toHaveCount(1)
	await expect(rows.first()).toContainText('Cedar task')
	await page.getByLabel('Due date', { exact: true }).selectOption({ label: 'All due dates' })

	await page.getByLabel('Status', { exact: true }).selectOption({ label: 'In progress' })
	await expect(rows).toHaveCount(0)
	await expect(page.locator('.my-cards-view .empty-content')).toBeVisible()
	await expect(page.locator('.my-cards-view .empty-content')).not.toContainText('No tasks assigned to you')
})

test('sorting orders the displayed tasks and board view groups by status', async ({ page }) => {
	await openTasks(page)
	const rows = page.locator('.my-cards-view__row:not(.my-cards-view__row--done)')
	await page.getByLabel('Sort by').selectOption({ label: 'Title' })
	await expect(rows.locator('.my-cards-view__card-title')).toHaveText(['Alpha task', 'Bravo task', 'Cedar task'])

	await page.getByLabel('Sort by').selectOption({ label: 'Priority' })
	await expect(rows.locator('.my-cards-view__card-title')).toHaveText(['Cedar task', 'Bravo task', 'Alpha task'])
	await page.getByLabel('Sort by').selectOption({ label: 'Board' })
	await expect(rows.locator('.my-cards-view__card-title')).toHaveText(['Bravo task', 'Cedar task', 'Alpha task'])

	await page.getByLabel('View', { exact: true }).selectOption({ label: 'Board' })
	const notStarted = page.locator('.my-cards-view__section', { has: page.getByRole('heading', { name: /Not started/ }) })
	const inProgress = page.locator('.my-cards-view__section', { has: page.getByRole('heading', { name: /In progress/ }) })
	await expect(notStarted.locator('.my-cards-view__row')).toHaveCount(2)
	await expect(notStarted).toContainText('Cedar task')
	await expect(notStarted).toContainText('Bravo task')
	await expect(inProgress.locator('.my-cards-view__row')).toHaveCount(1)
	await expect(inProgress).toContainText('Alpha task')
	await expect(page.getByRole('button', { name: 'Recently done' })).toBeVisible()
})

test('the today filter refreshes at local midnight without a data change', async ({ page }) => {
	const day = new Date(2026, 4, 15, 20, 0, 0)
	const beforeMidnight = new Date(2026, 4, 15, 23, 59, 55)
	await page.clock.install({ time: day })
	await openTasks(page, { now: day })
	await page.clock.pauseAt(beforeMidnight)
	await page.getByLabel('Due date', { exact: true }).selectOption({ label: 'Today' })
	await expect(page.locator('.my-cards-view__row', { hasText: 'Alpha task' })).toBeVisible()
	await page.clock.fastForward(10_000)
	await expect(page.locator('.my-cards-view__row', { hasText: 'Alpha task' })).toHaveCount(0)
})

test('recently completed tasks are requested for the selected board', async ({ page }) => {
	const requested = []
	await page.route('**/apps/kanso/api/my-cards/recently-done**', (route) => {
		const boardId = new URL(route.request().url()).searchParams.get('boardId')
		requested.push(boardId)
		const results = boardId === '501' ? [{ ...cards()[0], doneAt: 1700000000 }] : []
		return route.fulfill({ status: 200, contentType: 'application/json', headers: { 'X-Kanso-Limit': '50', 'X-Kanso-Done-Window-Days': '14', 'X-Kanso-Truncated': '0' }, body: JSON.stringify(results) })
	})
	await openTasks(page)
	await page.getByLabel('Board', { exact: true }).selectOption({ label: 'Alpha board' })
	await page.getByRole('button', { name: 'Recently done' }).click()
	await expect.poll(() => requested).toContain('501')
	await expect(page.locator('.my-cards-view__row--done', { hasText: 'Cedar task' })).toBeVisible()
	await page.getByLabel('Board', { exact: true }).selectOption({ label: 'Beta board' })
	await expect.poll(() => requested).toContain('502')
	await expect(page.locator('.my-cards-view__row--done', { hasText: 'Cedar task' })).toHaveCount(0)
})

test('a filter with no matches still discloses a truncated feed', async ({ page }) => {
	await openTasks(page, { truncated: true })
	await page.getByLabel('Board', { exact: true }).selectOption({ label: 'Gamma board' })
	const empty = page.locator('.my-cards-view .empty-content')
	await expect(empty).toContainText('Only loaded tasks are shown')
	await expect(empty).not.toContainText('Nothing on the selected board is assigned to you')
	await expect(page.locator('.my-cards-view__truncation')).toContainText('Only the first 200 assigned cards are loaded')
	await page.getByLabel('Board', { exact: true }).selectOption({ label: 'All boards' })
	await page.getByLabel('Search tasks').fill('no matching task')
	await expect(empty).toBeVisible()
	await expect(page.locator('.my-cards-view__truncation')).toContainText('Only the first 200 assigned cards are loaded')
	await expect(page.getByLabel('Search tasks')).toHaveValue('no matching task')
})

test('status board remains usable on a narrow screen', async ({ page }) => {
	await page.setViewportSize({ width: 360, height: 780 })
	await openTasks(page)
	await page.getByLabel('View', { exact: true }).selectOption({ label: 'Board' })
	const board = page.locator('.my-cards-view__board-layout')
	await expect(board).toBeVisible()
	await expect(board.locator('.my-cards-view__section')).toHaveCount(2)
	await expect(page.locator('.my-cards-view__row', { hasText: 'Cedar task' })).toBeVisible()
	await board.evaluate((el) => { el.scrollLeft = el.scrollWidth })
	await expect(page.locator('.my-cards-view__row', { hasText: 'Alpha task' })).toBeInViewport()
})
