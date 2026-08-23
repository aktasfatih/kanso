// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Swimlanes (#3406)', () => {
	const state = { boardId: 0, labelId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Swimlanes ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })

		// A colored board label to group by.
		const label = await api.post('/labels', { boardId: board.id, title: 'Frontend', color: '3498db' })
		state.labelId = label.id

		// One card WITH the label, one WITHOUT (→ the "No label" lane).
		const labelled = await api.post('/cards', { stackId: stack.id, title: 'Labelled Card' })
		await api.put(`/cards/${labelled.id}/labels/${state.labelId}`)
		await api.post('/cards', { stackId: stack.id, title: 'Plain Card' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('Group by label creates a lane per label + a No-label lane; toggling back restores the flat board', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		// Flat board first: the classic stacks wrap is visible, no lanes yet.
		await expect(page.locator('.board-view__stacks-wrap')).toBeVisible()
		await expect(page.locator('.swimlane')).toHaveCount(0)

		// Swimlanes now live under Display → Group by. Open it and pick "Label".
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByRole('menuitemradio', { name: 'Label', exact: true }).click()
		await page.keyboard.press('Escape')

		// Lanes appear: the "Frontend" label lane and the trailing "No label" lane.
		await expect(page.locator('.swimlane')).toHaveCount(2)
		const laneTitles = () => page.locator('.swimlane__title').allTextContents()
		await expect.poll(async () => (await laneTitles()).map((s) => s.trim()))
			.toEqual(['Frontend', 'No label'])

		// The labelled card sits in the Frontend lane; the plain card in No-label.
		const frontendLane = page.locator('.swimlane', { has: page.locator('.swimlane__title', { hasText: /^Frontend$/ }) })
		await expect(frontendLane.locator('.card-tile__title', { hasText: 'Labelled Card' })).toHaveCount(1)
		await expect(frontendLane.locator('.card-tile__title', { hasText: 'Plain Card' })).toHaveCount(0)

		const noLabelLane = page.locator('.swimlane', { has: page.locator('.swimlane__title', { hasText: /^No label$/ }) })
		await expect(noLabelLane.locator('.card-tile__title', { hasText: 'Plain Card' })).toHaveCount(1)

		// Persisted per board: a reload keeps the grouping.
		await page.reload()
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await expect(page.locator('.swimlane')).toHaveCount(2)

		// Toggle back to "None" (Group by) → flat board restored, lanes gone.
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByRole('menuitemradio', { name: 'None', exact: true }).click()
		await page.keyboard.press('Escape')
		await expect(page.locator('.swimlane')).toHaveCount(0)
		await expect(page.locator('.board-view__stacks-wrap')).toBeVisible()
		await expect(page.locator('.card-tile__title', { hasText: 'Labelled Card' })).toHaveCount(1)
		await expect(page.locator('.card-tile__title', { hasText: 'Plain Card' })).toHaveCount(1)
	})
})
