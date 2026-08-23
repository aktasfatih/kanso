// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// #3548 — quick multi-add: paste/enter multiple lines → one card per non-blank
// line, in order. Reuses the single create path (no bulk endpoint).
test.describe('Quick multi-add', () => {
	const state = { boardId: 0, stackId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Multi-Add E2E' })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To Do' })).id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	// Persisted server-side order (source of truth), top-to-bottom by sort key.
	async function stackOrder() {
		const board = await api.get(`/boards/${state.boardId}`)
		return board.cards
			.filter((c) => c.stackId === state.stackId && !c.archived)
			.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0))
			.map((c) => c.title)
	}

	// Simulate a real multi-line clipboard paste into the composer input. A text
	// <input> collapses typed newlines, so the composer intercepts `paste`.
	async function pasteIntoComposer(composer, text) {
		await composer.focus()
		await composer.evaluate((el, value) => {
			const dt = new DataTransfer()
			dt.setData('text', value)
			el.dispatchEvent(new ClipboardEvent('paste', {
				clipboardData: dt,
				bubbles: true,
				cancelable: true,
			}))
		}, text)
	}

	test('pasting 3 lines creates 3 cards in order; a single line makes 1', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })

		const composer = page.locator('.stack-column').first().locator('.card-composer__input')
		await expect(composer).toBeVisible({ timeout: 10_000 })

		// Multi-line paste (with a blank line + trailing whitespace to prove the
		// trim/skip-blank behaviour) → exactly 3 cards, in submitted order.
		await pasteIntoComposer(composer, 'Alpha\n  Bravo  \n\nCharlie\n')

		await expect.poll(() => stackOrder(), { timeout: 10_000 }).toEqual([
			'Alpha', 'Bravo', 'Charlie',
		])

		// Single-line add via Enter still creates exactly one card, appended in order.
		await composer.fill('Delta')
		await composer.press('Enter')

		await expect.poll(() => stackOrder(), { timeout: 10_000 }).toEqual([
			'Alpha', 'Bravo', 'Charlie', 'Delta',
		])
	})
})
