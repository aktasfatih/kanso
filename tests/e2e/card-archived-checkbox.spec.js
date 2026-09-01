// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10063 — the card's ⋯ menu used to offer a verb that flipped its own label
// ("Archive" / the opposite), so the only signal a card was archived was which
// action you were being offered. It is now ONE checkbox with a stable "Archived"
// label: it shows the state and changes it. This spec pins all three halves —
// unchecked on a live card, checked on an archived one, and the toggle in both
// directions actually archiving/unarchiving on the server.

import { test, expect, BASE, api, ncLogin } from './helpers.js'

test.describe('Archived is a checkbox in the card ⋯ menu (#10063)', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0 }

	const cardUrl = () => `${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.cardId}`

	// The checkbox lives in the collapsed ⋯ menu. NcActionCheckbox renders a
	// <label> wrapping a visually-hidden <input> plus the drawn check glyph, so
	// the input carries the state and the label is what a user actually clicks.
	const archivedBox = (page) => page.locator('[data-test="card-archived-toggle"] input[type="checkbox"]')
	const archivedLabel = (page) => page.locator('[data-test="card-archived-toggle"] label')

	async function openCardMenu(page) {
		await page.goto(cardUrl())
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })
		await page.locator('.card-modal__actions-menu button').first().click()
		await expect(archivedBox(page)).toBeAttached({ timeout: 8000 })
	}

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Archived checkbox ' + Date.now() })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'Doing' })).id
		state.cardId = (await api.post('/cards', { stackId: state.stackId, title: 'Checkbox archive card' })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('the menu offers one stable "Archived" checkbox that both shows and sets the state', async ({ page }) => {
		await api.patch(`/cards/${state.cardId}`, { archived: false })
		await ncLogin(page)

		// ── Unchecked state: a live card ────────────────────────────────────────
		await openCardMenu(page)
		await expect(archivedBox(page)).not.toBeChecked()
		// The label is stable prose, never the action-flavoured opposite.
		const item = page.locator('[data-test="card-archived-toggle"]')
		await expect(item).toContainText('Archived')

		// ── Toggle on: archives on the server ───────────────────────────────────
		await archivedLabel(page).click()
		await expect.poll(
			async () => (await api.get(`/cards/${state.cardId}`)).archived,
			{ timeout: 15_000 },
		).toBe(true)

		// ── Checked state: reopening the archived card shows the box ticked ─────
		await openCardMenu(page)
		await expect(archivedBox(page)).toBeChecked()
		await expect(item).toContainText('Archived')

		// ── Toggle off: unarchives, and the box follows without a reload ────────
		await archivedLabel(page).click()
		await expect(archivedBox(page)).not.toBeChecked({ timeout: 10_000 })
		await expect.poll(
			async () => (await api.get(`/cards/${state.cardId}`)).archived,
			{ timeout: 15_000 },
		).toBe(false)

		// The card is genuinely back on the board (same result as the old button).
		const board = await api.get(`/boards/${state.boardId}`)
		expect(board.cards.filter((c) => !c.archived).map((c) => c.title)).toContain('Checkbox archive card')
	})

	test('a change made elsewhere ticks the box without reopening the card', async ({ page }) => {
		await api.patch(`/cards/${state.cardId}`, { archived: false })
		await ncLogin(page)
		await openCardMenu(page)
		await expect(archivedBox(page)).not.toBeChecked()

		// Archive the card from outside this card view entirely. The board's delta
		// poll invalidates the open card's detail query, and the checkbox is bound
		// straight to cardData.archived — so it must tick with no reload and no
		// reopening of the card or the menu.
		await api.patch(`/cards/${state.cardId}`, { archived: true })
		await expect(archivedBox(page)).toBeChecked({ timeout: 30_000 })

		await api.patch(`/cards/${state.cardId}`, { archived: false }).catch(() => {})
	})
})
