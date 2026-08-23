// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Project analytics e2e (#3568): a project spanning two boards, each with a
// card (one with a priority set). The project header analytics button opens the
// cross-board CSS-bar stats page, which shows the priority distribution and the
// at-a-glance / flow panels — WITHOUT the board-specific "Cards by stack" panel.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

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
		await expect(page.locator('.project-view')).toBeVisible({ timeout: 10_000 })

		await page.locator('.project-view__analytics-btn').click()

		await expect(page).toHaveURL(new RegExp(`#/projects/${state.projectId}/stats`))
		const view = page.locator('.board-stats__body')
		await expect(view).toBeVisible({ timeout: 10_000 })

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
})
