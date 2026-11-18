// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Project analytics e2e (#3568): a project spanning two boards, each with a
// card (one with a priority set). The project header analytics button opens the
// cross-board CSS-bar stats page, which shows the priority distribution and the
// at-a-glance / flow panels — WITHOUT the board-specific "Cards by stack" panel.

import { test, expect, api, ncLogin, BASE, me } from './helpers.js'

test.describe('Project analytics — cross-board', () => {
	const state = { boardA: 0, boardB: 0, projectId: 0 }

	test.beforeAll(async () => {
		const ts = Date.now()
		const boardA = await api.post('/boards', { title: `PStats Board A ${ts}` })
		const boardB = await api.post('/boards', { title: `PStats Board B ${ts}` })
		state.boardA = boardA.id
		state.boardB = boardB.id
		const stackA = await api.post('/stacks', { boardId: boardA.id, title: 'To Do' })
		const stackB = await api.post('/stacks', { boardId: boardB.id, title: 'Doing' })
		const cardA = await api.post('/cards', { stackId: stackA.id, title: 'Alpha stats task' })
		const cardB = await api.post('/cards', { stackId: stackB.id, title: 'Beta stats task' })
		// Give one card a critical priority so the "Cards by priority" bar renders.
		await api.patch(`/cards/${cardA.id}`, { priority: 4 })

		const project = await api.post('/projects', { title: `Stats Initiative ${ts}` })
		state.projectId = project.id
		await api.put(`/projects/${project.id}/cards/${cardA.id}`)
		await api.put(`/projects/${project.id}/cards/${cardB.id}`)
	})

	test.afterAll(async () => {
		if (state.projectId) await api.delete(`/projects/${state.projectId}`).catch(() => {})
		if (state.boardA) await api.delete(`/boards/${state.boardA}`).catch(() => {})
		if (state.boardB) await api.delete(`/boards/${state.boardB}`).catch(() => {})
	})

	test('the project analytics button opens the cross-board stats view', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${state.projectId}`)
		await expect(page.locator('.project-view')).toBeVisible()

		await page.locator('.project-view__analytics-btn').click()

		await expect(page).toHaveURL(new RegExp(`#/projects/${state.projectId}/stats`))
		const view = page.locator('.board-stats__body')
		await expect(view).toBeVisible()

		// At-a-glance counters render (project-specific "Cards in project" + Overdue).
		await expect(page.getByText('Cards in project')).toBeVisible()
		await expect(page.getByText('Overdue', { exact: true })).toBeVisible()

		// The cross-board priority distribution renders with a bar.
		await expect(page.getByText('Cards by priority')).toBeVisible()
		expect(await page.locator('.board-stats__bar-row').count()).toBeGreaterThanOrEqual(1)

		// The board-specific "Cards by stack" panel is intentionally ABSENT — a
		// per-stack roll-up is meaningless across boards.
		await expect(page.getByText('Cards by stack')).toHaveCount(0)

		// Flow panels render (present even with no completions).
		await expect(page.getByText('Velocity — completed per week')).toBeVisible()
		await expect(page.getByText(/Cycle time — creation to done \(28d\)/)).toBeVisible()
	})

	test('the stats API aggregates over the project card set and omits board-only panels', async () => {
		const stats = await api.get(`/projects/${state.projectId}/stats`)
		// Two member cards across two boards.
		expect(stats.cardCount).toBe(2)
		const total = stats.byPriority.reduce((n, r) => n + r.count, 0)
		expect(total).toBe(2)
		// A critical-priority card contributes.
		expect(stats.byPriority.some((r) => r.priority === 4 && r.count >= 1)).toBe(true)
		// Board-specific aggregates are not part of the project DTO.
		expect(stats.byStack).toBeUndefined()
		expect(stats.estimateByStack).toBeUndefined()
		// Points are never summed across mixed scales.
		expect(stats.velocity.pointsPerWeek).toBeNull()
	})

	test('archiving and restoring cards preserves project progress and analytics', async ({ page }) => {
		let boardId = 0
		let projectId = 0
		try {
			const board = await api.post('/boards', { title: `Archive progress ${Date.now()}` })
			boardId = board.id
			await api.patch(`/boards/${boardId}`, { estimateScale: 'fibonacci' })
			const stack = await api.post('/stacks', { boardId, title: 'Tasks' })
			const completed = await api.post('/cards', { stackId: stack.id, title: 'Completed project work' })
			const unfinished = await api.post('/cards', { stackId: stack.id, title: 'Unfinished project work' })
			await api.patch(`/cards/${completed.id}`, { estimate: '3', priority: 4 })
			await api.patch(`/cards/${unfinished.id}`, { estimate: '5', duedate: '2020-01-01T00:00:00Z' })
			await api.put(`/cards/${completed.id}/assignees/${me}`)
			const label = await api.post('/labels', { boardId, title: 'Delivered', color: '0082c9' })
			await api.put(`/cards/${completed.id}/labels/${label.id}`)
			const item = await api.post(`/cards/${completed.id}/checklist`, { title: 'Verified' })
			await api.patch(`/checklist/${item.id}`, { done: true })
			await api.patch(`/cards/${completed.id}`, { done: true })
			const project = await api.post('/projects', { title: `Archive progress project ${Date.now()}` })
			projectId = project.id
			for (const card of [completed, unfinished]) await api.put(`/projects/${projectId}/cards/${card.id}`)

			const beforePlan = await api.get(`/projects/${projectId}/plan`)
			const beforeStats = await api.get(`/projects/${projectId}/stats`)
			expect(beforePlan).toMatchObject({ total: 2, done: 1, open: 1, points: { total: 8, done: 3 } })
			expect(beforeStats).toMatchObject({ cardCount: 2, overdue: 1, checklist: { total: 1, done: 1 } })
			expect(beforeStats.byAssignee).toContainEqual({ uid: me, count: 1 })
			expect(beforeStats.byLabel).toContainEqual({ boardId, labelId: label.id, count: 1 })
			expect(beforeStats.throughput.reduce((sum, day) => sum + day.count, 0)).toBe(1)

			for (const card of [completed, unfinished]) await api.patch(`/cards/${card.id}`, { archived: true })
			const cards = await api.get(`/projects/${projectId}/cards`)
			expect(cards.map((card) => card.id).sort((a, b) => a - b)).toEqual([completed.id, unfinished.id])
			expect(cards.every((card) => card.archived === true)).toBe(true)
			const afterPlan = await api.get(`/projects/${projectId}/plan`)
			for (const key of ['total', 'done', 'open', 'overdue', 'points', 'burnup', 'throughput']) {
				expect(afterPlan[key]).toEqual(beforePlan[key])
			}
			expect(await api.get(`/projects/${projectId}/stats`)).toEqual(beforeStats)

			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/projects/${projectId}`)
			await expect(page.locator('[data-test=project-progress]')).toHaveText('1 of 2 cards')
			await page.locator('[data-test=project-group-done] > summary').click()
			const completedRow = page.locator('.project-view__row', { hasText: completed.title })
			await expect(completedRow).toBeVisible()
			await expect(completedRow.locator('.project-view__badge', { hasText: 'Archived' })).toBeVisible()

			for (const card of [completed, unfinished]) await api.patch(`/cards/${card.id}`, { archived: false })
			expect((await api.get(`/projects/${projectId}/cards`)).every((card) => card.archived === false)).toBe(true)
			expect(await api.get(`/projects/${projectId}/stats`)).toEqual(beforeStats)
		} finally {
			if (projectId) await api.delete(`/projects/${projectId}`).catch(() => {})
			if (boardId) await api.delete(`/boards/${boardId}`).catch(() => {})
		}
	})

	test('templates and deleted cards stay out of the project card set and its stats', async () => {
		const board = await api.get(`/boards/${state.boardA}`)
		const stackId = board.stacks[0].id
		const template = await api.post('/cards', { stackId, title: 'Template stats task' })
		const deleted = await api.post('/cards', { stackId, title: 'Deleted stats task' })
		await api.put(`/projects/${state.projectId}/cards/${template.id}`)
		await api.put(`/projects/${state.projectId}/cards/${deleted.id}`)
		expect((await api.get(`/projects/${state.projectId}/cards`)).length).toBe(4)
		await api.put(`/cards/${template.id}/template`, { isTemplate: true })
		await api.delete(`/cards/${deleted.id}`)

		const cards = await api.get(`/projects/${state.projectId}/cards`)
		expect(cards.map((card) => card.id)).not.toContain(template.id)
		expect(cards.map((card) => card.id)).not.toContain(deleted.id)
		expect(cards).toHaveLength(2)
		expect((await api.get(`/projects/${state.projectId}/stats`)).cardCount).toBe(2)
	})
})
