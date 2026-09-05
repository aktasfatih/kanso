// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// #10171 — the three inline editors on the card detail (title, checklist-item
// title, comment body) used to close in a `finally`, so a REJECTED save closed
// the editor and destroyed what had been typed. Reopening re-seeds the draft
// from the server copy, so the text was unrecoverable: the only way back was to
// retype it.
//
// TanStack Query's default networkMode is 'online', so an offline save PAUSES
// rather than rejects — the real trigger is a server error (a 403 after a
// mid-edit permission change, a 409, a 5xx). These tests reproduce that by
// route-intercepting the mutation's PATCH and returning 403.
//
// Each test asserts three things: the editor is still open, the typed value is
// intact, and the failure is visible. Restoring any of the three `finally`
// blocks makes the corresponding test fail.
const FORCED = 'forced permission failure'

/** Fulfil a route with a 403 carrying the error body the UI surfaces. */
function deny(route) {
	return route.fulfill({
		status: 403,
		contentType: 'application/json',
		body: JSON.stringify({ error: FORCED }),
	})
}

test.describe('A failed inline save keeps the editor open with the typed text (#10171)', () => {
	const state = { boardId: 0, stackId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Edit Failure E2E ' + Date.now() })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To Do' })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	/** A card of its own per test, so one test's forced failure can't affect another. */
	async function freshCard(title) {
		const card = await api.post('/cards', { stackId: state.stackId, title })
		return {
			id: card.id,
			url: `${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${card.id}`,
		}
	}

	test('a rejected rename keeps the title editor open with the typed title', async ({ page }) => {
		const card = await freshCard('Rename me')
		await ncLogin(page)
		await page.goto(card.url)
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		await page.route(`**/apps/kanso/api/cards/${card.id}`, (route) =>
			route.request().method() === 'PATCH' ? deny(route) : route.continue())

		await page.locator('.card-modal__title').click()
		const titleInput = page.locator('.card-modal__title-input')
		await expect(titleInput).toBeVisible({ timeout: 5_000 })
		await titleInput.fill('a title worth keeping')
		await titleInput.press('Enter')

		// Editor still open, draft intact, failure explained next to it.
		await expect(page.locator('[data-title-error]')).toHaveText(FORCED, { timeout: 10_000 })
		await expect(titleInput).toBeVisible()
		await expect(titleInput).toHaveValue('a title worth keeping')

		// The server never took the rename, and the heading is still the old one
		// underneath — nothing was silently half-applied.
		await page.unroute(`**/apps/kanso/api/cards/${card.id}`)
		expect((await api.get(`/cards/${card.id}`)).title).toBe('Rename me')
	})

	test('a successful rename still closes the title editor', async ({ page }) => {
		const card = await freshCard('Rename me too')
		await ncLogin(page)
		await page.goto(card.url)
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		await page.locator('.card-modal__title').click()
		const titleInput = page.locator('.card-modal__title-input')
		await expect(titleInput).toBeVisible({ timeout: 5_000 })
		await titleInput.fill('renamed cleanly')
		await titleInput.press('Enter')

		await expect(titleInput).toBeHidden({ timeout: 10_000 })
		await expect(page.locator('.card-modal__title')).toHaveText('renamed cleanly')
		expect((await api.get(`/cards/${card.id}`)).title).toBe('renamed cleanly')
	})

	test('a rejected checklist-item rename keeps the step editor open with the typed title', async ({ page }) => {
		const card = await freshCard('Card with a step')
		const item = await api.post(`/cards/${card.id}/checklist`, { title: 'Original step' })

		await ncLogin(page)
		await page.goto(card.url)
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// The card has exactly one step. Locate it positionally, NOT by text: in
		// edit mode the title span is replaced by an <input>, whose value is not
		// text content, so a hasText filter would stop matching mid-test.
		const row = page.locator('.card-modal__checklist-item').first()
		await expect(row).toContainText('Original step', { timeout: 10_000 })

		await page.route(`**/apps/kanso/api/checklist/${item.id}`, (route) =>
			route.request().method() === 'PATCH' ? deny(route) : route.continue())

		await row.locator('.card-modal__checklist-item-title').click()
		const itemInput = row.locator('.card-modal__checklist-item-input')
		await expect(itemInput).toBeVisible({ timeout: 5_000 })
		await itemInput.fill('step text worth keeping')
		await itemInput.press('Enter')

		await expect(page.locator('.card-modal__checklist .card-modal__save-error'))
			.toHaveText(FORCED, { timeout: 10_000 })
		await expect(itemInput).toBeVisible()
		await expect(itemInput).toHaveValue('step text worth keeping')
	})

	// The highest-value case: a comment body is unbounded prose, so the old
	// `finally` destroyed arbitrarily much typing with no way to get it back.
	test('a rejected comment edit keeps the editor open with the typed body', async ({ page }) => {
		const card = await freshCard('Card with a comment')
		const comment = await api.post(`/cards/${card.id}/comments`, { body: 'Original body' })

		await ncLogin(page)
		await page.goto(card.url)
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		const thread = page.locator('.card-modal__comment-group > .card-modal__comment').first()
		await expect(thread).toContainText('Original body', { timeout: 10_000 })

		await page.route(`**/apps/kanso/api/comments/${comment.id}`, (route) =>
			route.request().method() === 'PATCH' ? deny(route) : route.continue())

		// The pencil is the first non-danger icon button on the comment.
		await thread.locator('.card-modal__comment-icon-btn:not(.card-modal__comment-icon-btn--danger)').first().click()
		const editTa = thread.locator('.card-modal__comment-edit-textarea')
		await expect(editTa).toBeVisible({ timeout: 5_000 })

		const longBody = 'A long reply that took real effort to write. '.repeat(8).trim()
		await editTa.fill(longBody)
		await editTa.press('Control+Enter')

		await expect(page.locator('.card-modal__discussion .card-modal__save-error'))
			.toHaveText(FORCED, { timeout: 10_000 })
		await expect(editTa).toBeVisible()
		await expect(editTa).toHaveValue(longBody)

		// The optimistic patch rolled back — the stored comment is untouched.
		await page.unroute(`**/apps/kanso/api/comments/${comment.id}`)
		const stored = await api.get(`/cards/${card.id}/comments`)
		expect(stored.find((c) => c.id === comment.id).body).toBe('Original body')
	})
})
