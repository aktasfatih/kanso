// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, BASE, api, ncLogin } from './helpers.js'

test('old card links follow repeated moves after purge without redirecting mutations', async ({ page, peer }) => {
	const boards = []
	try {
		const stacks = []
		for (let i = 0; i < 3; i++) {
			const board = await api.post('/boards', { title: `Alias board ${i}` })
			boards.push(board.id)
			stacks.push((await api.post('/stacks', { boardId: board.id, title: 'Work' })).id)
		}
		const source = await api.post('/cards', { stackId: stacks[0], title: 'Alias target' })
		const first = await api.post(`/cards/${source.id}/move-to-board`, { targetStackId: stacks[1] })
		expect(await api.get(`/card-navigation/${source.id}`)).toEqual({ cardId: first.id, boardId: boards[1] })

		await api.post(`/cards/${source.id}/restore`)
		expect(await api.get(`/card-navigation/${source.id}`)).toEqual({ cardId: source.id, boardId: boards[0] })
		await api.delete(`/cards/${source.id}`)
		expect((await api.raw('GET', `/card-navigation/${source.id}`)).status).toBe(404)
		await api.delete(`/cards/${source.id}/purge`)
		expect((await api.raw('GET', `/card-navigation/${source.id}`)).status).toBe(404)

		const chainSource = await api.post('/cards', { stackId: stacks[0], title: 'Alias chain target' })
		const chainFirst = await api.post(`/cards/${chainSource.id}/move-to-board`, { targetStackId: stacks[1] })
		const latest = await api.post(`/cards/${chainFirst.id}/move-to-board`, { targetStackId: stacks[2] })
		await api.delete(`/cards/${chainSource.id}/purge`)
		await api.delete(`/cards/${chainFirst.id}/purge`)
		expect(await api.get(`/card-navigation/${chainSource.id}`)).toEqual({ cardId: latest.id, boardId: boards[2] })
		expect((await api.raw('PATCH', `/cards/${chainSource.id}`, { title: 'incorrect' })).status).toBe(404)
		expect((await peer.api.raw('GET', `/card-navigation/${chainSource.id}`)).status).toBe(404)
		await api.post(`/boards/${boards[2]}/acl`, { participant: peer.user, participantType: 'user', permission: 1 })
		expect(await peer.api.get(`/card-navigation/${chainSource.id}`)).toEqual({ cardId: latest.id, boardId: boards[2] })

		const branched = await api.post('/cards', { stackId: stacks[0], title: 'Restored branch' })
		const intermediate = await api.post(`/cards/${branched.id}/move-to-board`, { targetStackId: stacks[1] })
		await api.post(`/cards/${intermediate.id}/move-to-board`, { targetStackId: stacks[2] })
		await api.post(`/cards/${intermediate.id}/restore`)
		expect(await api.get(`/card-navigation/${branched.id}`)).toEqual({ cardId: intermediate.id, boardId: boards[1] })
		await api.delete(`/cards/${intermediate.id}`)
		expect((await api.raw('GET', `/card-navigation/${branched.id}`)).status).toBe(404)
		await api.delete(`/cards/${intermediate.id}/purge`)
		expect((await api.raw('GET', `/card-navigation/${branched.id}`)).status).toBe(404)

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso/#/board/${boards[0]}/card/${chainSource.id}`)
		await expect(page).toHaveURL(new RegExp(`/board/${boards[2]}/card/${latest.id}$`))
		await expect(page.locator('.card-modal')).toBeVisible()
		await page.goto(`${BASE}/index.php/apps/kanso/card/${chainSource.id}`)
		await expect(page.locator('.card-modal')).toBeVisible()
		await expect(page.locator('.card-modal')).toContainText('Alias chain target')
	} finally {
		for (const boardId of boards) await api.delete(`/boards/${boardId}`).catch(() => {})
	}
})
