// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// Card titles are capped at 100 characters server-side (CardService::MAX_TITLE_LENGTH,
// backed by a VARCHAR(100) column). Every card title input now carries maxlength="100"
// so the cap is felt while typing instead of as a 400 after Enter — matching the column
// and board title inputs, which already had it.
//
// The four inputs covered here are the complete set of card-title entry points:
// the kanban composer, the list-view composer, the card-detail rename, and the
// sub-card composer.
// Distinct filler per input so one test's persisted 100-char title can never
// satisfy another's assertion.
const tooLong = (ch) => ch.repeat(150)
const capped = (ch) => ch.repeat(100)

test.describe('Card title length cap', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Title Cap E2E ' + Date.now() })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To Do' })).id
		state.cardId = (await api.post('/cards', { stackId: state.stackId, title: 'Rename me' })).id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	// Persisted titles (source of truth) — proves the cap survived the round trip
	// rather than only looking right in the DOM.
	async function titles() {
		const board = await api.get(`/boards/${state.boardId}`)
		return board.cards.filter((c) => !c.archived).map((c) => c.title)
	}

	test('kanban composer truncates at 100 and creates the 100-char card', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })

		const composer = page.locator('.stack-column').first().locator('.card-composer__input')
		await expect(composer).toBeVisible({ timeout: 10_000 })
		await expect(composer).toHaveAttribute('maxlength', '100')

		// fill() sets the value through the real input pipeline, so maxlength applies.
		await composer.fill(tooLong('a'))
		await expect(composer).toHaveValue(capped('a'))

		await composer.press('Enter')

		// Created with exactly the 100-character prefix — no 400, no truncation surprise.
		await expect.poll(() => titles(), { timeout: 10_000 }).toContain(capped('a'))
	})

	test('list-view composer truncates at 100', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Switch to List view — its per-group quick-add row carries its own composer.
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByRole('menuitemradio', { name: 'List', exact: true }).click()
		await page.keyboard.press('Escape')

		// Scope to the list table — the kanban composer stays in the DOM (hidden)
		// behind the view switch, so an unscoped locator would match that one.
		const composer = page.locator('.board-list-table .card-composer__input').first()
		await expect(composer).toBeVisible({ timeout: 10_000 })
		await expect(composer).toHaveAttribute('maxlength', '100')

		await composer.fill(tooLong('b'))
		await expect(composer).toHaveValue(capped('b'))
	})

	test('card-detail rename and sub-card composer truncate at 100', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.cardId}`)
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// Sub-card composer.
		const addChild = page.getByPlaceholder('Add a sub-card…')
		await expect(addChild).toBeVisible({ timeout: 10_000 })
		await expect(addChild).toHaveAttribute('maxlength', '100')
		await addChild.fill(tooLong('c'))
		await expect(addChild).toHaveValue(capped('c'))
		// Leave no half-typed draft behind for the rename step.
		await addChild.fill('')

		// Title rename: click the heading to swap it for the input.
		await page.locator('.card-modal__title').click()
		const titleInput = page.locator('.card-modal__title-input')
		await expect(titleInput).toBeVisible({ timeout: 5_000 })
		await expect(titleInput).toHaveAttribute('maxlength', '100')
		await titleInput.fill(tooLong('d'))
		await expect(titleInput).toHaveValue(capped('d'))

		await titleInput.press('Enter')
		await expect.poll(() => titles(), { timeout: 10_000 }).toContain(capped('d'))
	})
})
