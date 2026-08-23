// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Dead-link UX (#3662): a deep-link/inbox/notification pointing at a card or
// board that no longer exists must land on a friendly, actionable message - not
// a raw "failed to load" error. This asserts:
//   1. Opening a card route for a non-existent card id under a live board shows
//      "This card no longer exists" + a "Go to boards" way out (not a raw error).
//   2. Opening a board route for a deleted board shows "This board no longer
//      exists" + a "Go to boards" link (not the generic error box).

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Dead card/board links (#3662)', () => {
	const state = { liveBoardId: 0, deadBoardId: 0 }
	const TITLE = 'Dead Links E2E Board'

	test.beforeAll(async () => {
		// Clean any leftovers from a prior run.
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === TITLE) await api.delete(`/boards/${b.id}`).catch(() => {})
		}

		// A live board to host a bogus card deep-link.
		const live = await api.post('/boards', { title: TITLE })
		state.liveBoardId = live.id
		await api.post('/stacks', { boardId: live.id, title: 'To Do' })

		// A second board we delete, to exercise the gone-board path.
		const dead = await api.post('/boards', { title: TITLE })
		state.deadBoardId = dead.id
		await api.delete(`/boards/${dead.id}`).catch(() => {})
	})

	test.afterAll(async () => {
		if (state.liveBoardId) await api.delete(`/boards/${state.liveBoardId}`).catch(() => {})
	})

	test.beforeEach(async ({ page }) => {
		await ncLogin(page)
	})

	test('a card that no longer exists shows a friendly message + a way out', async ({ page }) => {
		// Deep-link to a non-existent card id under the LIVE board (the card fetch
		// 404s while the board loads fine).
		const bogusCardId = 2_000_000_000
		await page.goto(
			`${BASE}/index.php/apps/kanso#/board/${state.liveBoardId}/card/${bogusCardId}`,
		)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		const err = page.locator('.card-modal__error')
		await expect(err).toBeVisible({ timeout: 15_000 })
		await expect(err).toContainText('no longer exists')
		// Not the old generic copy.
		await expect(err).not.toContainText('Failed to load card details')
		// A dead card is a dead end - no Retry, but a way out to the boards list.
		await expect(err.getByRole('button', { name: 'Go to boards' })).toBeVisible()
		await expect(err.getByRole('button', { name: 'Retry' })).toHaveCount(0)

		// The way out actually leaves the (broken) card.
		await err.getByRole('button', { name: 'Go to boards' }).click()
		await expect(page).toHaveURL(/#\/$/, { timeout: 10_000 })
	})

	test('a board that no longer exists explains itself + links to the boards list', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.deadBoardId}`)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		const err = page.locator('.board-view__error')
		await expect(err).toBeVisible({ timeout: 15_000 })
		await expect(err).toContainText('no longer exists')
		await expect(err).not.toContainText('Failed to load board.')
		await expect(err.getByRole('button', { name: 'Go to boards' })).toBeVisible()
	})
})
