// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, me, ncLogin, BASE } from './helpers.js'

async function fixture() {
	const board = await api.post('/boards', { title: `Parent archive ${Date.now()}` })
	try {
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Tasks' })
		const parent = await api.post('/cards', { stackId: stack.id, title: 'Archive progress parent' })
		const child = await api.post('/cards', { stackId: stack.id, title: 'Archived sub-card' })
		await api.put(`/cards/${child.id}/parent`, { parentCardId: parent.id })
		return { board, stack, parent, child, cleanup: () => api.delete(`/boards/${board.id}`) }
	} catch (error) {
		await api.delete(`/boards/${board.id}`)
		throw error
	}
}

for (const mode of ['manual', 'rule']) {
	for (const gate of ['none', 'default-off', 'sibling', 'checklist', 'review']) {
		test(`${mode} archive respects parent completion with ${gate} gate`, async () => {
			const f = await fixture()
			try {
				await api.patch(`/cards/${f.child.id}`, { done: true })
				expect(Number((await api.get(`/cards/${f.parent.id}`)).doneAt)).toBe(0)
				if (gate === 'sibling') {
					const sibling = await api.post('/cards', { stackId: f.stack.id, title: 'Unresolved sibling' })
					await api.put(`/cards/${sibling.id}/parent`, { parentCardId: f.parent.id })
				}
				if (gate === 'checklist') await api.post(`/cards/${f.parent.id}/checklist`, { title: 'Required work' })
				if (gate === 'review') await api.put(`/cards/${f.parent.id}/reviews/${me}`)
				if (gate !== 'default-off') await api.patch(`/boards/${f.board.id}`, { autoCompleteParent: true })
				if (mode === 'rule') {
					const rule = await api.post(`/boards/${f.board.id}/archive-rules`, { stackId: f.stack.id, thresholdSeconds: 0 })
					expect(await api.post(`/archive-rules/${rule.id}/archive-now`)).toEqual({ archived: 1 })
				} else {
					await api.patch(`/cards/${f.child.id}`, { archived: true })
				}
				expect((await api.get(`/cards/${f.child.id}`)).archived).toBe(true)
				expect(Number((await api.get(`/cards/${f.parent.id}`)).doneAt) > 0).toBe(gate === 'none')
			} finally {
				await f.cleanup()
			}
		})
	}
}

for (const allArchived of [false, true]) {
	test(`archived sub-cards show resolved progress and restoring never reopens the parent (all archived ${allArchived})`, async ({ page }) => {
		const f = await fixture()
		try {
			await api.patch(`/boards/${f.board.id}`, { autoCompleteParent: true })
			const sibling = await api.post('/cards', { stackId: f.stack.id, title: 'Other resolved sub-card' })
			await api.put(`/cards/${sibling.id}/parent`, { parentCardId: f.parent.id })
			await api.patch(`/cards/${sibling.id}`, allArchived ? { archived: true } : { done: true })
			await api.patch(`/cards/${f.child.id}`, { duedate: '2020-01-01T00:00:00+00:00', archived: true })
			const parent = await api.get(`/cards/${f.parent.id}`)
			expect(Number(parent.doneAt)).toBeGreaterThan(0)
			const board = await api.get(`/boards/${f.board.id}`)
			expect(board.cards.find((card) => card.id === f.parent.id).childProgress).toEqual({ total: 2, done: 2 })
			const views = await api.get('/views/cards')
			expect(views.cards.find((card) => card.id === f.parent.id).childProgress).toEqual({ total: 2, done: 2 })
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${f.board.id}/card/${f.parent.id}`)
			await expect(page.locator('.card-modal__title')).toHaveText(f.parent.title)
			await expect(page.locator('.card-modal__section-count[title="Done or archived sub-cards"]')).toHaveText('2 / 2')
			const row = page.locator('.card-modal__child').filter({ has: page.getByRole('button', { name: f.child.title, exact: true }) })
			await expect(row.locator('.card-modal__child-archived')).toHaveText('Archived')
			await expect(row.locator('.card-modal__child-date--overdue')).toHaveCount(0)
			await page.setViewportSize({ width: 390, height: 844 })
			await expect(row.locator('.card-modal__child-archived')).toBeVisible()
			await page.setViewportSize({ width: 1280, height: 900 })
			const tile = page.locator(`.card-tile[data-card-id="${f.parent.id}"]`)
			await expect(tile.locator('.card-tile__children')).toHaveText('2/2')
			await row.getByRole('button', { name: f.child.title, exact: true }).click()
			await expect(page.locator('.card-modal__title')).toHaveText(f.child.title)
			await page.locator('.card-modal__actions-menu button').first().click()
			const checkbox = page.locator('[data-test="card-archived-toggle"] input[type="checkbox"]')
			await expect(checkbox).toBeChecked()
			const restored = page.waitForResponse((response) => response.url().endsWith(`/cards/${f.child.id}`) && response.request().method() === 'PATCH' && response.ok())
			await page.locator('[data-test="card-archived-toggle"] label').click()
			await restored
			await expect(checkbox).not.toBeChecked()
			expect(Number((await api.get(`/cards/${f.parent.id}`)).doneAt)).toBe(Number(parent.doneAt))
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${f.board.id}/card/${f.parent.id}`)
			await expect(page.locator('.card-modal__section-count[title="Done or archived sub-cards"]')).toHaveText('1 / 2')
			await expect(page.locator('.card-tile').filter({ hasText: f.parent.title }).locator('.card-tile__children')).toHaveText('1/2')
			await expect(page.locator('.card-modal__child').filter({ hasText: f.child.title }).locator('.card-modal__child-archived')).toHaveCount(0)
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${f.board.id}`)
			await page.locator('.board-view__display-menu button').first().click()
			await page.getByRole('menuitemradio', { name: 'List', exact: true }).click()
			await expect(page.getByRole('img', { name: 'Sub-card progress 1 of 2 (done or archived)', exact: true })).toHaveText('1/2')
		} finally {
			await f.cleanup()
		}
	})
}
