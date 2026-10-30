// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, authFor, makeApi, ncLogin, BASE } from './helpers.js'

test('a View filters labels across readable boards and saves the selection', async ({ page, peer }) => {
	const stamp = Date.now()
	const boardA = await api.post('/boards', { title: `Label board A ${stamp}` })
	const boardB = await api.post('/boards', { title: `Label board B ${stamp}` })
	const peerApi = makeApi(authFor(peer.user, peer.pass))
	const privateBoard = await peerApi.post('/boards', { title: `Private labels ${stamp}` })
	let viewId = ''
	try {
		const labelA = await api.post('/labels', { boardId: boardA.id, title: `Shared name ${stamp}`, color: 'cc0000' })
		const labelB = await api.post('/labels', { boardId: boardB.id, title: `Shared name ${stamp}`, color: '0000cc' })
		const hidden = await peerApi.post('/labels', { boardId: privateBoard.id, title: `Secret label ${stamp}`, color: '00cc00' })
		const stackA = await api.post('/stacks', { boardId: boardA.id, title: 'To do' })
		const stackB = await api.post('/stacks', { boardId: boardB.id, title: 'To do' })
		const cardA = await api.post('/cards', { stackId: stackA.id, title: `Label card A ${stamp}` })
		const cardB = await api.post('/cards', { stackId: stackB.id, title: `Label card B ${stamp}` })
		await api.put(`/cards/${cardA.id}/labels/${labelA.id}`)
		await api.put(`/cards/${cardB.id}/labels/${labelB.id}`)

		const initialFeed = await api.get(`/views/cards?fl=${labelA.id}`)
		expect(initialFeed.labels.map((label) => label.id)).toEqual(expect.arrayContaining([labelA.id, labelB.id]))
		expect(initialFeed.labels.map((label) => label.id)).not.toContain(hidden.id)
		expect(initialFeed.cards.map((card) => card.id)).toContain(cardA.id)
		expect(initialFeed.cards.map((card) => card.id)).not.toContain(cardB.id)

		const created = await api.put('/views', {
			name: `Label View ${stamp}`,
			filter: { labels: [labelA.id] },
			groupBy: 'status',
			display: 'list',
		})
		viewId = created.views[created.views.length - 1].id
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/views/${viewId}`)
		const rowA = page.locator('.board-list-row__title', { hasText: `Label card A ${stamp}` })
		const rowB = page.locator('.board-list-row__title', { hasText: `Label card B ${stamp}` })
		await expect(rowA).toBeVisible()
		await expect(rowB).toHaveCount(0)
		await page.locator('.board-filter-bar__trigger').click()
		await page.locator('.board-filter-bar__dim-row[data-dim="labels"]').click()
		const optionA = page.locator('.board-filter-bar__label-item', { hasText: `Label board A ${stamp}` })
		const optionB = page.locator('.board-filter-bar__label-item', { hasText: `Label board B ${stamp}` })
		await expect(optionA).toHaveAttribute('aria-checked', 'true')
		await expect(optionB).toHaveAttribute('aria-checked', 'false')
		await expect(page.locator('.board-filter-bar__label-item', { hasText: `Secret label ${stamp}` })).toHaveCount(0)
		await optionB.click()
		await expect(rowB).toBeVisible()
		await optionA.click()
		await expect(rowA).toHaveCount(0)
		await expect(rowB).toBeVisible()
		await page.keyboard.press('Escape')
		const saved = page.waitForResponse((response) => response.url().includes('/api/views') && response.request().method() === 'PUT')
		await page.locator('.view-page__save').click()
		expect((await saved).ok()).toBeTruthy()
		await page.reload()
		await expect(rowB).toBeVisible()
		await expect(rowA).toHaveCount(0)
		await page.locator('.board-filter-bar__trigger').click()
		const cleared = page.waitForRequest((request) => request.url().includes('/api/views/cards') && !request.url().includes('fl='))
		await page.locator('.board-filter-bar__clear').click()
		await cleared
		await expect(page.locator('.board-filter-bar__trigger')).not.toContainText('·')
	} finally {
		if (viewId) await api.delete(`/views/${viewId}`).catch(() => {})
		await api.delete(`/boards/${boardA.id}`).catch(() => {})
		await api.delete(`/boards/${boardB.id}`).catch(() => {})
		await peerApi.delete(`/boards/${privateBoard.id}`).catch(() => {})
	}
})

test('a saved View with a deleted label can clear its filter', async ({ page }) => {
	const stamp = Date.now()
	const board = await api.post('/boards', { title: `Deleted label board ${stamp}` })
	let viewId = ''
	try {
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		const card = await api.post('/cards', { stackId: stack.id, title: `Deleted label card ${stamp}` })
		const label = await api.post('/labels', { boardId: board.id, title: `Deleted label ${stamp}`, color: 'cc0000' })
		await api.put(`/cards/${card.id}/labels/${label.id}`)
		const created = await api.put('/views', { name: `Deleted label view ${stamp}`, filter: { labels: [label.id] }, groupBy: 'status', display: 'list' })
		viewId = created.views[created.views.length - 1].id
		await api.delete(`/labels/${label.id}`)
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/views/${viewId}`)
		await expect(page.locator('.board-list-row__title', { hasText: `Deleted label card ${stamp}` })).toHaveCount(0)
		await page.locator('.board-filter-bar__trigger').click()
		await expect(page.locator('.board-filter-bar__dim-row[data-dim="labels"]')).toBeVisible()
		const cleared = page.waitForRequest((request) => request.url().includes('/api/views/cards') && !request.url().includes('fl='))
		await page.locator('.board-filter-bar__clear').click()
		await cleared
		await expect(page.locator('.board-filter-bar__trigger')).not.toContainText('·')
	} finally {
		if (viewId) await api.delete(`/views/${viewId}`).catch(() => {})
		await api.delete(`/boards/${board.id}`).catch(() => {})
	}
})
