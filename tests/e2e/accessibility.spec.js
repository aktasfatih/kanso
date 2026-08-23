// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// #3412 — one accessibility smoke over the board + open card modal. Uses
// Playwright's built-in accessibility snapshot (no extra dependency): asserts
// the board exposes a landmark/heading tree and the card modal opens as a
// focusable dialog with an accessible name and the aria-live region present.
test.describe('Accessibility smoke', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title: 'A11y Smoke E2E' })
		state.boardId = board.id
		state.stackId = (await api.send('POST', '/stacks', { boardId: board.id, title: 'To Do' })).id
		state.cardId = (await api.send('POST', '/cards', { stackId: state.stackId, title: 'Accessible card' })).id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${state.cardId}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('board + card modal expose an accessible tree with an aria-live region', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view', { timeout: 10_000 })

		// The single polite aria-live region for own-action announcements exists.
		await expect(page.locator('.board-view [aria-live="polite"]')).toHaveCount(1)

		// Playwright's native accessibility snapshot (aria tree) must be
		// non-trivial and surface the card by its accessible name.
		const boardSnapshot = await page.locator('.board-view').ariaSnapshot()
		expect(boardSnapshot).toBeTruthy()
		expect(boardSnapshot).toContain('Accessible card')

		// Open the card modal → it must be a dialog with an accessible name.
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		const dialog = page.getByRole('dialog').first()
		await expect(dialog).toBeVisible()
		// The modal mounts before the card detail fetch resolves; snapshotting
		// immediately races the content load (flaked on a cold CI runner). Wait
		// for the card's real title to render before taking the aria snapshot.
		await expect(dialog).toContainText('Accessible card', { timeout: 20_000 })
		const modalSnapshot = await dialog.ariaSnapshot()
		expect(modalSnapshot).toContain('Accessible card')
	})
})
