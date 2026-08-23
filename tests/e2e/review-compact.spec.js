// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, me } from './helpers.js'

// #3493 — with 3+ reviews the attribute-bar chips must stay compact (one row).
test.describe('Compact multi-review attribute bar', () => {
	const state = { boardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Review-Compact E2E' })
		state.boardId = board.id
		const stackId = (await api.post('/stacks', { boardId: board.id, title: 'Do' })).id
		const cardId = (await api.post('/cards', { stackId, title: 'Review-heavy card' })).id
		// Three distinct reviews from the current user (one per type) — same reviewer,
		// three types is a valid multi-review shape and avoids provisioning extra users.
		for (const name of ['QA', 'Security', 'Design']) {
			const typeId = (await api.post('/review-types', { boardId: board.id, title: name, color: '31CC7C' })).id
			await api.put(`/cards/${cardId}/reviews/${me}`, { reviewTypeId: typeId })
		}
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${cardId}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('3+ reviews render as compact chips on a single row', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const pills = page.locator('.card-modal__review-pill')
		await expect(pills).toHaveCount(3, { timeout: 8000 })

		// Compact mode engaged: every pill carries the modifier and the reviewer
		// name / state text are hidden (moved to the avatar tooltip + icon).
		await expect(page.locator('.card-modal__review-pill--compact')).toHaveCount(3)
		await expect(page.locator('.card-modal__review-pill .card-modal__review-name').first()).toBeHidden()

		// The review row stays a single line: its height is about one pill tall,
		// not the ~2-3 rows the full pills would wrap into.
		const box = await page.locator('.card-modal__attr-right').boundingBox()
		expect(box).not.toBeNull()
		expect(box.height).toBeLessThan(44)
	})
})
