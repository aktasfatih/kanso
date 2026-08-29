// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// The column actions menu (rename / status / WIP limit / colour / "Delete
// column") and card drag are write affordances: renaming, deleting or reordering
// a column and moving a card all need the board's EDIT bit server-side, so a
// read-only member who sees them gets nothing but an error. These specs pin all
// of them to editors, on the kanban board and in list view.

/** Pick a radio in the board's display menu ("Board"/"List"). */
async function setDisplay(page, name) {
	await page.locator('.board-view__display-menu button').first().click()
	await page.getByRole('menuitemradio', { name, exact: true }).click()
	await page.keyboard.press('Escape')
}

test.describe('Column actions and card drag are editors only (#9897)', () => {
	// A second identity logs in explicitly, so this describe must NOT inherit the
	// shared admin storageState (it would silently stay admin and false-pass).
	test.use({ storageState: { cookies: [], origins: [] }, viewport: { width: 1600, height: 900 } })

	const state = { boardId: 0, stackId: 0, otherStackId: 0, cardId: 0, boardUrl: '' }

	test.beforeAll(async ({ peer }) => {
		const board = await api.post('/boards', { title: 'Column ACL ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Inbox' })
		state.stackId = stack.id
		// A second column so the refused card move has somewhere to aim at.
		const other = await api.post('/stacks', { boardId: board.id, title: 'Done' })
		state.otherStackId = other.id
		const card = await api.post('/cards', { stackId: stack.id, title: 'Read-only card' })
		state.cardId = card.id
		// READ only (1) — no EDIT bit, so canEditBoard is false for the peer.
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 1,
		})
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('the server refuses a column delete and a card move from a viewer', async ({ peer }) => {
		// The UI gates below only hide dead affordances — this pins that the real
		// gate is server-side, so hiding them never becomes the only check.
		const deleted = await peer.api.raw('DELETE', `/stacks/${state.stackId}`)
		expect(deleted.status).toBe(403)
		const moved = await peer.api.raw('POST', `/cards/${state.cardId}/move`, {
			targetStackId: state.otherStackId,
		})
		expect(moved.status).toBe(403)
	})

	test('a viewer gets no column actions menu and cannot start a drag', async ({ browser, peer }) => {
		const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 } })
		try {
			const page = await ctx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(state.boardUrl)
			await page.waitForSelector('.board-view__header', { timeout: 15_000 })

			// Kanban — the columns and the card render for them…
			await expect(page.locator('.stack-column')).toHaveCount(2, { timeout: 10_000 })
			await expect(page.locator('.card-tile', { hasText: 'Read-only card' }))
				.toBeVisible({ timeout: 8_000 })
			// …but the whole actions menu is gone (all six callbacks are null), so
			// there is no "Delete column" entry to reach and no rename affordance.
			await expect(page.locator('.stack-column__actions')).toHaveCount(0)
			await expect(page.getByRole('button', { name: 'Column actions' })).toHaveCount(0)
			await expect(page.locator('.stack-column__title--editable')).toHaveCount(0)
			// Neither the tile nor the column header is a drag source: pragmatic
			// drag-and-drop marks a registered draggable with draggable="true".
			await expect(page.locator('.card-tile[draggable="true"]')).toHaveCount(0)
			await expect(page.locator('.stack-column__header[draggable="true"]')).toHaveCount(0)

			// List view — the row renders, but it is not a drag source either.
			await setDisplay(page, 'List')
			await page.waitForSelector('.board-list-group', { timeout: 10_000 })
			await expect(page.locator('.board-list-row', { hasText: 'Read-only card' }))
				.toBeVisible({ timeout: 8_000 })
			await expect(page.locator('.board-list-row-wrap[draggable="true"]')).toHaveCount(0)
			await expect(page.locator('.board-list-row--draggable')).toHaveCount(0)
		} finally {
			await ctx.close()
		}
	})
})

test.describe('Column actions and card drag stay available to editors (#9897)', () => {
	test.use({ viewport: { width: 1600, height: 900 } })

	const state = { boardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Column Editor ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Inbox' })
		await api.post('/cards', { stackId: stack.id, title: 'Editable card' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('an editor keeps the column menu, "Delete column" and both drag handles', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Kanban — the menu is there and still offers the destructive entry.
		await expect(page.locator('.stack-column__actions')).toHaveCount(1, { timeout: 10_000 })
		await page.locator('.stack-column__actions button').first().click()
		await expect(page.getByRole('button', { name: 'Delete column' })).toBeVisible({ timeout: 8_000 })
		await page.keyboard.press('Escape')

		// Tile and column header are both real drag sources.
		await expect(page.locator('.card-tile[draggable="true"]')).toHaveCount(1)
		await expect(page.locator('.stack-column__header[draggable="true"]')).toHaveCount(1)

		// List view keeps its row drag.
		await setDisplay(page, 'List')
		await page.waitForSelector('.board-list-group', { timeout: 10_000 })
		await expect(page.locator('.board-list-row', { hasText: 'Editable card' }))
			.toBeVisible({ timeout: 8_000 })
		await expect(page.locator('.board-list-row-wrap[draggable="true"]')).toHaveCount(1)
	})
})
