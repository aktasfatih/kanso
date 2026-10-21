// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Project plan — progress, forecast and health', () => {
	const state = { boardId: 0, projectId: 0, blocker: 0, blocked: 0 }

	test.beforeAll(async () => {
		const ts = Date.now()
		const board = await api.post('/boards', { title: `Plan Board ${ts}` })
		state.boardId = board.id
		await api.patch(`/boards/${board.id}`, { estimateScale: 'tshirt' })
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })

		const project = await api.post('/projects', { title: `Plan Project ${ts}`, targetDate: '2099-12-31' })
		state.projectId = project.id

		const specs = [
			{ title: 'Plan done one', estimate: 'S', done: true },
			{ title: 'Plan done two', estimate: 'M', done: true },
			{ title: 'Plan blocker', estimate: 'L' },
			{ title: 'Plan blocked', estimate: null },
		]
		for (const spec of specs) {
			const card = await api.post('/cards', { stackId: stack.id, title: spec.title })
			const patch = {}
			if (spec.estimate) patch.estimate = spec.estimate
			if (spec.done) patch.done = true
			if (Object.keys(patch).length) await api.patch(`/cards/${card.id}`, patch)
			await api.put(`/projects/${project.id}/cards/${card.id}`)
			if (spec.title === 'Plan blocker') state.blocker = card.id
			if (spec.title === 'Plan blocked') state.blocked = card.id
		}
		await api.post(`/cards/${state.blocker}/relations`, { otherCardId: state.blocked, kind: 'blocks' })
	})

	test.afterAll(async () => {
		if (state.projectId) await api.delete(`/projects/${state.projectId}`).catch(() => {})
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('the plan endpoint forecasts in normalized points', async () => {
		const plan = await api.get(`/projects/${state.projectId}/plan`)

		expect(plan.total).toBe(4)
		expect(plan.done).toBe(2)
		expect(plan.blocked).toBe(1)
		expect(plan.basis).toBe('points')
		expect(plan.points).toMatchObject({ total: 13, done: 5, unestimatedCount: 1, assumedPerCard: 3 })
		expect(plan.forecast).not.toBeNull()
		expect(plan.status).toBe('on_track')
	})

	test('the health panel shows status, groups cards and reacts to a new target date', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${state.projectId}`)

		const health = page.locator('[data-test=project-health]')
		await expect(health).toBeVisible()
		await expect(page.locator('[data-test=project-status]')).toHaveText('On track')
		await expect(page.locator('[data-test=project-progress]')).toContainText('2 of 4 cards')
		await expect(page.locator('[data-test=project-chip-blocked]')).toContainText('1 blocked')
		await expect(page.locator('[data-test=project-burnup]')).toBeVisible()

		await expect(page.locator('[data-test=project-group-blocked]')).toContainText('Plan blocked')
		await expect(page.locator('[data-test=project-group-not-started]')).toContainText('Plan blocker')
		await expect(page.locator('[data-test=project-group-done]')).toContainText('2')

		const target = page.locator('[data-test=project-target-date]')
		await target.fill('2020-01-01')
		await target.dispatchEvent('change')
		await expect(page.locator('[data-test=project-status]')).toHaveText('Late')

		await page.reload()
		await expect(page.locator('[data-test=project-target-date]')).toHaveValue('2020-01-01')
	})

	test('a card opens over the project, and closing it returns with fresh numbers', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${state.projectId}`)
		await expect(page.locator('[data-test=project-chip-blocked]')).toBeVisible()

		await page.locator('.project-view__row', { hasText: 'Plan blocker' }).click()
		const modal = page.locator('.card-modal-modal')
		await expect(modal).toBeVisible()
		await expect(page).toHaveURL(new RegExp(`#/projects/${state.projectId}$`))

		await api.patch(`/cards/${state.blocker}`, { done: true })
		await page.keyboard.press('Escape')
		await expect(modal).toHaveCount(0)
		await expect(page).toHaveURL(new RegExp(`#/projects/${state.projectId}$`))
		await expect(page.locator('[data-test=project-chip-blocked]')).toHaveCount(0)
		await expect(page.locator('[data-test=project-group-done]')).toContainText('3')
	})

	test('pinning a project lists it in the sidebar and unpinning removes it', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects`)

		const tile = page.locator('.projects-view__row', { hasText: 'Plan Project' })
		const navItem = page.locator('[data-test=nav-pinned-project]', { hasText: 'Plan Project' })
		await expect(navItem).toHaveCount(0)

		await tile.locator('[data-test=project-tile-pin]').click()
		await expect(navItem).toBeVisible()
		expect((await api.get('/projects')).find((p) => p.id === state.projectId).pinned).toBe(true)

		await navItem.locator('a').first().click()
		await expect(page).toHaveURL(new RegExp(`#/projects/${state.projectId}$`))

		await api.delete(`/projects/${state.projectId}/pin`)
		await page.reload()
		await expect(navItem).toHaveCount(0)
	})

	test('a long description is collapsed until expanded', async ({ page }) => {
		const long = Array.from({ length: 30 }, (_, i) => `Paragraph ${i + 1} of the plan.`).join('\n\n')
		await api.patch(`/projects/${state.projectId}`, { description: long })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${state.projectId}`)

		const description = page.locator('[data-test=project-description]')
		const toggle = page.locator('[data-test=project-description-toggle]')
		await expect(toggle).toHaveText(/Show more/)
		const collapsed = (await description.boundingBox()).height
		await toggle.click()
		await expect(toggle).toHaveText(/Show less/)
		expect((await description.boundingBox()).height).toBeGreaterThan(collapsed)
	})

	test('the timeline tab renders the project cards on the board timeline', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${state.projectId}`)

		await page.locator('[data-test=project-mode-timeline]').click()
		await expect(page.locator('.project-view__timeline')).toBeVisible()
		await expect(page.locator('.project-view__timeline')).toContainText('unscheduled')
	})
})
