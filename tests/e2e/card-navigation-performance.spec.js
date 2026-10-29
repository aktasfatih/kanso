// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, BASE, boardUrl, ncLogin } from './helpers.js'

test('opening a card on a loaded board does not wait for alias resolution', async ({ page }) => {
	const board = await api.post('/boards', { title: 'Card navigation performance' })
	try {
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Work' })
		await api.post('/cards', { stackId: stack.id, title: 'Open without a resolver' })
		await ncLogin(page)
		await page.goto(boardUrl(board.id))
		const card = page.locator('.card-tile').filter({ hasText: 'Open without a resolver' })
		await expect(card).toBeVisible()

		let resolutionRequests = 0
		await page.route('**/api/card-navigation/**', async (route) => {
			resolutionRequests++
			await route.abort()
		})
		await card.click()
		await expect(page.locator('.card-modal')).toBeVisible()
		expect(resolutionRequests).toBe(0)
	} finally {
		await api.delete(`/boards/${board.id}`).catch(() => {})
	}
})

test('a card opened from a View uses its board after navigating to a sub-card', async ({ page }) => {
	const board = await api.post('/boards', { title: 'View card navigation board' })
	let viewId
	try {
		const stack = await api.post('/stacks', { boardId: board.id, title: 'View card work' })
		const label = await api.post('/labels', { boardId: board.id, title: 'View card scope', color: 'e01e01' })
		const parent = await api.post('/cards', { stackId: stack.id, title: 'View parent' })
		const child = await api.post('/cards', { stackId: stack.id, title: 'View child' })
		await api.put(`/cards/${parent.id}/labels/${label.id}`)
		await api.put(`/cards/${child.id}/parent`, { parentCardId: parent.id })
		const result = await api.put('/views', { name: 'View card navigation', filter: { labels: [label.id] }, groupBy: 'board', display: 'list' })
		viewId = result.views[result.views.length - 1].id
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/views/${viewId}`)
		await page.locator('.board-list-row__title', { hasText: 'View parent' }).click()
		await page.locator('.card-modal__child-link', { hasText: 'View child' }).click()
		await expect(page).toHaveURL(new RegExp(`/views/${viewId}$`))
		await expect(page.locator('.card-modal__crumb').first()).toHaveText('View card navigation board')
		await expect(page.locator('.card-modal__crumb--column')).toHaveText('View card work')
	} finally {
		if (viewId) await api.delete(`/views/${viewId}`).catch(() => {})
		await api.delete(`/boards/${board.id}`).catch(() => {})
	}
})
