// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, me } from './helpers.js'

test('the project picker accepts direct typing and preserves membership while filtering', async ({ page }) => {
	const board = await api.post('/boards', { title: 'Project picker search' })
	const projects = []
	try {
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Todo' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Search project membership' })
		const title = `Project picker ${Date.now()}`
		projects.push(await api.post('/projects', { title: `${title} Alpha` }))
		projects.push(await api.post('/projects', { title: `${title} BETA` }))
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`)
		const pill = page.locator('[data-pill=project]')
		await pill.click()
		const search = page.getByRole('searchbox', { name: 'Search projects', exact: true })
		await expect(search).toBeFocused()
		await page.keyboard.type(`${title.toLowerCase()} bEtA`)
		const popover = page.locator('.card-modal__popover', { has: search })
		await expect(popover.locator('.card-modal__label-toggle')).toHaveCount(1)
		const row = popover.getByRole('button', { name: `${title} BETA`, exact: true })
		await search.press('Tab')
		await expect(row).toBeFocused()
		await row.press('Enter')
		await expect(row).toHaveAttribute('aria-pressed', 'true')
		await search.fill('no project has this name')
		await expect(popover.getByText('No projects match.')).toBeVisible()
		await search.fill('')
		await expect(row).toHaveAttribute('aria-pressed', 'true')
		expect((await api.get(`/cards/${card.id}`)).projectIds).toContain(projects[1].id)
		await pill.click()
		await pill.click()
		await expect(search).toHaveValue('')
		await expect(search).toBeFocused()
	} finally {
		for (const project of projects) await api.delete(`/projects/${project.id}`).catch(() => {})
		await api.delete(`/boards/${board.id}`).catch(() => {})
	}
})

test('card timer buttons record time and reconcile the running state', async ({ page }) => {
	const board = await api.post('/boards', { title: 'Direct timer buttons' })
	try {
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Todo' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Start and stop this timer' })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`)
		const toggle = page.locator('[data-test=card-timer-toggle]')
		await expect(toggle).toHaveText(/Start timer/)
		await toggle.click()
		await expect(toggle).toHaveText(/Stop timer/)
		await expect(page.locator('.card-modal__timer-running-row')).toBeVisible()
		const running = (await api.get(`/cards/${card.id}`)).runningTimer
		expect(running.startedBy).toBe(me)
		await expect.poll(() => Math.floor(Date.now() / 1000) - running.startedAt).toBeGreaterThan(0)
		await toggle.click()
		await expect(toggle).toHaveText(/Start timer/)
		await expect(page.locator('.card-modal__timer-running-row')).toHaveCount(0)
		const entries = await api.get(`/cards/${card.id}/time-entries`)
		expect(entries).toHaveLength(1)
		expect(entries[0].seconds).toBeGreaterThan(0)
		expect((await api.get(`/cards/${card.id}`)).runningTimer).toBeNull()
	} finally {
		await api.delete(`/boards/${board.id}`).catch(() => {})
	}
})

test('AI names and robot icons use readable foreground colors in light and dark themes', async ({ page }) => {
	const board = await api.post('/boards', { title: 'AI attribution contrast' })
	try {
		await api.patch(`/boards/${board.id}`, { allowAiActors: true })
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Todo' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'AI attributed card' })
		const comment = await api.post(`/cards/${card.id}/comments`, { body: 'AI thread', actorName: 'Codex' })
		await api.post(`/cards/${card.id}/comments`, { body: 'AI reply', parentCommentId: comment.id, actorName: 'Claude Code' })
		await api.put(`/cards/${card.id}/assignees/${me}`, { actorName: 'Codex' })
		await api.put(`/cards/${card.id}/reviews/${me}`, { actorName: 'Claude Code' })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`)
		const names = page.locator('.card-modal__comment-author--ai, .card-modal__review-name--ai, .card-modal__assignee-name--ai')
		await expect(names).toHaveCount(4)
		for (const [foreground, background] of [['#eeeeee', '#181818'], ['#181818', '#ffffff']]) {
			await page.locator('.card-modal').evaluate((root, colors) => {
				root.style.setProperty('--color-main-text', colors.foreground)
				root.style.setProperty('--color-main-background', colors.background)
				root.style.setProperty('--color-primary', colors.background)
			}, { foreground, background })
			const colors = await names.evaluateAll((elements) => elements.map((element) => ({
				text: getComputedStyle(element).color,
				icon: getComputedStyle(element.querySelector('.card-modal__ai-actor-icon')).color,
				foreground: getComputedStyle(element.closest('.card-modal')).color,
			})))
			for (const color of colors) {
				expect(color.text).toBe(color.foreground)
				expect(color.icon).toBe(color.foreground)
			}
		}
		await page.goto(`${BASE}/index.php/apps/kanso#/reviews`)
		const review = page.locator('.review-row', { hasText: 'AI attributed card' }).first()
		await expect(review.locator('.review-row__requested-by--ai')).toBeVisible()
		for (const [foreground, background] of [['#eeeeee', '#181818'], ['#181818', '#ffffff']]) {
			const colors = await review.evaluate((root, colors) => {
				root.style.setProperty('--color-main-text', colors.foreground)
				root.style.setProperty('--color-primary', colors.background)
				root.style.color = 'var(--color-main-text)'
				return {
					foreground: getComputedStyle(root).color,
					text: getComputedStyle(root.querySelector('.review-row__requested-by--ai')).color,
					icon: getComputedStyle(root.querySelector('.review-row__ai-actor-icon')).color,
				}
			}, { foreground, background })
			expect(colors.text).toBe(colors.foreground)
			expect(colors.icon).toBe(colors.foreground)
		}
	} finally {
		await api.delete(`/boards/${board.id}`).catch(() => {})
	}
})
