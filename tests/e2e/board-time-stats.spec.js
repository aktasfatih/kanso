// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, me, ncLogin, BASE } from './helpers.js'

test.describe('Board time analytics', () => {
	const state = { boardId: 0, otherBoardId: 0, peerUser: '', day: '', secondsByDay: {} }

	function record(entry) {
		const day = new Date(entry.createdAt * 1000).toISOString().slice(0, 10)
		state.secondsByDay[day] = (state.secondsByDay[day] ?? 0) + entry.seconds
	}

	test.beforeAll(async ({ peer }) => {
		const board = await api.post('/boards', { title: `Time analytics ${Date.now()}` })
		state.boardId = board.id
		state.peerUser = peer.user
		await api.patch(`/boards/${board.id}`, { allowAiActors: true })
		await api.post(`/boards/${board.id}/acl`, { participant: peer.user, participantType: 'user', permission: 3, role: 'external' })
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Tasks' })
		const publicCard = await api.post('/cards', { stackId: stack.id, title: 'Archived recorded work' })
		const entry = await api.post(`/cards/${publicCard.id}/time-entries`, { seconds: 3600 })
		state.day = new Date(entry.createdAt * 1000).toISOString().slice(0, 10)
		record(entry)
		record(await api.post(`/cards/${publicCard.id}/time-entries`, { seconds: 1800, actorName: 'Codex' }))
		record(await peer.api.post(`/cards/${publicCard.id}/time-entries`, { seconds: 900, actorName: 'Codex' }))
		await api.patch(`/cards/${publicCard.id}`, { archived: true })

		for (const [title, seconds, change] of [
			['Private work', 7200, 'private'],
			['Template work', 5000, 'template'],
			['Deleted work', 6000, 'deleted'],
		]) {
			const card = await api.post('/cards', { stackId: stack.id, title })
			const entry = await api.post(`/cards/${card.id}/time-entries`, { seconds })
			if (change === 'private') record(entry)
			if (change === 'private') await api.patch(`/cards/${card.id}`, { visibility: 'private' })
			if (change === 'template') await api.put(`/cards/${card.id}/template`, { isTemplate: true })
			if (change === 'deleted') await api.delete(`/cards/${card.id}`)
		}

		const otherBoard = await api.post('/boards', { title: `Other time analytics ${Date.now()}` })
		state.otherBoardId = otherBoard.id
		const otherStack = await api.post('/stacks', { boardId: otherBoard.id, title: 'Tasks' })
		const otherCard = await api.post('/cards', { stackId: otherStack.id, title: 'Other board work' })
		await api.post(`/cards/${otherCard.id}/time-entries`, { seconds: 10000 })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		if (state.otherBoardId) await api.delete(`/boards/${state.otherBoardId}`).catch(() => {})
	})

	test('keeps archived effort and separates owning users and AI actors', async ({ peer }) => {
		const stats = await api.get(`/boards/${state.boardId}/time-stats`)
		expect(stats.totalSeconds).toBe(13500)
		expect(stats.byUser).toEqual(expect.arrayContaining([
			expect.objectContaining({ uid: me, actorName: null, seconds: 10800 }),
			expect.objectContaining({ uid: me, actorName: 'Codex', seconds: 1800 }),
			expect.objectContaining({ uid: state.peerUser, actorName: 'Codex', seconds: 900 }),
		]))
		expect(stats.byUser).toHaveLength(3)

		const peerStats = await peer.api.get(`/boards/${state.boardId}/time-stats`)
		expect(peerStats.totalSeconds).toBe(6300)
		expect(peerStats.byUser.find((row) => row.uid === me && !row.actorName).seconds).toBe(3600)
		expect((await peer.api.raw('GET', `/boards/${state.otherBoardId}/time-stats`)).status).toBe(403)
	})

	test('uses inclusive dates and rejects malformed or unbounded ranges', async () => {
		const singleDay = await api.get(`/boards/${state.boardId}/time-stats?from=${state.day}&to=${state.day}`)
		expect(singleDay.totalSeconds).toBe(state.secondsByDay[state.day])
		const empty = await api.get(`/boards/${state.boardId}/time-stats?from=2000-01-01&to=2000-01-01`)
		expect(empty.totalSeconds).toBe(0)
		expect(empty.byUser).toEqual([])
		expect((await api.raw('GET', `/boards/${state.boardId}/time-stats?from=2026-02-30`)).status).toBe(400)
		expect((await api.raw('GET', `/boards/${state.boardId}/time-stats?from=2000-01-01&to=2026-01-01`)).status).toBe(400)
	})

	test('shows totals and refetches the selected date range', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/stats`)
		const panel = page.locator('[data-test=board-time-stats]')
		await expect(panel).toBeVisible()
		await expect(panel.locator('[data-test=board-time-total]')).toContainText('3h 45m')
		await expect(panel.locator('[data-test=board-time-user]')).toHaveCount(3)
		await expect(panel.locator('[data-test=board-time-user]', { hasText: 'Codex' })).toHaveCount(2)

		const response = page.waitForResponse((r) => {
			if (!r.url().includes(`/boards/${state.boardId}/time-stats`)) return false
			const params = new URL(r.url()).searchParams
			return params.get('from') === params.get('to')
		}, { timeout: 15_000 })
		await panel.locator('[data-test=board-time-range]').selectOption('today')
		const selected = await response
		expect(selected.ok()).toBe(true)
		const body = await selected.json()
		expect(body.from).toBe(body.to)
		expect(body.totalSeconds).toBe(state.secondsByDay[body.from] ?? 0)
		await expect(panel.locator('[data-test=board-time-total]')).toContainText(`${body.from} – ${body.to}`)
	})

	test('hides the panel and skips its query when time tracking is disabled', async ({ page }) => {
		await api.patch(`/boards/${state.boardId}`, { cardFeatures: { timeTracking: false } })
		try {
			let timeReads = 0
			page.on('request', (request) => {
				if (request.url().includes(`/boards/${state.boardId}/time-stats`)) timeReads++
			})
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/stats`)
			await expect(page.locator('.board-stats__body')).toBeVisible()
			await expect(page.locator('[data-test=board-time-stats]')).toHaveCount(0)
			expect(timeReads).toBe(0)
			const disabled = await api.get(`/boards/${state.boardId}/time-stats`)
			expect(disabled).toMatchObject({ enabled: false, totalSeconds: 0, byUser: [] })
		} finally {
			await api.patch(`/boards/${state.boardId}`, { cardFeatures: { timeTracking: true } })
		}
		expect((await api.get(`/boards/${state.boardId}/time-stats`)).totalSeconds).toBe(13500)
	})
})
